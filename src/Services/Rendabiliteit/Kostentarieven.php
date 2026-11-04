<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit;

use Carbon\CarbonInterface;
use Dashed\DashedEcommerceRendabiliteit\Models\Kostentarief;

/** Geldend tarief op een datum: de rij met de grootste geldig_vanaf ≤ datum. */
class Kostentarieven
{
    /** @var array<string, list<Kostentarief>>|null  soort|sleutel → rijen aflopend op geldig_vanaf */
    private ?array $tarieven = null;

    public function geldig(string $soort, string $sleutel, CarbonInterface $datum): ?Kostentarief
    {
        $this->tarieven ??= Kostentarief::orderByDesc('geldig_vanaf')->get()
            ->groupBy(fn (Kostentarief $t) => $t->soort.'|'.$t->sleutel)
            ->map(fn ($rijen) => $rijen->values()->all())
            ->all();

        foreach ($this->tarieven[$soort.'|'.$sleutel] ?? [] as $tarief) {
            if ($tarief->geldig_vanaf->lte($datum->copy()->startOfDay())) {
                return $tarief;
            }
        }

        return null;
    }

    public function bedrag(?Kostentarief $tarief, float $grondslag): float
    {
        if ($tarief === null) {
            return 0.0;
        }

        return round($tarief->vast_bedrag + $tarief->percentage / 100 * $grondslag, 4);
    }
}
