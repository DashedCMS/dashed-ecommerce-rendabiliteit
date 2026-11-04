<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Kostprijzen;

use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;
use Dashed\DashedEcommerceCore\Models\Product;
use Dashed\DashedEcommerceCore\Models\ProductGroup;
use Dashed\DashedEcommerceRendabiliteit\Support\Maatlabel;
use Dashed\DashedEcommerceRendabiliteit\Instellingen\MargeInstellingen;

/**
 * Afwijkende prijzen voor de Marges-pagina: kengetallen, verlies, ver onder
 * de doelmarge, ver boven de adviesprijs en prijsverschillen binnen één
 * groep × maat. Dezelfde basis als de tabel (openbaar, met groep, zonder de
 * uitgesloten groepen). Kandidaten komen met SQL op purchase_price; de exacte
 * adviesprijs (kostprijsbron + Prijsadvies, zoals de kolom) alleen voor de
 * getoonde regels. Leest alleen.
 */
class MargeSignalen
{
    /** Hoeveel kandidaten per getoonde regel: de SQL-benadering en de kostprijsbron kunnen verschillen. */
    private const OVERVRAAG = 3;

    private const EXCL = 'dashed__products.price / (1 + COALESCE(dashed__products.vat_rate, 21) / 100)';

    private const KOST = 'NULLIF(dashed__products.purchase_price, 0)';

    /** @return array{onder_doel: int, verlies: int, onbekend: int, gemiddelde_marge: ?float} */
    public function kengetallen(): array
    {
        $doelmarge = MargeInstellingen::doelmarge();
        $marge = self::margeSql();

        $rij = $this->basis()->toBase()
            ->selectRaw('SUM(CASE WHEN dashed__products.purchase_price > 0 AND dashed__products.price > 0 AND ROUND('.$marge.', 1) < ? THEN 1 ELSE 0 END) AS onder_doel', [$doelmarge])
            ->selectRaw('SUM(CASE WHEN dashed__products.purchase_price > 0 AND '.self::EXCL.' < dashed__products.purchase_price THEN 1 ELSE 0 END) AS verlies')
            ->selectRaw('SUM(CASE WHEN dashed__products.purchase_price IS NULL OR dashed__products.purchase_price <= 0 THEN 1 ELSE 0 END) AS onbekend')
            ->selectRaw('AVG(CASE WHEN dashed__products.purchase_price > 0 AND dashed__products.price > 0 THEN '.$marge.' END) AS gemiddelde_marge')
            ->first();

        return [
            'onder_doel' => (int) $rij->onder_doel,
            'verlies' => (int) $rij->verlies,
            'onbekend' => (int) $rij->onbekend,
            'gemiddelde_marge' => $rij->gemiddelde_marge === null ? null : round((float) $rij->gemiddelde_marge, 1),
        ];
    }

    /** @return array<int, array{id: int, naam: string, groep: string, kostprijs: float, prijs: float, verlies: float}> */
    public function verlies(int $limiet = 10): array
    {
        return $this->filterVerlies($this->basis())
            ->with('productGroup')
            ->orderByRaw('(dashed__products.purchase_price - '.self::EXCL.') DESC')
            ->limit($limiet)
            ->get()
            ->map(fn (Product $product) => $this->regel($product) + [
                'verlies' => round((float) $product->purchase_price - Prijsadvies::exclBtw((float) $product->price, $this->btw($product)), 2),
            ])
            ->all();
    }

    /** @return array<int, array{id: int, naam: string, groep: string, kostprijs: float, prijs: float, adviesprijs: float, verschil: float, marge_procent: float}> */
    public function onderDoel(int $limiet = 10): array
    {
        $advies = self::adviesSql(MargeInstellingen::doelmarge());

        return $this->metAdvies(
            $this->basis()
                ->where('dashed__products.price', '>', 0)
                ->whereRaw("{$advies} > dashed__products.price")
                ->orderByRaw("({$advies} - dashed__products.price) DESC"),
            $limiet,
        )
            ->filter(fn (array $regel) => $regel['adviesprijs'] > $regel['prijs'])
            ->map(fn (array $regel) => $regel + [
                'verschil' => round($regel['adviesprijs'] - $regel['prijs'], 2),
                'marge_procent' => Prijsadvies::margeProcent($regel['prijs'], $regel['btw'], $regel['kostprijs']),
            ])
            ->sortByDesc('verschil')
            ->take($limiet)
            ->values()
            ->all();
    }

