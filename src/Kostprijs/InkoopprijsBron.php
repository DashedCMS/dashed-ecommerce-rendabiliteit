<?php

namespace Dashed\DashedEcommerceRendabiliteit\Kostprijs;

use Dashed\DashedEcommerceCore\Models\Product;
use Dashed\DashedEcommerceRendabiliteit\Contracts\KostprijsBron;

class InkoopprijsBron implements KostprijsBron
{
    public function voorProduct(Product $product): ?Kostprijs
    {
        $inkoop = (float) $product->purchase_price;

        if ($inkoop <= 0) {
            return null;
        }

        return new Kostprijs($inkoop, ['inkoop' => $inkoop]);
    }
}
