<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\Koppelingen;

use Carbon\CarbonImmutable;

interface AdvertentieKoppeling
{
    /** Sleutel uit KoppelingInstellingen, bv. "meta". */
    public function sleutel(): string;

    /** Platformnaam zoals in advertentie_uitgaven, bv. "Meta". */
    public function platform(): string;

    /** Of de verplichte inloggegevens zijn ingevuld. */
    public function isIngesteld(): bool;

    /** Eerste dag die het platform nog kan leveren, of null zonder grens. */
    public function vroegsteDag(): ?CarbonImmutable;

    /**
     * Uitgaven in euro per dag; een dag zonder uitgaven mag ontbreken.
     *
     * @return array<string, float> Y-m-d => bedrag
     *
     * @throws KoppelingFout
     */
    public function uitgaven(CarbonImmutable $van, CarbonImmutable $tot): array;
}
