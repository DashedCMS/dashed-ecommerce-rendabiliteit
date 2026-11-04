<?php

namespace Dashed\DashedEcommerceRendabiliteit\Filament\Widgets\Advertenties;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\AdvertentieDashboard;
use Dashed\DashedEcommerceRendabiliteit\Filament\Widgets\Advertenties\Concerns\LeestAdvertentieCijfers;

/** KPI-tegels voor de gekozen periode, met het verschil tegenover de even lange periode ervoor. */
class AdvertentieKpis extends StatsOverviewWidget
{
    use LeestAdvertentieCijfers;

    protected static bool $isDiscovered = false;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    protected function getColumns(): int|array|null
    {
        return ['md' => 2, 'xl' => 4];
    }

    protected function getStats(): array
    {
        $periode = $this->periode();
        $reeks = $this->reeks();
        $nu = AdvertentieDashboard::totaal($reeks);
        $vorig = AdvertentieDashboard::totaal(
            app(AdvertentieDashboard::class)->reeks($periode->vorigVan, $periode->vorigTot, $periode->groepering),
        );

        $google = $nu['perPlatform']['Google'] ?? null;
        $meta = $nu['perPlatform']['Meta'] ?? null;
        $geschat = array_sum(array_column($nu['perPlatform'], 'dagenGeschat'));
        $splitsing = collect($nu['perPlatform'])->map(fn (array $p, string $platform) => $platform.' '.self::euro($p['uitgaven']))->implode(' · ');

        return [
            $this->tegel('Advertentie-uitgaven', self::euro($nu['uitgaven']), $nu['uitgaven'], $vorig['uitgaven'], hogerIsBeter: null, extra: $splitsing.($geschat > 0 ? " · {$geschat} dagen geschat" : ''))
                ->chart(array_map(fn (array $e) => round(array_sum($e['uitgaven']), 2), $reeks))
                ->chartColor('gray'),
            $this->tegel('Webshopomzet', self::euro($nu['omzet']), $nu['omzet'], $vorig['omzet'], extra: $nu['orders'].' orders')
                ->chart(array_column($reeks, 'omzet')),
            $this->tegel('MER (omzet ÷ uitgaven)', self::factor($nu['mer']), $nu['mer'], $vorig['mer'], extra: 'break-even '.self::factor($nu['breakEvenRoas'])),
            $this->tegel('Kosten per order', self::euro($nu['kostenPerOrder']), $nu['kostenPerOrder'], $vorig['kostenPerOrder'], hogerIsBeter: false),
            $this->tegel('Marge na orderkosten', self::euro($nu['marge']), $nu['marge'], $vorig['marge'], extra: $nu['omzet'] > 0 ? round($nu['marge'] / $nu['omzet'] * 100).'% van de omzet' : null)
                ->chart(array_column($reeks, 'marge')),
            $this->tegel('Marge min advertenties', self::euro($nu['netto']), $nu['netto'], $vorig['netto'])
                ->chart(array_map(fn (array $e) => round($e['marge'] - array_sum($e['uitgaven']), 2), $reeks))
                ->color($nu['netto'] >= 0 ? 'success' : 'danger'),
            $this->tegel('ROAS Google Ads', self::factor($google['roas'] ?? null), $google['roas'] ?? null, $vorig['perPlatform']['Google']['roas'] ?? null, extra: self::euro($google['omzet'] ?? 0).' omzet · break-even '.self::factor($google['breakEvenRoas'] ?? null)),
            $this->tegel('ROAS Meta Ads', self::factor($meta['roas'] ?? null), $meta['roas'] ?? null, $vorig['perPlatform']['Meta']['roas'] ?? null, extra: self::euro($meta['omzet'] ?? 0).' omzet · break-even '.self::factor($meta['breakEvenRoas'] ?? null)),
        ];
    }

    /** @param  ?bool  $hogerIsBeter  null = neutraal (grijs) */
    private function tegel(string $label, string $waarde, ?float $nu, ?float $vorig, ?bool $hogerIsBeter = true, ?string $extra = null): Stat
    {
        $stat = Stat::make($label, $waarde);
        $verschil = ($nu !== null && $vorig !== null && $vorig != 0.0) ? ($nu - $vorig) / abs($vorig) * 100 : null;
        $delen = array_filter([$extra]);

        if ($verschil !== null) {
            $delen[] = ($verschil >= 0 ? '+' : '−').number_format(abs($verschil), 0, ',', '.').'% t.o.v. vorige periode';
            $stat->descriptionIcon($verschil >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down');
            $goed = $hogerIsBeter === null ? null : ($hogerIsBeter ? $verschil >= 0 : $verschil <= 0);
            $stat->descriptionColor($goed === null || abs($verschil) < 1 ? 'gray' : ($goed ? 'success' : 'danger'));
        }

        return $stat->description(implode(' · ', $delen) ?: null);
    }

    private static function euro(?float $bedrag): string
    {
        return $bedrag === null ? '—' : '€ '.number_format($bedrag, 0, ',', '.');
    }

    private static function factor(?float $waarde): string
    {
        return $waarde === null ? '—' : number_format($waarde, 2, ',', '.');
    }
}
