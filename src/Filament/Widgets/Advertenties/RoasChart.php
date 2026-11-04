<?php

namespace Dashed\DashedEcommerceRendabiliteit\Filament\Widgets\Advertenties;

use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\Aandachtspunten;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\AdvertentieDashboard;
use Dashed\DashedEcommerceRendabiliteit\Filament\Widgets\Advertenties\Concerns\LeestAdvertentieCijfers;

/**
 * ROAS per platform (toegeschreven omzet ÷ uitgave), MER (alle webshopomzet ÷ alle uitgaven)
 * en de break-even-ROAS (omzet ÷ marge). ROAS pas vanaf Aandachtspunten::ATTRIBUTIE_VANAF:
 * daarvoor hebben orders geen klik-ID's.
 */
class RoasChart extends ChartWidget
{
    use LeestAdvertentieCijfers;

    protected static bool $isDiscovered = false;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '300px';

    protected ?string $pollingInterval = null;

    public function getHeading(): string
    {
        return 'ROAS en MER';
    }

    public function getDescription(): string
    {
        return 'Boven de break-even-lijn verdienen de advertenties hun kosten terug uit de marge. ROAS per platform telt vanaf mei 2026 (daarvoor geen klik-ID\'s op orders).';
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $reeks = $this->reeks();
        $platformen = array_keys($reeks[0]['uitgaven'] ?? []);
        $vanaf = Aandachtspunten::ATTRIBUTIE_VANAF.'-01';
        $datasets = [];

        foreach ($platformen as $platform) {
            $kanaal = AdvertentieDashboard::KANAAL_PER_PLATFORM[$platform] ?? null;

            if ($kanaal === null) {
                continue;
            }

            $datasets[] = $this->lijn("ROAS {$platform}", $this->kleur($platform), array_map(
                fn (array $e) => $e['van'] < $vanaf ? null : AdvertentieDashboard::deel($e['kanalen'][$kanaal]['omzet'] ?? 0.0, $e['uitgaven'][$platform]),
                $reeks,
            ));
        }

        $datasets[] = $this->lijn('MER', $this->kleur('MER'), array_map(fn (array $e) => AdvertentieDashboard::deel($e['omzet'], array_sum($e['uitgaven'])), $reeks));
        $datasets[] = $this->lijn('Break-even-ROAS', $this->kleur('Break-even'), array_map(fn (array $e) => $e['marge'] > 0 ? AdvertentieDashboard::deel($e['omzet'], $e['marge']) : null, $reeks)) + ['borderDash' => [6, 4], 'pointRadius' => 0];

        return ['labels' => array_column($reeks, 'label'), 'datasets' => $datasets];
    }

    private function lijn(string $label, string $kleur, array $data): array
    {
        return [
            'label' => $label,
            'data' => $data,
            'borderColor' => $kleur,
            'backgroundColor' => $kleur,
            'borderWidth' => 2,
            'pointRadius' => count($data) > 40 ? 0 : 3,
            'pointHoverRadius' => 5,
            'tension' => 0.25,
            'spanGaps' => false,
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: { callbacks: { label: (c) => c.parsed.y === null ? null : ` ${c.dataset.label}: ${c.parsed.y.toLocaleString('nl-NL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}` } },
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, ticks: { callback: (v) => v.toLocaleString('nl-NL') } },
                },
            }
        JS);
    }
}
