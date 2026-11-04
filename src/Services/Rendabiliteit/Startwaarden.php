<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit;

use Illuminate\Support\Facades\DB;
use Dashed\DashedCore\Models\Customsetting;

/** Startwaarden voor tarieven en instellingen; overschrijft nooit iets. */
class Startwaarden
{
    public const TARIEVEN = [
        ['verzending', 'brievenbus|NL', 3.85],
        ['verzending', 'brievenbus|BE', 5.50],
        ['verzending', 'brievenbus|overig', 8.00],
        ['verzending', 'pakket|NL', 4.75],
        ['verzending', 'pakket|BE', 6.75],
        ['verzending', 'pakket|overig', 8.00],
        ['verpakking', 'pakket', 1.00],
    ];

    public static function zet(): void
    {
        foreach (self::TARIEVEN as [$soort, $sleutel, $bedrag]) {
            DB::table('kostentarieven')->insertOrIgnore([
                'soort' => $soort,
                'sleutel' => $sleutel,
                'vast_bedrag' => $bedrag,
                'percentage' => 0,
                'geldig_vanaf' => '2026-01-01',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (Customsetting::get('rendabiliteit_bol_commissie_incl_btw', null, null, null, 'default', true) === null) {
            Customsetting::set('rendabiliteit_bol_commissie_incl_btw', 1);
        }

        RendabiliteitCache::wis();
    }
}
