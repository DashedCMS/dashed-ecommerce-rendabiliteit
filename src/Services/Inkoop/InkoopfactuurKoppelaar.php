<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Inkoop;

use Dashed\DashedEcommerceCore\Models\Product;
use Dashed\DashedEcommerceRendabiliteit\Models\Inkoopfactuur;
use Dashed\DashedEcommerceRendabiliteit\Models\InkoopfactuurRegel;
use Dashed\DashedEcommerceRendabiliteit\Models\LeverancierArtikel;

/**
 * Koppelt productregels van een inkoopfactuur aan een product: eerst op EAN,
 * dan op SKU (de artikelcode), dan op een onthouden artikelcode van deze
 * leverancier. Een handmatige keuze wordt onthouden voor de volgende factuur.
 */
class InkoopfactuurKoppelaar
{
    /** Koppelt de regels zonder product; geeft het aantal nieuw gekoppelde regels. */
    public function koppel(Inkoopfactuur $factuur): int
    {
        $gekoppeld = 0;

        foreach ($factuur->regels()->whereNull('product_id')->where('soort', InkoopfactuurRegel::SOORT_PRODUCT)->get() as $regel) {
            [$productId, $koppeling] = $this->zoek($factuur->leverancier, $regel);

            if ($productId === null) {
                continue;
            }

            $regel->update(['product_id' => $productId, 'koppeling' => $koppeling]);
            $gekoppeld++;
        }

        return $gekoppeld;
    }

    /** Zet het gekozen product op de regel en onthoudt de artikelcode van de leverancier. */
    public function kiesHandmatig(InkoopfactuurRegel $regel, ?int $productId): void
    {
        $regel->update([
            'product_id' => $productId,
            'koppeling' => $productId ? InkoopfactuurRegel::KOPPELING_HANDMATIG : null,
        ]);

        $this->onthoud($regel);
    }

    /** Onthoudt alle handmatige koppelingen van een factuur (na opslaan van het formulier). */
    public function onthoudHandmatige(Inkoopfactuur $factuur): void
    {
        $factuur->regels()
            ->where('koppeling', InkoopfactuurRegel::KOPPELING_HANDMATIG)
            ->whereNotNull('product_id')
            ->get()
            ->each(fn (InkoopfactuurRegel $regel) => $this->onthoud($regel));
    }

    private function onthoud(InkoopfactuurRegel $regel): void
    {
        $artikelcode = trim((string) $regel->artikelcode);
        $leverancier = trim((string) $regel->factuur?->leverancier);

        if ($artikelcode === '' || $leverancier === '' || ! $regel->product_id) {
            return;
        }

        LeverancierArtikel::updateOrCreate(
            ['leverancier' => $leverancier, 'artikelcode' => $artikelcode],
            ['product_id' => $regel->product_id],
        );
    }

    /** @return array{0: ?int, 1: ?string} [product-id, koppeling] */
    private function zoek(string $leverancier, InkoopfactuurRegel $regel): array
    {
        $ean = preg_replace('/\s+/', '', (string) $regel->ean);
        if ($ean !== '' && ($id = Product::query()->where('ean', $ean)->value('id'))) {
            return [(int) $id, InkoopfactuurRegel::KOPPELING_EAN];
        }

        $artikelcode = trim((string) $regel->artikelcode);
        if ($artikelcode === '') {
            return [null, null];
        }

        if ($id = Product::query()->where('sku', $artikelcode)->value('id')) {
            return [(int) $id, InkoopfactuurRegel::KOPPELING_SKU];
        }

        $id = LeverancierArtikel::query()
            ->where('leverancier', trim($leverancier))
            ->where('artikelcode', $artikelcode)
            ->value('product_id');

        if ($id && Product::query()->whereKey($id)->exists()) {
            return [(int) $id, InkoopfactuurRegel::KOPPELING_ONTHOUDEN];
        }

        return [null, null];
    }
}
