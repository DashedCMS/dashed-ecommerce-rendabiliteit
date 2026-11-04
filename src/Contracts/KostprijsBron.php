<?php

namespace Dashed\DashedEcommerceRendabiliteit\Contracts;

use Dashed\DashedEcommerceCore\Models\Product;
use Dashed\DashedEcommerceRendabiliteit\Kostprijs\Kostprijs;

interface KostprijsBron
{
    /** Kostprijs excl. btw van één product, of null als die onbekend is. */
    public function voorProduct(Product $product): ?Kostprijs;
}
