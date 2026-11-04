<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit;

/** Vertaalt Dashed' order_origin naar een menselijk kanaal voor het rapport. */
class Kanalen
{
    private const MAP = [
        'own' => 'Webshop',
        'bol' => 'Bol',
        'etsy' => 'Etsy',
        'pos' => 'Kassa',
    ];

    public static function voor(?string $orderOrigin): string
    {
        $waarde = mb_strtolower(trim((string) $orderOrigin));

        return self::MAP[$waarde] ?? 'Overig';
    }
}