    /** @return array<int, array{id: int, naam: string, groep: string, kostprijs: float, prijs: float, adviesprijs: float, procent_boven: float}> */
    public function bovenAdvies(int $limiet = 10): array
    {
        $drempel = MargeInstellingen::bovenAdviesProcent();
        $advies = self::adviesSql(MargeInstellingen::doelmarge());

        return $this->metAdvies(
            $this->filterBovenAdvies($this->basis())->orderByRaw("dashed__products.price / {$advies} DESC"),
            $limiet,
        )
            ->map(fn (array $regel) => $regel + [
                'procent_boven' => round(($regel['prijs'] / $regel['adviesprijs'] - 1) * 100, 1),
            ])
            ->filter(fn (array $regel) => $regel['procent_boven'] > $drempel)
            ->sortByDesc('procent_boven')
            ->take($limiet)
            ->values()
            ->all();
    }

    /**
     * Groep × maat waar de openbare varianten verschillende prijzen hebben.
     * Een variant die volgens een extra signaal (rendabiliteit()->extraSignalen(),
     * soort `verwacht_duurder`) duurder mag zijn dan de gangbare prijs telt niet
     * als afwijking; alleen combinaties met minstens één onverwachte afwijking komen terug.
     *
     * @return array<int, array{groep_id: int, groep: string, maat: string, laagste: float, hoogste: float, aantal: int, gangbare_prijs: float, afwijkend: array<int, array{id: int, naam: string, prijs: float, verwacht: bool}>}>
     */
    public function prijsverschillenPerMaat(int $limiet = 15): array
    {
        $producten = $this->basis()->get(['dashed__products.id', 'dashed__products.name', 'dashed__products.product_group_id', 'dashed__products.price']);
        $sleutels = rendabiliteit()->maatsleutelsVoor($producten);
        $dual = self::verwachtDuurder($producten);

        $regels = $producten
            ->groupBy(fn (Product $product) => $product->product_group_id.'|'.($sleutels[(int) $product->id] ?? ''))
            ->map(fn (Collection $variant) => $this->vergelijkPrijzen($variant, $dual))
            ->filter()
            ->sortByDesc(fn (array $regel) => $regel['hoogste'] - $regel['laagste'])
            ->take($limiet);

        $groepen = ProductGroup::query()->whereIn('id', $regels->pluck('groep_id')->unique())->get()->keyBy('id');

        return $regels
            ->map(function (array $regel, string $sleutel) use ($groepen) {
                $maatsleutel = (string) str($sleutel)->after('|');

                return [
                    'groep_id' => $regel['groep_id'],
                    'groep' => (string) $groepen->get($regel['groep_id'])?->getTranslation('name', 'nl'),
                    'maat' => Maatlabel::voor((int) $regel['groep_id'], $maatsleutel, '—'),
                ] + $regel;
            })
            ->values()
            ->all();
    }

    /** Voor het tabelfilter "Verlies": prijs excl. btw onder een bekende kostprijs. */
    public function filterVerlies(Builder $query): Builder
    {
        return $query->where('dashed__products.purchase_price', '>', 0)
            ->whereRaw(self::EXCL.' < dashed__products.purchase_price');
    }

    /**
     * Voor het tabelfilter "Ver boven advies": prijs meer dan de drempel boven de
     * adviesprijs volgens purchase_price. Een product zonder bekende kostprijs
     * uit de kostprijsbron kan hier toch in staan (de kolom Adviesprijs is dan leeg).
     */
    public function filterBovenAdvies(Builder $query): Builder
    {
        $advies = self::adviesSql(MargeInstellingen::doelmarge());
        $factor = 1 + MargeInstellingen::bovenAdviesProcent() / 100;

        return $query->where('dashed__products.purchase_price', '>', 0)
            ->whereRaw("dashed__products.price > {$advies} * ?", [$factor]);
    }

    /** Zelfde formule als Prijsadvies::margeProcent, in SQL. */
    public static function margeSql(): string
    {
        return '('.self::EXCL.' - '.self::KOST.') / ('.self::EXCL.') * 100';
    }

    /**
     * Prijsadvies::adviesprijs in SQL op purchase_price, inclusief de afronding
     * op ,95; NULL bij een onbekende kostprijs.
     */
    public static function adviesSql(float $doelmarge): string
    {
        $deler = sprintf('%.6F', 1 - min(95.0, max(0.0, $doelmarge)) / 100);
        $bedrag = 'CEIL(ROUND('.self::KOST.' * (1 + COALESCE(dashed__products.vat_rate, 21) / 100) / '.$deler.' * 100, 4)) / 100';

        return "(CASE WHEN FLOOR({$bedrag}) + 0.95 >= {$bedrag} THEN FLOOR({$bedrag}) + 0.95 ELSE FLOOR({$bedrag}) + 1.95 END)";
    }

