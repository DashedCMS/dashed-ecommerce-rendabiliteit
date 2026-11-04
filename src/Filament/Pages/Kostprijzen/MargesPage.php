<?php

namespace Dashed\DashedEcommerceRendabiliteit\Filament\Pages\Kostprijzen;

use UnitEnum;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables\Table;
use Livewire\Attributes\Url;
use Livewire\Attributes\Computed;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Dashed\DashedEcommerceCore\Models\Product;
use Filament\Tables\Concerns\InteractsWithTable;
use Dashed\DashedEcommerceRendabiliteit\Instellingen\MargeInstellingen;
use Dashed\DashedEcommerceRendabiliteit\Services\Kostprijzen\Prijsadvies;
use Dashed\DashedEcommerceRendabiliteit\Services\Kostprijzen\MargeSignalen;
use Dashed\DashedEcommerceRendabiliteit\Filament\Kostprijzen\AdviesprijsActie;

/**
 * Marge per product op basis van purchase_price (leeg of 0 = onbekend) en
 * de huidige prijs; rood onder de doelmarge. De adviesprijs komt uit de
 * kostprijsbron (rendabiliteit()->kostprijsBron(), zoals de modal), niet rechtstreeks uit purchase_price.
 */
class MargesPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static string|UnitEnum|null $navigationGroup = 'Kostprijzen';

    protected static ?string $navigationLabel = 'Marges';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'kostprijzen/marges';

    protected static ?string $title = 'Marges en adviesprijzen';

    protected string $view = 'dashed-ecommerce-rendabiliteit::filament.pages.kostprijzen.marges';

    /** Filters in de URL (?filters[product_group_id][value]=…), zodat o.a. het rendabiliteitsrapport naar één groep kan linken. */
    #[Url(as: 'filters')]
    public ?array $tableFilters = null;

    /** Filters die de signaalblokken onder de tabel mogen aanzetten, met hun sortering. */
    private const SIGNAALFILTERS = [
        'verlies' => 'marge_euro:asc',
        'onder_doel' => 'marge_procent:asc',
        'boven_advies' => 'marge_procent:desc',
        'onbekend' => null,
    ];

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->can('view_statistics');
    }

    public function table(Table $table): Table
    {
        $doelmarge = MargeInstellingen::doelmarge();
        $uitgesloten = rendabiliteit()->uitgeslotenGroepIds();
        // Zelfde formule als Prijsadvies::margeProcent, in SQL zodat filteren en sorteren kan.
        // NULLIF zorgt dat een kostprijs van 0 (net als leeg) als onbekend (NULL) sorteert.
        $margeSql = '(price / (1 + COALESCE(vat_rate, 21) / 100) - NULLIF(purchase_price, 0)) / (price / (1 + COALESCE(vat_rate, 21) / 100)) * 100';
        // Teller van $margeSql: hetzelfde als Prijsadvies::margeEuro, in SQL voor sorteren.
        $margeEuroSql = 'price / (1 + COALESCE(vat_rate, 21) / 100) - NULLIF(purchase_price, 0)';
        // Adviesprijs met dezelfde kostprijsbron als de doorvoer-modal.
        $bron = rendabiliteit()->kostprijsBron();

        return $table
            ->query(Product::query()
                // Tabelnamen expliciet: de Groep-sortering left-joint dashed__product_groups,
                // die (net als dashed__products) een eigen kolom "public" heeft.
                ->where('dashed__products.public', 1)
                ->whereNotNull('dashed__products.product_group_id')
                ->when($uitgesloten, fn ($q) => $q->whereNotIn('dashed__products.product_group_id', $uitgesloten))
                ->with('productGroup'))
            ->columns([
                TextColumn::make('productGroup.name')->label('Groep')
                    ->sortable(query: function ($query, string $direction) {
                        // Sorteert op de Nederlandse naam van de gekoppelde groep (translatable JSON).
                        // Expliciete select voorkomt dat de join dashed__products-kolommen (o.a. name, id) overschrijft.
                        $query->leftJoin('dashed__product_groups', 'dashed__product_groups.id', '=', 'dashed__products.product_group_id')
                            ->select('dashed__products.*')
                            ->orderByRaw("JSON_UNQUOTE(JSON_EXTRACT(dashed__product_groups.name, '$.nl')) {$direction}");
                    }),
                TextColumn::make('name')->label('Product')->searchable()
                    ->sortable(query: fn ($query, string $direction) => $query->orderByRaw("JSON_UNQUOTE(JSON_EXTRACT(dashed__products.name, '$.nl')) {$direction}")),
                TextColumn::make('purchase_price')->label('Kostprijs')
                    ->formatStateUsing(fn ($state) => (float) $state > 0 ? '€ '.number_format((float) $state, 2, ',', '.') : 'onbekend')
                    ->sortable(),
                TextColumn::make('advies')->label('Adviesprijs')
                    ->state(function (Product $r) use ($bron, $doelmarge) {
                        // Leeg bij een onbekende kostprijs, ook als er een handmatige inkoopprijs staat:
                        // de modal voert voor zo'n product ook niets door.
                        $kostprijs = $bron->voorProduct($r);

                        return $kostprijs ? Prijsadvies::adviesprijs($kostprijs->totaal, (float) ($r->vat_rate ?? 21), $doelmarge) : null;
                    })
                    ->money('EUR')
                    // Benadert de ,95-afgeronde adviesprijs met dezelfde monotone formule op purchase_price
                    // in plaats van de volledige kostprijsbron (die kan niet in SQL); onbekende kostprijs sorteert als NULL.
                    ->sortable(query: fn ($query, string $direction) => $query->orderByRaw('NULLIF(purchase_price, 0) * (1 + COALESCE(vat_rate, 21) / 100) / (1 - ? / 100) '.$direction, [$doelmarge])),
                TextColumn::make('price')->label('Prijs')->money('EUR')->sortable(),
                TextColumn::make('marge_euro')->label('Marge €')
                    ->state(fn (Product $r) => (float) $r->purchase_price > 0 ? Prijsadvies::margeEuro((float) $r->price, (float) ($r->vat_rate ?? 21), (float) $r->purchase_price) : null)
                    ->money('EUR')
                    ->sortable(query: fn ($query, string $direction) => $query->orderByRaw("({$margeEuroSql}) {$direction}")),
                TextColumn::make('marge_procent')->label('Marge %')
                    ->state(fn (Product $r) => (float) $r->purchase_price > 0 ? Prijsadvies::margeProcent((float) $r->price, (float) ($r->vat_rate ?? 21), (float) $r->purchase_price) : null)
                    ->formatStateUsing(fn ($state) => $state === null ? '' : number_format($state, 1, ',', '.').'%')
                    ->color(fn ($state) => $state !== null && $state < $doelmarge ? 'danger' : 'success')
                    ->sortable(query: fn ($query, string $direction) => $query->orderByRaw("({$margeSql}) {$direction}")),
            ])
            ->filters([
                SelectFilter::make('product_group_id')->label('Groep')->relationship('productGroup', 'name')->searchable(),
                // Op 1 decimaal afgerond, net als Prijsadvies::margeProcent en de rode kleur.
                Filter::make('onder_doel')->label('Onder doelmarge')
                    ->query(fn ($query) => $query->where('purchase_price', '>', 0)->where('price', '>', 0)->whereRaw("ROUND({$margeSql}, 1) < ?", [$doelmarge])),
                Filter::make('onbekend')->label('Kostprijs onbekend')
                    ->query(fn ($query) => $query->where(fn ($q) => $q->whereNull('purchase_price')->orWhere('purchase_price', '<=', 0))),
                Filter::make('verlies')->label('Verlies')
                    ->query(fn ($query) => app(MargeSignalen::class)->filterVerlies($query)),
                Filter::make('boven_advies')->label('Ver boven advies ('.number_format(MargeInstellingen::bovenAdviesProcent(), 0, ',', '.').'%)')
                    ->query(fn ($query) => app(MargeSignalen::class)->filterBovenAdvies($query)),
            ])
            ->recordActions([
                // Per rij; voor meerdere producten tegelijk: selecteren en de bulkactie hieronder.
                AdviesprijsActie::voorProducten(fn (Product $record) => collect([$record]), 'adviesprijsRij'),
            ])
            ->toolbarActions([
                AdviesprijsActie::voorBulk(),
            ]);
    }

    /**
     * Kengetallen en de vier signaalblokken onder de tabel; één keer per
     * request berekend.
     *
     * @return array<string, array>
     */
    #[Computed]
    public function signalen(): array
    {
        $signalen = app(MargeSignalen::class);

        return [
            'kengetallen' => $signalen->kengetallen(),
            'verlies' => $signalen->verlies(),
            'onder_doel' => $signalen->onderDoel(),
            'boven_advies' => $signalen->bovenAdvies(),
            'prijsverschillen' => $signalen->prijsverschillenPerMaat(),
            'doelmarge' => MargeInstellingen::doelmarge(),
            'boven_advies_procent' => MargeInstellingen::bovenAdviesProcent(),
        ];
    }

    /** Zet de tabel op precies één signaalfilter (plus bijbehorende sortering). */
    public function toonInTabel(string $filter): void
    {
        if (! array_key_exists($filter, self::SIGNAALFILTERS)) {
            return;
        }

        $this->zetTabelfilters([$filter => ['isActive' => true]], self::SIGNAALFILTERS[$filter]);
    }

    /** Toont alle producten van één groep in de tabel, op prijs. */
    public function toonGroep(int $groepId): void
    {
        $this->zetTabelfilters(['product_group_id' => ['value' => $groepId]], 'price:asc');
    }

    private function zetTabelfilters(array $filters, ?string $sortering): void
    {
        // fill() zet alle niet-genoemde filters terug op hun standaard.
        $this->getTableFiltersForm()->fill($filters);
        $this->resetTableSearch();
        $this->tableSort = $sortering;
        $this->updatedTableSort();

        if ($this->getTable()->hasDeferredFilters()) {
            $this->applyTableFilters();

            return;
        }

        $this->handleTableFilterUpdates();
    }
}
