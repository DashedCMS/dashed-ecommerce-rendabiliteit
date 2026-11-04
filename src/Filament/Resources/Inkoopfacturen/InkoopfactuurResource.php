<?php

namespace Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Inkoopfacturen;

use UnitEnum;
use BackedEnum;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Actions\DeleteAction;
use Illuminate\Auth\Access\Response;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Unique;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Tables\Filters\SelectFilter;
use Dashed\DashedEcommerceCore\Models\Product;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Dashed\DashedEcommerceRendabiliteit\Models\Inkoopfactuur;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Dashed\DashedEcommerceRendabiliteit\Models\InkoopfactuurRegel;
use Dashed\DashedEcommerceRendabiliteit\Services\Inkoop\InkoopfactuurLezer;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Inkoopfacturen\Pages\EditInkoopfactuur;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Inkoopfacturen\Pages\ListInkoopfacturen;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Inkoopfacturen\Pages\CreateInkoopfactuur;

/**
 * Inkoopfacturen van leveranciers. Claude leest de regels uit, elke regel wordt
 * aan een product gekoppeld (EAN, SKU, onthouden artikelcode of met de hand) en
 * "Toepassen" zet purchase_price, de standaard kostprijs.
 */
class InkoopfactuurResource extends Resource
{
    public const UITLEG = 'Facturen van leveranciers. Na "Toepassen" krijgt elk gekoppeld product de prijs per stuk excl. btw van de factuur als inkoopprijs (de kostprijs in het rendabiliteitsrapport), één keer per regel.';

    public const DISK = 'local';

    public const MAP = InkoopfactuurLezer::MAP;