    /** Dezelfde basis als de tabel op de Marges-pagina. */
    private function basis(): Builder
    {
        $uitgesloten = rendabiliteit()->uitgeslotenGroepIds();

        return Product::query()
            ->where('dashed__products.public', 1)
            ->whereNotNull('dashed__products.product_group_id')
            ->when($uitgesloten, fn ($q) => $q->whereNotIn('dashed__products.product_group_id', $uitgesloten));
    }

    /**
     * Kandidaten met de exacte adviesprijs; producten zonder berekende
     * kostprijs vallen af (de kolom Adviesprijs is voor hen ook leeg).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function metAdvies(Builder $kandidaten, int $limiet): Collection
    {
        $doelmarge = MargeInstellingen::doelmarge();
        $bron = rendabiliteit()->kostprijsBron();

        return $kandidaten
            ->with('productGroup')
            ->limit($limiet * self::OVERVRAAG)
            ->get()
            ->map(function (Product $product) use ($doelmarge, $bron) {
                $kostprijs = $bron->voorProduct($product);

                return $kostprijs ? $this->regel($product) + [
                    'btw' => $this->btw($product),
                    'adviesprijs' => Prijsadvies::adviesprijs($kostprijs->totaal, $this->btw($product), $doelmarge),
                ] : null;
            })
            ->filter()
            ->values();
    }

    /** @return array{id: int, naam: string, groep: string, kostprijs: float, prijs: float} */
    private function regel(Product $product): array
    {
        return [
            'id' => (int) $product->id,
            'naam' => (string) $product->getTranslation('name', 'nl'),
            'groep' => (string) $product->productGroup?->getTranslation('name', 'nl'),
            'kostprijs' => (float) $product->purchase_price,
            'prijs' => (float) $product->price,
        ];
    }

    private function btw(Product $product): float
    {
        return (float) ($product->vat_rate ?? 21);
    }

    /**
     * @param  Collection<int, Product>  $variant
     * @param  array<int, true>  $dual
     */
    private function vergelijkPrijzen(Collection $variant, array $dual): ?array
    {
        $centen = fn (Product $product) => (int) round((float) $product->price * 100);

        if ($variant->count() < 2 || $variant->map($centen)->unique()->count() < 2) {
            return null;
        }

        // Gangbare prijs: meest voorkomend onder de gewone kleuren (bij gelijkspel de laagste).
        $gewoon = $variant->reject(fn (Product $product) => isset($dual[(int) $product->id]));
        $telling = ($gewoon->isEmpty() ? $variant : $gewoon)->map($centen)->countBy();
        $meest = $telling->max();
        $gangbaar = $telling->filter(fn ($aantal) => $aantal === $meest)->keys()->min();

        $afwijkend = $variant
            ->filter(fn (Product $product) => $centen($product) !== $gangbaar)
            ->map(fn (Product $product) => [
                'id' => (int) $product->id,
                'naam' => (string) $product->getTranslation('name', 'nl'),
                'prijs' => (float) $product->price,
                'verwacht' => isset($dual[(int) $product->id]) && $centen($product) > $gangbaar,
            ])
            ->sortBy('prijs')
            ->values();

        if ($afwijkend->every(fn (array $regel) => $regel['verwacht'])) {
            return null;
        }

        return [
            'groep_id' => (int) $variant->first()->product_group_id,
            'laagste' => $variant->map($centen)->min() / 100,
            'hoogste' => $variant->map($centen)->max() / 100,
            'aantal' => $variant->count(),
            'gangbare_prijs' => $gangbaar / 100,
            'afwijkend' => $afwijkend->all(),
        ];
    }

    /** Signaalsoort (rendabiliteit()->extraSignalen()) voor een variant die duurder mag zijn dan de gangbare prijs. */
    public const VERWACHT_DUURDER = 'verwacht_duurder';

    /**
     * Product-id's die volgens de geregistreerde extra signalen duurder mogen zijn
     * (signaal ['soort' => 'verwacht_duurder', 'product_id' => int]).
     *
     * @return array<int, true>
     */
    private static function verwachtDuurder(Collection $producten): array
    {
        $ids = [];

        foreach (rendabiliteit()->signalen($producten) as $signaal) {
            if (($signaal['soort'] ?? null) === self::VERWACHT_DUURDER && isset($signaal['product_id'])) {
                $ids[(int) $signaal['product_id']] = true;
            }
        }

        return $ids;
    }
}
