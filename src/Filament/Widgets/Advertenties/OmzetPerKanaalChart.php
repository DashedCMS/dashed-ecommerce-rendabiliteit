<?php

namespace Dashed\DashedEcommerceRendabiliteit\Filament\Widgets\Advertenties;

use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\Marketingkanalen;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\AdvertentieDashboard;
use Dashed\DashedEcommerceRendabiliteit\Filament\Widgets\Advertenties\Concerns\LeestAdvertentieCijfers;

/** Webshopomzet per last-click-marketingkanaal (Marketingkanalen::voor). */
class OmzetPerKanaalChart extends ChartWidget
{
    use LeestAdvertentieCijfers;

    protected static bool $isDiscovered = false;

    protected static ?int $sort = 5;

    protected ?string $maxHeight = '260px';

    protected ?string $pollingInterval = null;

    public function getHeading(): string
    {
        return 'Webshopomzet per kanaal';
    }

    public function getDescription(): string
    {
        return 'Last click; vóór mei 2026 valt bijna alles onder Direct / onbekend.';
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $kanalen = AdvertentieDashboard::totaal($this->reeks())['kanalen'];
        $volgorde = array_flip(Marketingkanalen::VOLGORDE);
        uksort($kanalen, fn (string $a, string $b) => ($volgorde[$a] ?? PHP_INT_MAX) <=> ($volgorde[$b] ?? PHP_INT_MAX));
        $kanalen = array_filter($kanalen, fn (array $c) => $c['omzet'] > 0);

        return [
            'labels' => array_keys($kanalen),
            'datasets' => [[
                'label' => 'Omzet',
                'data' => array_column($kanalen, 'omzet'),
                'backgroundColor' => array_map(fn (string $k) => $this->kleur(match ($k) {
                    Marketingkanalen::GOOGLE => 'Google',
                    Marketingkanalen::META => 'Meta',
                    default => $k,
                }), array_keys($kanalen)),
                'borderColor' => 'rgba(255,255,255,0.9)',
                'borderWidth' => 2,
            ]],
        ];
    }

    protected function getOptions(): RawJs
    {
        return UitgavenVerdelingChart::donutOpties();
    }
}
