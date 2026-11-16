<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Kostprijzen;

use Dashed\DashedEcommerceCore\Models\Product;
use Dashed\DashedEcommerceCore\Models\OrderProduct;
use Dashed\DashedEcommerceRendabiliteit\Contracts\KostprijsBron;
use Dashed\DashedEcommerceRendabiliteit\Models\OrderregelKostprijs;

/**
 * Legt de kostprijs per stuk van een orderregel vast, uit de kostprijsbron
 * (rendabiliteit()->kostprijsBron()). Een rij met bron
 * bij_verkoop wordt nooit overschreven; aangevulde rijen mogen opnieuw.
 */
class OrderregelKostprijsVastlegger
{
    public function legVast(OrderProduct $regel, string $bron, ?KostprijsBron $kostprijsBron = null): bool
    {
        if (! $regel->product_id) {
            return false;
        }

        // Ook verwijderde producten: een orderregel van een inmiddels verwijderde variant heeft nog steeds een kostprijs.
        $product = Product::withTrashed()->find($regel->product_id);

        if (! $product) {
            return false;
        }

        $bestaand = OrderregelKostprijs::where('order_product_id', $regel->id)->first();

        if ($bestaand && $bestaand->bron === OrderregelKostprijs::BRON_BIJ_VERKOOP) {
            return false;
        }

        $kostprijs = ($kostprijsBron ?? rendabiliteit()->kostprijsBron())->voorProduct($product);

        if ($kostprijs === null) {
            return false;
        }

        OrderregelKostprijs::updateOrCreate(
            ['order_product_id' => $regel->id],
            [
                'order_id' => $regel->order_id,
                'product_id' => $regel->product_id,
                'kostprijs' => $kostprijs->totaal,
                'opbouw' => $kostprijs->opbouw,
                'bron' => $bron,
                'berekend_op' => now(),
            ],
        );

        return true;
    }

    /**
     * Vult regels zonder kostprijs aan; met $opnieuw ook de al aangevulde regels.
     *
     * @param  callable(int): void|null  $naPortie  Na elke portie, met het aantal bekeken regels.
     */
    public function vulAan(bool $opnieuw = false, bool $dryRun = false, ?callable $naPortie = null): int
    {
        $kostprijsBron = rendabiliteit()->kostprijsBron();
        $aantal = 0;

        $this->teVullen($opnieuw)
            ->chunkById(500, function ($regels) use ($kostprijsBron, $dryRun, &$aantal, $naPortie) {
                foreach ($regels as $regel) {
                    if ($dryRun) {
                        $aantal++;

                        continue;
                    }

                    if ($this->legVast($regel, OrderregelKostprijs::BRON_AANGEVULD, $kostprijsBron)) {
                        $aantal++;
                    }
                }

                if ($naPortie) {
                    $naPortie($regels->count());
                }
            });

        return $aantal;
    }

    /** Hoeveel regels vulAan() zou bekijken. */
    public function aantalTeVullen(bool $opnieuw = false): int
    {
        return $this->teVullen($opnieuw)->count();
    }

    private function teVullen(bool $opnieuw)
    {
        $regelId = (new OrderProduct())->getTable().'.id';

        return OrderProduct::query()
            ->whereNotNull('product_id')
            ->where(function ($query) use ($opnieuw, $regelId) {
                $query->whereNotExists(fn ($sub) => $sub->selectRaw('1')
                    ->from('orderregel_kostprijzen')
                    ->whereColumn('orderregel_kostprijzen.order_product_id', $regelId));

                if ($opnieuw) {
                    $query->orWhereExists(fn ($sub) => $sub->selectRaw('1')
                        ->from('orderregel_kostprijzen')
                        ->whereColumn('orderregel_kostprijzen.order_product_id', $regelId)
                        ->where('bron', OrderregelKostprijs::BRON_AANGEVULD));
                }
            });
    }
}
