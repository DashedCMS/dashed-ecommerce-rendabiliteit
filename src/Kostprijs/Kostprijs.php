<?php

namespace Dashed\DashedEcommerceRendabiliteit\Kostprijs;

final readonly class Kostprijs
{
    /**
     * @param  array<string, float>  $opbouw  onderdeel → bedrag (excl. btw)
     */
    public function __construct(
        public float $totaal,
        public array $opbouw = [],
    ) {
    }
}
