<?php

namespace Dashed\DashedEcommerceRendabiliteit\Filament\Widgets\Advertenties\Concerns;

use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Dashed\DashedEcommerceRendabiliteit\Support\AdvertentiePeriode;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\AdvertentieDashboard;

/** Periode en cijfers uit de filters van AdvertentiesPage, één keer per render berekend. */
trait LeestAdvertentieCijfers
{
    use InteractsWithPageFilters;

    /** Platformkleuren volgen het platform op elke grafiek (categoriepalet, vaste volgorde). */
    protected const KLEUREN = [
        'Google' => '#2a78d6',
        'Meta' => '#eb6834',
        'Omzet' => '#1baf7a',
        'Marge' => '#008300',
        'MER' => '#1baf7a',
        'Google + Meta' => '#6250d6',
        'Organisch zoeken' => '#eda100',
        'Direct / onbekend' => '#a3a29c',
        'Break-even' => '#8a8984',
        'Overig' => '#e87ba4',
    ];

    private ?AdvertentiePeriode $periodeCache = null;

    private ?array $reeksCache = null;

    protected function periode(): AdvertentiePeriode
    {
        return $this->periodeCache ??= AdvertentiePeriode::uitFilters($this->pageFilters);
    }

    protected function reeks(): array
    {
        $periode = $this->periode();

        return $this->reeksCache ??= app(AdvertentieDashboard::class)->reeks($periode->van, $periode->tot, $periode->groepering);
    }

    protected function kleur(string $naam): string
    {
        return self::KLEUREN[$naam] ?? self::KLEUREN['Overig'];
    }
}
