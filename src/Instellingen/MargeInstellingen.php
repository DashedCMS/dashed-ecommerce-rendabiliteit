<?php

namespace Dashed\DashedEcommerceRendabiliteit\Instellingen;

use Dashed\DashedCore\Models\Customsetting;

/**
 * Instellingen voor marges en adviesprijzen. Leest zonder de statische
 * Customsetting-cache; de sleutels zijn die van de oorspronkelijke opzet.
 */
class MargeInstellingen
{
    public const DOELMARGE = 'kostprijs_doelmarge';

    public const BOVEN_ADVIES_PROCENT = 'kostprijs_boven_advies_procent';

    public const BOL_COMMISSIE_INCL_BTW = 'rendabiliteit_bol_commissie_incl_btw';

    /** Procent van de prijs excl. btw, begrensd op 0–95 zodat de adviesprijs nooit door 0 deelt. */
    public static function doelmarge(): float
    {
        return min(95.0, max(0.0, self::getal(self::DOELMARGE, 60.0)));
    }

    /** Vanaf hoeveel procent boven de adviesprijs een prijs op de Marges-pagina opvalt. */
    public static function bovenAdviesProcent(): float
    {
        return self::getal(self::BOVEN_ADVIES_PROCENT, 30.0);
    }

    /** Of de Bol-commissie op de order incl. btw is (standaard ja). */
    public static function bolCommissieInclBtw(): bool
    {
        return (bool) (int) Customsetting::get(self::BOL_COMMISSIE_INCL_BTW, null, '1', null, 'default', true);
    }

    /** Negatieve waarden tellen als 0, een lege of niet-numerieke waarde geeft de standaard. */
    private static function getal(string $sleutel, float $standaard): float
    {
        $waarde = Customsetting::get($sleutel, null, null, null, 'default', true);

        return is_numeric($waarde) ? max(0.0, (float) $waarde) : $standaard;
    }
}
