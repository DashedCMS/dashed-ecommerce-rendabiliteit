<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit;

/** Normaliseert vrije-tekst advertentieplatformnamen naar een vaste schrijfwijze. */
class AdvertentiePlatformen
{
    public const BEKEND = ['Google', 'Meta', 'Pinterest', 'Bol'];

    private const META = ['facebook', 'instagram', 'meta', 'meta ads', 'facebook ads'];

    private const GOOGLE = ['google', 'google ads', 'adwords'];

    private const PINTEREST = ['pinterest', 'pinterest ads'];

    private const BOL = ['bol', 'bol.com', 'bol ads', 'sponsored products'];

    public static function normaliseer(?string $naam): string
    {
        $waarde = mb_strtolower(trim((string) $naam));

        if ($waarde === '') {
            return '';
        }

        if (in_array($waarde, self::META, true)) {
            return 'Meta';
        }

        if (in_array($waarde, self::GOOGLE, true)) {
            return 'Google';
        }

        if (in_array($waarde, self::PINTEREST, true)) {
            return 'Pinterest';
        }

        if (in_array($waarde, self::BOL, true)) {
            return 'Bol';
        }

        return mb_strtoupper(mb_substr($waarde, 0, 1)).mb_substr($waarde, 1);
    }
}
