<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit;

use Illuminate\Support\Facades\Cache;

/** Versienummer in de cachesleutels van het rapport; wis() maakt alle maanden ongeldig. */
class RendabiliteitCache
{
    private const SLEUTEL = 'dashed-ecommerce-rendabiliteit.versie';

    public static function versie(): int
    {
        return (int) Cache::get(self::SLEUTEL, 1);
    }

    public static function wis(): void
    {
        Cache::forever(self::SLEUTEL, self::versie() + 1);
    }
}
