<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Kostprijzen;

/**
 * Uitkomst van AdviesprijsDoorvoerder::voerDoorVolgens(): overgeslagen
 * (product verwijderd, of prijs op of boven de van-prijs) is geen fout;
 * alleen `mislukt` telt echte fouten bij het opslaan.
 */
final class DoorvoerResultaat
{
    public function __construct(
        public readonly int $gelukt = 0,
        public readonly int $overgeslagen = 0,
        public readonly int $mislukt = 0,
    ) {
    }
}
