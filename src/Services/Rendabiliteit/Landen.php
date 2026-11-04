<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit;

/** Normaliseert vrije-tekst landnamen/-codes naar 'NL', 'BE' of 'overig'. */
class Landen
{
    private const NL = ['nl', 'nederland', 'netherlands', 'niederlande'];

    private const BE = ['be', 'belgië', 'belgie', 'belgium', 'belgien'];

    public static function normaliseer(?string $land): string
    {
        $waarde = mb_strtolower(trim((string) $land));

        if (in_array($waarde, self::NL, true)) {
            return 'NL';
        }

        if (in_array($waarde, self::BE, true)) {
            return 'BE';
        }

        return 'overig';
    }
}
