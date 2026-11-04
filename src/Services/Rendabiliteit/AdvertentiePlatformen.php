<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit;

/** Normaliseert vrije-tekst advertentieplatformnamen naar een vaste schrijfwijze. */
class AdvertentiePlatformen
{
    public const BEKEND = ['Google', 'Meta', 'Pinterest', 'Bol'];

    /** Alle platformen die we herkennen, voor keuzelijsten bij het inlezen van een export. */
    public const ALLE = ['Google', 'Meta', 'Bol', 'TikTok', 'Pinterest', 'Microsoft'];

    private const META = ['facebook', 'instagram', 'meta', 'meta ads', 'facebook ads'];

    private const GOOGLE = ['google', 'google ads', 'adwords'];

    private const PINTEREST = ['pinterest', 'pinterest ads'];

    private const BOL = ['bol', 'bol.com', 'bol ads', 'sponsored products'];

    private const TIKTOK = ['tiktok', 'tik tok', 'tiktok ads'];

    private const MICROSOFT = ['microsoft', 'microsoft ads', 'microsoft advertising', 'bing', 'bing ads'];

    public static function normaliseer(?string $naam): string
    {
        $waarde = mb_strtolower(trim((string) $naam));

        if ($waarde === '') {
            return '';
        }

        foreach ([
            'Meta' => self::META,
            'Google' => self::GOOGLE,
            'Pinterest' => self::PINTEREST,
            'Bol' => self::BOL,
            'TikTok' => self::TIKTOK,
            'Microsoft' => self::MICROSOFT,
        ] as $label => $namen) {
            if (in_array($waarde, $namen, true)) {
                return $label;
            }
        }

        return mb_strtoupper(mb_substr($waarde, 0, 1)).mb_substr($waarde, 1);
    }
}
