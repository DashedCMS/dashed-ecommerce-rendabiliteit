<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\Pdf;

use Carbon\CarbonInterface;
use Dashed\DashedEcommerceRendabiliteit\Filament\Pages\Rendabiliteit\RendabiliteitPage;

/** Leesbare en bestandsnaamvriendelijke namen voor een maand of periode. */
final class PeriodeLabel
{
    /** "september 2026" of "maart 2026 t/m september 2026". */
    public static function van(CarbonInterface $van, CarbonInterface $tot): string
    {
        $eerste = RendabiliteitPage::maandLabel($van->format('Y-m'));

        return $van->format('Y-m') === $tot->format('Y-m')
            ? $eerste
            : $eerste.' t/m '.RendabiliteitPage::maandLabel($tot->format('Y-m'));
    }

    /** "2026-09" of "2026-03-2026-09". */
    public static function kort(CarbonInterface $van, CarbonInterface $tot): string
    {
        return $van->format('Y-m') === $tot->format('Y-m')
            ? $van->format('Y-m')
            : $van->format('Y-m').'-'.$tot->format('Y-m');
    }
}
