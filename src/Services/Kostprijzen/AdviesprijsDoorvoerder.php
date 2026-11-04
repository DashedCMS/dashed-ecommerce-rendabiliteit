<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Kostprijzen;

use Illuminate\Support\Collection;
use Dashed\DashedEcommerceCore\Models\Product;
use Dashed\DashedEcommerceRendabiliteit\Instellingen\MargeInstellingen;

/**
 * Stelt adviesprijzen voor (kostprijs naar de doelmarge, afgerond op ,95)
 * en voert ze door. Doorvoeren gaat via save() zodat Dashed min-/maxprijs
 * van de groep, zoekindex en feeds bijwerkt; new_price blijft staan.
 */
class AdviesprijsDoorvoerder
{
    /**
     * @param  Collection<int, Product>  $producten
     * @return Collection<int, array{product: Product, oud: float, nieuw: float, kostprijs: float}>
     */
    public function voorstel(Collection $producten, bool $alleenVerhogen = true): Collection
    {
        return $this->voorstelMetOverslag($producten, $alleenVerhogen)['voorstel'];
    }

    /**
     * Aantal producten dat uit het voorstel valt omdat de adviesprijs op of
     * boven hun "van"-prijs (`new_price`) uitkomt (zelfde regels als
     * `voorstel()`), zodat de modal dit kan melden.
     *
     * @param  Collection<int, Product>  $producten
     */
    public function overgeslagenVanwegeVanprijs(Collection $producten, bool $alleenVerhogen = true): int
    {
        return $this->voorstelMetOverslag($producten, $alleenVerhogen)['overgeslagen'];
    }

    /**
     * Voorstel en het aantal overgeslagen producten (adviesprijs op of boven
     * de van-prijs) in één berekening, voor de modal.
     *
     * @param  Collection<int, Product>  $producten
     * @return array{voorstel: Collection<int, array{product: Product, oud: float, nieuw: float, kostprijs: float}>, overgeslagen: int}
     */
    public function voorstelMetOverslag(Collection $producten, bool $alleenVerhogen = true): array
    {
        [$overgeslagen, $voorstel] = $this->berekenRegels($producten, $alleenVerhogen)
            ->partition(fn (array $regel) => self::boventVanprijs($regel));

        return [
            'voorstel' => $voorstel->values(),
            'overgeslagen' => $overgeslagen->count(),
        ];
    }

    /** @param Collection<int, array{product: Product, oud: float, nieuw: float, kostprijs: float}> $voorstel */
    public function voerDoor(Collection $voorstel): int
    {
        $gelukt = 0;

        foreach ($voorstel as $regel) {
            if ($this->zetPrijs($regel['product'], $regel['nieuw'])) {
                $gelukt++;
            }
        }

        return $gelukt;
    }

    /**
     * Zet precies de meegegeven prijzen (zoals getoond in de modal), zonder
     * opnieuw te rekenen. Overgeslagen: producten die niet meer bestaan en
     * prijzen op of boven de van-prijs (`new_price`), ook als de modal
     * verouderd of gemanipuleerd is.
     *
     * @param  array<int, float>  $prijzenPerProductId
     */
    public function voerDoorVolgens(array $prijzenPerProductId): DoorvoerResultaat
    {
        if ($prijzenPerProductId === []) {
            return new DoorvoerResultaat();
        }

        $producten = Product::query()->whereIn('id', array_keys($prijzenPerProductId))->get();
        $gelukt = 0;
        $mislukt = 0;
        $overgeslagen = count($prijzenPerProductId) - $producten->count();

        foreach ($producten as $product) {
            $prijs = (float) $prijzenPerProductId[$product->id];
            $vanPrijs = (float) $product->new_price;

            if ($vanPrijs > 0 && $prijs >= $vanPrijs) {
                $overgeslagen++;

                continue;
            }

            $this->zetPrijs($product, $prijs) ? $gelukt++ : $mislukt++;
        }

        return new DoorvoerResultaat($gelukt, $overgeslagen, $mislukt);
    }

    private function zetPrijs(Product $product, float $prijs): bool
    {
        try {
            $product->price = $prijs;
            $product->save();
            // current_price wordt niet bij save() gevuld, alleen door calculatePrices().
            $product->calculatePrices();

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * @param  Collection<int, Product>  $producten
     * @return Collection<int, array{product: Product, oud: float, nieuw: float, kostprijs: float}>
     */
    private function berekenRegels(Collection $producten, bool $alleenVerhogen): Collection
    {
        $bron = rendabiliteit()->kostprijsBron();
        $doelmarge = MargeInstellingen::doelmarge();

        return $producten
            ->map(function (Product $product) use ($bron, $doelmarge) {
                $oud = (float) $product->price;
                $kostprijs = $bron->voorProduct($product);

                if ($oud <= 0 || $kostprijs === null) {
                    return null;
                }

                return [
                    'product' => $product,
                    'oud' => $oud,
                    'nieuw' => Prijsadvies::adviesprijs($kostprijs->totaal, (float) ($product->vat_rate ?? 21), $doelmarge),
                    'kostprijs' => $kostprijs->totaal,
                ];
            })
            ->filter()
            ->reject(fn (array $regel) => abs($regel['nieuw'] - $regel['oud']) < 0.005)
            ->reject(fn (array $regel) => $alleenVerhogen && $regel['nieuw'] <= $regel['oud']);
    }

    /** @param array{product: Product, oud: float, nieuw: float, kostprijs: float} $regel */
    private static function boventVanprijs(array $regel): bool
    {
        $vanPrijs = (float) $regel['product']->new_price;

        return $vanPrijs > 0 && $regel['nieuw'] >= $vanPrijs;
    }
}
