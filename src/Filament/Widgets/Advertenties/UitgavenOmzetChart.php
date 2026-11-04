<?php

namespace Dashed\DashedEcommerceRendabiliteit\Filament\Widgets\Advertenties;

use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Dashed\DashedEcommerceRendabiliteit\Filament\Widgets\Advertenties\Concerns\LeestAdvertentieCijfers;

/** Uitgaven per platform (gestapeld) met webshopomzet en marge als lijnen, op één euro-as. */
class UitgavenOmzetChart extends ChartWidget
{
    use LeestAdvertentieCijfers;

    protected static bool $isDiscovered = false;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '340px';

    protected ?string $pollingInterval = null;

    public function getHeading(): string
    {
        return 'Uitgaven tegenover omzet en marge';
    }

    public function getDescription(): string
    {
        return 'Webshop, excl. btw, '.$this->periode()->groepering.' · '.$this->periode()->omschrijving().'. Marge = na kostprijs en orderkosten.';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $reeks = $this->reeks();
        $platformen = array_keys($reeks[0]['uitgaven'] ?? []);
        $datasets = [];

        foreach ($platformen as $platform) {
            $datasets[] = [
                'type' => 'bar',
                'label' => "Uitgaven {$platform}",
                'data' => array_map(fn (array $e) => $e['uitgaven'][$platform], $reeks),
                'backgroundColor' => $this->kleur($platform),
                'borderColor' => 'rgba(255,255,255,0.9)',
                'borderWidth' => ['top' => 2],
                'borderRadius' => 4,
                'stack' => 'uitgaven',
                'order' => 2,
            ];
        }

        foreach (['Omzet' => 'omzet', 'Marge' => 'marge'] as $label => $veld) {
            $datasets[] = [
                'type' => 'line',
                'label' => $label,
                'data' => array_column($reeks, $veld),
                'borderColor' => $this->kleur($label),
                'backgroundColor' => $this->kleur($label),
                'borderWidth' => 2,
                'pointRadius' => count($reeks) > 40 ? 0 : 3,
                'pointHoverRadius' => 5,
                'tension' => 0.25,
                'stack' => $veld,
                'order' => 1,
            ];
        }

        return ['labels' => array_column($reeks, 'label'), 'datasets' => $datasets];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: { callbacks: { label: (c) => ` ${c.dataset.label}: ${new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }).format(c.parsed.y ?? 0)}` } },
                },
                scales: {
                    x: { stacked: true, grid: { display: false } },
                    y: { stacked: true, beginAtZero: true, ticks: { callback: (v) => new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(v) } },
                },
            }
        JS);
    }
}
