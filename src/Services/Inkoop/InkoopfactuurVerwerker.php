<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Inkoop;

use Illuminate\Support\Facades\DB;
use Dashed\DashedEcommerceCore\Models\Product;
use Dashed\DashedEcommerceRendabiliteit\Models\Inkoopfactuur;
use Dashed\DashedEcommerceRendabiliteit\Models\InkoopfactuurRegel;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\RendabiliteitCache;

/**
 * "Toepassen" van een inkoopfactuur: elke gekoppelde productregel zet één keer
 * purchase_price = nettostuksprijs excl. btw, eventueel met de kostenregels
 * (verzending, toeslag) verdeeld naar regelwaarde.
 */
class InkoopfactuurVerwerker
{
    public const STANDAARD_BTW = 21.0;

    /** Prijs per stuk excl. btw na korting (korting per stuk, op dezelfde basis als de stuksprijs). */
    public static function nettoStuksprijs(float $stuksprijs, float $korting, bool $inclBtw, float $btwProcent): float
    {
        $netto = max(0.0, $stuksprijs - $korting);

        if ($inclBtw) {
            $netto /= 1 + max(0.0, $btwProcent) / 100;
        }

        return round($netto, 4);
    }

    /**
     * Verdeelt $kosten over de regels naar regelwaarde (aantal × stuksprijs) en
     * geeft per regel de nieuwe stuksprijs.
     *
     * @param  list<array{0: float|int, 1: float}>  $regels  [aantal, stuksprijs]
     * @return list<float>
     */
    public static function verdeelKosten(array $regels, float $kosten): array
    {
        $totaal = array_sum(array_map(fn (array $r) => (float) $r[0] * (float) $r[1], $regels));

        return array_map(function (array $r) use ($kosten, $totaal) {
            [$aantal, $prijs] = [(float) $r[0], (float) $r[1]];

            if ($kosten == 0 || $totaal <= 0 || $aantal <= 0) {
                return round($prijs, 4);
            }

            return round($prijs + $kosten * ($aantal * $prijs / $totaal) / $aantal, 4);
        }, $regels);
    }

    /** Zet purchase_price voor elke nog niet toegepaste gekoppelde regel; geeft het aantal bijgewerkte producten. */
    public function pasToe(Inkoopfactuur $factuur): int
    {
        return DB::transaction(function () use ($factuur) {
            $regels = $factuur->regels()->orderBy('id')->get();
            $producten = Product::query()
                ->whereIn('id', $regels->pluck('product_id')->filter()->unique())
                ->get(['id', 'vat_rate'])
                ->keyBy('id');

            $btw = fn (InkoopfactuurRegel $regel) => (float) ($producten->get($regel->product_id)?->vat_rate ?: self::STANDAARD_BTW);
            $netto = fn (InkoopfactuurRegel $regel) => self::nettoStuksprijs($regel->stuksprijs, $regel->korting, $factuur->prijzen_incl_btw, $btw($regel));

            $productregels = $regels->reject(fn (InkoopfactuurRegel $r) => $r->isKosten())->values();
            $kosten = $factuur->kosten_verdelen
                ? $regels->filter(fn (InkoopfactuurRegel $r) => $r->isKosten())->sum(fn (InkoopfactuurRegel $r) => $r->aantal * $netto($r))
                : 0.0;

            // De verdeling rekent over alle productregels, ook al toegepaste of
            // ongekoppelde, zodat de uitkomst niet afhangt van wat al gedaan is.
            $prijzen = self::verdeelKosten(
                $productregels->map(fn (InkoopfactuurRegel $r) => [$r->aantal, $netto($r)])->all(),
                (float) $kosten,
            );

            $bijgewerkt = [];
            foreach ($productregels as $i => $regel) {
                if ($regel->toegepast_at !== null || ! $producten->has($regel->product_id)) {
                    continue;
                }

                Product::query()->whereKey($regel->product_id)->update(['purchase_price' => round($prijzen[$i], 2)]);
                $regel->update(['toegepast_at' => now()]);
                $bijgewerkt[$regel->product_id] = true;
            }

            $klaar = $productregels->every(fn (InkoopfactuurRegel $r) => $r->toegepast_at !== null);
            $factuur->update(['status' => $klaar ? Inkoopfactuur::STATUS_VERWERKT : Inkoopfactuur::STATUS_CONCEPT]);

            if ($bijgewerkt !== []) {
                RendabiliteitCache::wis();
            }

            return count($bijgewerkt);
        });
    }
}
