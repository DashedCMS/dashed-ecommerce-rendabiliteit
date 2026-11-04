<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Kostprijzen;

/** Marge en adviesprijs; puur rekenwerk, zonder database. */
final class Prijsadvies
{
    public static function exclBtw(float $inclBtw, float $btwProcent): float
    {
        return round($inclBtw / (1 + $btwProcent / 100), 4);
    }

    public static function margeEuro(float $inclBtw, float $btwProcent, float $kostprijs): float
    {
        return round(self::exclBtw($inclBtw, $btwProcent) - $kostprijs, 2);
    }

    /** Marge als percentage van de prijs excl. btw; 0 bij een prijs van 0. */
    public static function margeProcent(float $inclBtw, float $btwProcent, float $kostprijs): float
    {
        $excl = self::exclBtw($inclBtw, $btwProcent);

        if ($excl <= 0) {
            return 0.0;
        }

        return round(($excl - $kostprijs) / $excl * 100, 1);
    }

    public static function adviesprijs(float $kostprijs, float $btwProcent, float $doelmargeProcent): float
    {
        $doelmarge = min(95.0, max(0.0, $doelmargeProcent)) / 100;
        $incl = $kostprijs / (1 - $doelmarge) * (1 + $btwProcent / 100);

        return self::rondAfOpNegenVijf($incl);
    }

    /** Het kleinste bedrag n,95 dat minstens het gegeven bedrag is. */
    public static function rondAfOpNegenVijf(float $bedrag): float
    {
        $centen = (int) ceil(round($bedrag * 100, 4));
        $kandidaat = intdiv($centen, 100) * 100 + 95;

        if ($kandidaat < $centen) {
            $kandidaat += 100;
        }

        return $kandidaat / 100;
    }
}
