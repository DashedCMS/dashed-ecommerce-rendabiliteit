<?php

namespace Dashed\DashedEcommerceRendabiliteit\Filament\Widgets\Advertenties;

use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\AdvertentieDashboard;
use Dashed\DashedEcommerceRendabiliteit\Filament\Widgets\Advertenties\Concerns\LeestAdvertentieCijfers;

class UitgavenVerdelingChart extends ChartWidget
{
    use LeestAdvertentieCijfers;

    protected static bool $isDiscovered = false;

    protected static ?int $sort = 4;

    protected ?string $maxHeight = '260px';

    protected ?string $pollingInterval = null;

    public function getHeading(): string
    {
        return 'Uitgaven per platform';
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $perPlatform = AdvertentieDashboard::totaal($this->reeks())['perPlatform'];

        return [
            'labels' => array_keys($perPlatform),
            'datasets' => [[
                'label' => 'Uitgaven',
                'data' => array_column($perPlatform, 'uitgaven'),
                'backgroundColor' => array_map(fn (string $p) => $this->kleur($p), array_keys($perPlatform)),
                'borderColor' => 'rgba(255,255,255,0.9)',
                'borderWidth' => 2,
            ]],
        ];
    }

    protected function getOptions(): RawJs
    {
        return self::donutOpties();
    }

    public static function donutOpties(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                cutout: '62%',
                scales: { x: { display: false }, y: { display: false } },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: { callbacks: { label: (c) => {
                        const totaal = c.dataset.data.reduce((a, b) => a + b, 0);
                        const deel = totaal > 0 ? Math.round(c.parsed / totaal * 100) : 0;
                        return ` ${c.label}: ${new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(c.parsed)} (${deel}%)`;
                    } } },
                },
            }
        JS);
    }
}
