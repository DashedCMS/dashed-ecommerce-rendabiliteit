<?php

namespace Dashed\DashedEcommerceRendabiliteit\Support;

use Dashed\DashedEcommerceCore\Models\ProductFilterOption;

/**
 * Leesbare naam van een maatsleutel (gesorteerde filteroptie-id's). Een
 * geregistreerde kostprijslink mag een eigen naam meegeven ('maat').
 */
class Maatlabel
{
    public static function voor(int $groepId, string $sleutel, string $leeg = 'één maat'): string
    {
        if ($sleutel === '') {
            return $leeg;
        }

        $eigen = rendabiliteit()->linkVoor($groepId, $sleutel)['maat'] ?? null;

        return filled($eigen) ? (string) $eigen : self::uitFilters($sleutel);
    }

    /** De namen van de filteropties, op de volgorde van de filters. */
    public static function uitFilters(string $sleutel): string
    {
        if ($sleutel === '') {
            return '—';
        }

        return ProductFilterOption::query()
            ->whereIn('id', explode(',', $sleutel))
            ->with('productFilter')
            ->get()
            ->sortBy(fn (ProductFilterOption $optie) => [$optie->productFilter?->order ?? 0, $optie->product_filter_id])
            ->map(fn (ProductFilterOption $optie) => $optie->getTranslation('name', 'nl'))
            ->implode(' · ');
    }
}
