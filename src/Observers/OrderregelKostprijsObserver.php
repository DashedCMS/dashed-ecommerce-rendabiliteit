<?php

namespace Dashed\DashedEcommerceRendabiliteit\Observers;

use Throwable;
use Dashed\DashedEcommerceCore\Models\OrderProduct;
use Dashed\DashedEcommerceRendabiliteit\Models\OrderregelKostprijs;
use Dashed\DashedEcommerceRendabiliteit\Services\Kostprijzen\OrderregelKostprijsVastlegger;

/**
 * Legt bij elke nieuwe orderregel (webshop, Bol, Etsy, kassa, handmatig) de
 * kostprijs van dat moment vast. Een fout hier mag een order nooit breken.
 * Een definitief verwijderde regel neemt zijn kostprijsrij mee; een
 * soft-deleted regel houdt hem (rapportages filteren op niet-verwijderd).
 */
class OrderregelKostprijsObserver
{
    public function created(OrderProduct $regel): void
    {
        try {
            app(OrderregelKostprijsVastlegger::class)->legVast($regel, OrderregelKostprijs::BRON_BIJ_VERKOOP);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function forceDeleted(OrderProduct $regel): void
    {
        try {
            OrderregelKostprijs::where('order_product_id', $regel->id)->delete();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
