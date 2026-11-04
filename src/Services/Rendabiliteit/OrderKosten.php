<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit;

/** Omzet en kosten van één order, alle bedragen excl. btw. */
final class OrderKosten
{
    /**
     * @param  array<int, array{omzet: float, kostprijs: float, orderkosten: float}>  $perProductgroep  groep-id (0 = zonder productgroep)
     * @param  array<string, array{omschrijving: string, aantal: int, omzet: float}>  $waarschuwingen
     * @param  array<string, array{tarief: float, verzending: float, bronnen: array<string, int>}>  $verzendDetail  vervoerder → ongecorrigeerd tarief, gecorrigeerde verzending en aantal labels per bron
     * @param  string  $marketingkanaal  last-click-kanaal (Marketingkanalen::voor)
     */
    public function __construct(
        public readonly int $orderId,
        public readonly string $kanaal,
        public readonly float $omzet,
        public readonly float $kostprijs,
        public readonly float $verpakking,
        public readonly float $verzending,
        public readonly float $betaalkosten,
        public readonly float $commissie,
        public readonly array $perProductgroep,
        public readonly array $waarschuwingen,
        public readonly array $verzendDetail = [],
        public readonly string $marketingkanaal = 'Overig',
    ) {
    }

    public function orderkosten(): float
    {
        return $this->verpakking + $this->verzending + $this->betaalkosten + $this->commissie;
    }

    public function marge(): float
    {
        return $this->omzet - $this->kostprijs - $this->orderkosten();
    }
}
