<?php

namespace Dashed\DashedEcommerceRendabiliteit\Support;

use Illuminate\Support\Carbon;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\AdvertentieDashboard;

/**
 * Leest de filters van het advertentiedashboard: een vaste periode of van/tot, de groepering
 * en de even lange periode ervoor (voor de vergelijking in de KPI-tegels).
 */
final class AdvertentiePeriode
{
    public const PERIODES = [
        '30d' => 'Laatste 30 dagen',
        '90d' => 'Laatste 90 dagen',
        'deze_maand' => 'Deze maand',
        'vorige_maand' => 'Vorige maand',
        'dit_jaar' => 'Dit jaar',
        '12m' => 'Laatste 12 maanden',
        'eigen' => 'Eigen periode',
    ];

    public const GROEPERINGEN = [
        'auto' => 'Automatisch',
        AdvertentieDashboard::DAG => 'Per dag',
        AdvertentieDashboard::WEEK => 'Per week',
        AdvertentieDashboard::MAAND => 'Per maand',
    ];

    /** Langere periodes worden ingekort: elke maand is een eigen berekening. */
    private const MAX_DAGEN = 1100;

    private function __construct(
        public readonly Carbon $van,
        public readonly Carbon $tot,
        public readonly string $groepering,
        public readonly Carbon $vorigVan,
        public readonly Carbon $vorigTot,
    ) {
    }

    public static function standaardFilters(): array
    {
        return ['periode' => '90d', 'van' => null, 'tot' => null, 'groepering' => 'auto'];
    }

    public static function uitFilters(?array $filters): self
    {
        $filters = array_merge(self::standaardFilters(), array_filter($filters ?? [], fn ($waarde) => $waarde !== null && $waarde !== ''));
        $vandaag = Carbon::today();

        [$van, $tot] = match ($filters['periode']) {
            '30d' => [$vandaag->copy()->subDays(29), $vandaag->copy()],
            'deze_maand' => [$vandaag->copy()->startOfMonth(), $vandaag->copy()],
            'vorige_maand' => [$vandaag->copy()->subMonthNoOverflow()->startOfMonth(), $vandaag->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'dit_jaar' => [$vandaag->copy()->startOfYear(), $vandaag->copy()],
            '12m' => [$vandaag->copy()->subMonthsNoOverflow(11)->startOfMonth(), $vandaag->copy()],
            'eigen' => self::eigen($filters, $vandaag),
            default => [$vandaag->copy()->subDays(89), $vandaag->copy()],
        };

        if ($van->diffInDays($tot) + 1 > self::MAX_DAGEN) {
            $van = $tot->copy()->subDays(self::MAX_DAGEN - 1);
        }

        $groepering = array_key_exists($filters['groepering'], self::GROEPERINGEN) && $filters['groepering'] !== 'auto'
            ? $filters['groepering']
            : AdvertentieDashboard::standaardGroepering($van, $tot);

        $lengte = (int) $van->diffInDays($tot) + 1;
        $vorigTot = $van->copy()->subDay();

        return new self($van, $tot, $groepering, $vorigTot->copy()->subDays($lengte - 1), $vorigTot);
    }

    public function omschrijving(): string
    {
        return $this->van->translatedFormat('j M Y').' t/m '.$this->tot->translatedFormat('j M Y');
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private static function eigen(array $filters, Carbon $vandaag): array
    {
        $tot = self::datum($filters['tot']) ?? $vandaag->copy();
        $van = self::datum($filters['van']) ?? $tot->copy()->subDays(89);

        return $van->gt($tot) ? [$tot, $van] : [$van, $tot];
    }

    private static function datum(mixed $waarde): ?Carbon
    {
        try {
            return $waarde ? Carbon::parse($waarde)->startOfDay() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