    public const BESTANDSTYPEN = [
        'application/pdf',
        'text/csv',
        'text/plain',
        'application/csv',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public const KOPPELINGEN = [
        InkoopfactuurRegel::KOPPELING_EAN => 'op EAN',
        InkoopfactuurRegel::KOPPELING_SKU => 'op SKU',
        InkoopfactuurRegel::KOPPELING_ONTHOUDEN => 'onthouden artikelcode',
        InkoopfactuurRegel::KOPPELING_HANDMATIG => 'met de hand',
    ];

    protected static ?string $model = Inkoopfactuur::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|UnitEnum|null $navigationGroup = 'Rendabiliteit';

    protected static ?string $navigationLabel = 'Inkoopfacturen';

    protected static ?int $navigationSort = 7;

    protected static ?string $slug = 'rendabiliteit/inkoopfacturen';

    protected static ?string $modelLabel = 'inkoopfactuur';

    protected static ?string $pluralModelLabel = 'inkoopfacturen';

    /** Alle acties alleen met view_statistics. */
    public static function getAuthorizationResponse(string $action, ?Model $record = null): Response
    {
        return auth()->check() && auth()->user()->can('view_statistics') ? Response::allow() : Response::deny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Bestand')
                ->description('Upload de factuur (PDF, CSV of XLSX) en laat Claude de regels invullen; controleer ze daarna.')
                ->columnSpanFull()
                ->schema([
                    FileUpload::make('bestand')
                        ->label('Factuur')
                        ->disk(self::DISK)
                        ->directory(self::MAP)
                        ->visibility('private')
                        ->acceptedFileTypes(self::BESTANDSTYPEN)
                        ->maxSize(10240)
                        ->preserveFilenames(false)
                        ->downloadable(false)
                        ->openable(false),
                    Actions::make([
                        Action::make('uitlezen')
                            ->label('Uitlezen met Claude')
                            ->icon('heroicon-o-sparkles')
                            // Een toegepaste factuur opnieuw uitlezen zou de regels (en hun
                            // toegepast_at) vervangen en een oude prijs opnieuw laten toepassen.
                            ->visible(fn (Get $get, ?Inkoopfactuur $record) => filled($get('bestand')) && ! $record?->isToegepast())
                            ->action(fn (Get $get, Set $set, ?Inkoopfactuur $record) => self::leesUit($get, $set, $record)),
                    ])->key('uitleesActies'),
                    Hidden::make('twijfel')->dehydrated(false),
                    Callout::make('Controleer deze velden')
                        ->description(fn (Get $get) => implode(' ', (array) $get('twijfel')))
                        ->warning()
                        ->visible(fn (Get $get) => filled($get('twijfel'))),
                ]),
            Grid::make(3)->columnSpanFull()->schema([
                TextInput::make('leverancier')
                    ->label('Leverancier')
                    ->required()
                    ->maxLength(255)
                    ->trim()
                    ->live(onBlur: true)
                    ->datalist(fn () => Inkoopfactuur::query()->distinct()->orderBy('leverancier')->pluck('leverancier')->all()),
                TextInput::make('factuurnummer')
                    ->label('Factuurnummer')
                    ->required()
                    ->maxLength(255)
                    ->trim()
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('leverancier', trim((string) $get('leverancier'))),
                    )
                    ->validationMessages(['unique' => 'Deze factuur van deze leverancier staat er al.']),
                DatePicker::make('factuurdatum')->label('Factuurdatum'),
                Toggle::make('prijzen_incl_btw')
                    ->label('Prijzen op de factuur zijn incl. btw')
                    ->helperText('Dan rekenen we terug met het btw-tarief van het product (anders 21%).'),
                Toggle::make('kosten_verdelen')
                    ->label('Kosten verdelen over de producten')
                    ->helperText('Verzendkosten en toeslagen naar regelwaarde bij de inkoopprijs optellen.')
                    ->default(true),
            ]),
            Section::make('Regels')
                ->columnSpanFull()
                ->schema([
                    Repeater::make('regels')
                        ->label('')
                        ->relationship()
                        ->addActionLabel('Regel toevoegen')
                        ->defaultItems(0)
                        ->columns(6)
                        ->itemLabel(fn (array $state): ?string => $state['omschrijving'] ?? $state['artikelcode'] ?? null)
                        ->schema(self::regelVelden()),
                ]),
            Textarea::make('notitie')->label('Notitie')->rows(2)->columnSpanFull(),
            Hidden::make('bron')->default(Inkoopfactuur::BRON_HANDMATIG),
        ]);
    }

    private static function regelVelden(): array
    {
        return [
            Select::make('soort')
                ->label('Soort')
                ->options([InkoopfactuurRegel::SOORT_PRODUCT => 'Product', InkoopfactuurRegel::SOORT_KOSTEN => 'Kosten (verzending, toeslag)'])
                ->default(InkoopfactuurRegel::SOORT_PRODUCT)
                ->required()
                ->live(),
            TextInput::make('artikelcode')->label('Artikelcode')->maxLength(255),
            TextInput::make('ean')->label('EAN')->maxLength(255),
            TextInput::make('omschrijving')->label('Omschrijving')->maxLength(255)->columnSpan(3),
            TextInput::make('aantal')->label('Aantal')->numeric()->minValue(0.001)->default(1)->required(),
            TextInput::make('stuksprijs')->label('Prijs per stuk')->numeric()->minValue(0)->prefix('€')->required(),
            TextInput::make('korting')->label('Korting per stuk')->numeric()->minValue(0)->prefix('€')->default(0),
            Select::make('product_id')
                ->label('Product')
                ->searchable()
                ->getSearchResultsUsing(fn (string $search) => self::zoekProducten($search))
                ->getOptionLabelUsing(fn ($value) => self::productLabel(Product::query()->find($value)))
                ->visible(fn (Get $get) => $get('soort') !== InkoopfactuurRegel::SOORT_KOSTEN)
                ->dehydrateStateUsing(fn ($state, Get $get) => $get('soort') === InkoopfactuurRegel::SOORT_KOSTEN ? null : $state)
                ->afterStateUpdated(fn (Set $set, $state) => $set('koppeling', $state ? InkoopfactuurRegel::KOPPELING_HANDMATIG : null))
                ->live()
                ->hint(fn (Get $get) => self::regelStatus($get('koppeling'), $get('toegepast_at')))
                ->columnSpan(3),
            Hidden::make('koppeling'),
            Hidden::make('toegepast_at')->dehydrated(false),
        ];
    }

    /** @return array<int, string> */
    public static function zoekProducten(string $search): array
    {
        return Product::query()
            ->search($search)
            ->limit(25)
            ->get()
            ->mapWithKeys(fn (Product $product) => [$product->id => self::productLabel($product)])
            ->all();
    }

    private static function productLabel(?Product $product): ?string
    {
        if (! $product) {
            return null;
        }

        return trim($product->name.($product->sku ? " ({$product->sku})" : ''));
    }

    private static function regelStatus(?string $koppeling, mixed $toegepast): ?string
    {
        $delen = array_filter([
            $koppeling ? 'Gekoppeld '.(self::KOPPELINGEN[$koppeling] ?? $koppeling) : null,
            filled($toegepast) ? 'toegepast' : null,
        ]);

        return $delen === [] ? null : Str::ucfirst(implode(', ', $delen));
    }

    /** Stuurt het geüploade bestand naar Claude en zet de gevonden velden en regels in het formulier. */
    public static function leesUit(Get $get, Set $set, ?Inkoopfactuur $record = null): void
    {
        if ($record?->isToegepast()) {
            Notification::make()->danger()->title('Deze factuur is al toegepast')->body('Maak een nieuwe factuur aan om opnieuw uit te lezen.')->send();

            return;
        }

        $bestand = $get('bestand');
        $bestand = is_array($bestand) ? (array_values($bestand)[0] ?? null) : $bestand;
        $lezer = app(InkoopfactuurLezer::class);

        $lezing = match (true) {
            $bestand instanceof TemporaryUploadedFile => $lezer->leesUpload($bestand),
            is_string($bestand) && $bestand !== '' => $lezer->lees($bestand),
            default => ['gelukt' => false, 'fout' => 'Upload eerst een bestand.'],
        };

        if (! $lezing['gelukt']) {
            Notification::make()->danger()->title('Uitlezen mislukt')->body($lezing['fout'])->send();

            return;
        }

        foreach (['leverancier', 'factuurnummer', 'factuurdatum'] as $veld) {
            if ($lezing[$veld] !== null) {
                $set($veld, $lezing[$veld]);
            }
        }
        $set('prijzen_incl_btw', $lezing['prijzen_incl_btw']);
        $set('regels', collect($lezing['regels'])
            ->mapWithKeys(fn (array $regel) => [(string) Str::uuid() => $regel + ['product_id' => null, 'koppeling' => null]])
            ->all());
        $set('bron', Inkoopfactuur::BRON_AI);

        $twijfel = $lezing['twijfel'];
        if ($lezing['leverancier'] && $lezing['factuurnummer'] && Inkoopfactuur::bestaatAl($lezing['leverancier'], $lezing['factuurnummer'])) {
            array_unshift($twijfel, 'Deze factuur van deze leverancier staat er al.');
        }
        $set('twijfel', $twijfel);

        $melding = Notification::make()->title('Factuur uitgelezen: '.count($lezing['regels']).' regels');
        ($twijfel === [] ? $melding->success()->body('Controleer de regels en sla op; daarna koppelen we ze aan producten.') : $melding->warning()->body('Een deel moet je zelf controleren.'))->send();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description(self::UITLEG)
            ->defaultSort(fn ($query) => $query->orderByDesc('factuurdatum')->orderByDesc('id'))
            ->columns([
                TextColumn::make('leverancier')->label('Leverancier')->searchable()->sortable(),
                TextColumn::make('factuurnummer')->label('Factuurnummer')->searchable(),
                TextColumn::make('factuurdatum')->label('Factuurdatum')->date('d-m-Y')->sortable()->placeholder('—'),
                TextColumn::make('regels_count')->label('Regels')->counts('regels'),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (?string $state) => $state === Inkoopfactuur::STATUS_VERWERKT ? 'Toegepast' : 'Concept')
                    ->color(fn (?string $state) => $state === Inkoopfactuur::STATUS_VERWERKT ? 'success' : 'gray'),
                TextColumn::make('bron')->label('Bron')->badge()
                    ->formatStateUsing(fn (?string $state) => $state === Inkoopfactuur::BRON_AI ? 'Claude' : 'Handmatig')
                    ->color(fn (?string $state) => $state === Inkoopfactuur::BRON_AI ? 'info' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')
                    ->options([Inkoopfactuur::STATUS_CONCEPT => 'Concept', Inkoopfactuur::STATUS_VERWERKT => 'Toegepast']),
            ])
            ->recordActions([
                self::downloadActie(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function downloadActie(): Action
    {
        return Action::make('downloaden')
            ->label('Bestand')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (?Inkoopfactuur $record) => filled($record?->bestand) && Storage::disk(self::DISK)->exists($record->bestand))
            ->action(function (Inkoopfactuur $record): ?StreamedResponse {
                // Het pad komt uit de database; alleen een vertrouwd pad wordt gedownload.
                if (! InkoopfactuurLezer::isVertrouwdPad($record->bestand)) {
                    Notification::make()->danger()->title('Bestand niet gevonden')->send();

                    return null;
                }

                return Storage::disk(self::DISK)->download(
                    $record->bestand,
                    str($record->leverancier.'-'.$record->factuurnummer)->slug()->append('.'.pathinfo($record->bestand, PATHINFO_EXTENSION))->toString(),
                );
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInkoopfacturen::route('/'),
            'create' => CreateInkoopfactuur::route('/create'),
            'edit' => EditInkoopfactuur::route('/{record}/edit'),
        ];
    }
}
