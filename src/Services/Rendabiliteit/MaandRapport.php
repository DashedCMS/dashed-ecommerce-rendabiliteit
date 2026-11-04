<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit;

/** Eén maand van het rendabiliteitsrapport, alle bedragen excl. btw. */
final class MaandRapport
{
    /**
     * @param  string  $maand  'Y-m'
     * @param  array{omzet: float, kostprijs: float, verpakking: float, verzending: float, betaalkosten: float, commissie: float, marge: float, margePercentage: float, aantalOrders: int}  $totaal
     * @param  array<string, array{omzet: float, kostprijs: float, verpakking: float, verzending: float, betaalkosten: float, commissie: float, marge: float, margePercentage: float, aantalOrders: int}>  $perKanaal  kanaal → zelfde sleutels als $totaal
     * @param  array<int, array{naam: string, omzet: float, kostprijs: float, orderkosten: float, marge: float}>  $perProductgroep  groep-id (0 = zonder productgroep)
     * @param  array<string, float>  $advertentiesPerPlatform
     * @param  array<string, array{werkelijk: float, geschat: float, dagenGeschat: int}>  $advertentiesDetail
     * @param  array<string, array{omschrijving: string, aantal: int, omzet: float}>  $waarschuwingen
     * @param  array<string, array{tarief: float, verzending: float, factor: ?float, bron: 'factuur'|'gemiddelde'|'tarief'|'gemengd'}>  $verzendDetail  vervoerder → ongecorrigeerde schatting, gecorrigeerde verzending, gemiddelde factor
     * @param  array<string, array{omzet: float, orders: int, marge: float, advertenties: float, roas: ?float, breakEvenRoas: ?float}>  $perMarketingkanaal  last-click-kanaal (Marketingkanalen::VOLGORDE) → omzet, betaalde orders, marge na orderkosten, advertenties, ROAS en break-even-ROAS
     */
    public function __construct(
        public readonly string $maand,
        public readonly array $totaal,
        public readonly array $perKanaal,
        public readonly array $perProductgroep,
        public readonly float $advertenties,
        public readonly array $advertentiesPerPlatform,
        public readonly array $advertentiesDetail,
        public readonly float $vasteLasten,
        public readonly float $winst,
        public readonly ?float $breakEven,
        public readonly array $waarschuwingen,
        public readonly array $verzendDetail = [],
        public readonly array $perMarketingkanaal = [],
    ) {
    }

    /**
     * Samenvattende bron van de verzending over de vervoerders: null als er
     * geen labels zijn.
     *
     * @return array{bron: 'factuur'|'gemiddelde'|'tarief'|'gemengd', factor: ?float}|null
     */
    public function verzendBron(): ?array
    {
        $vervoerders = $this->verzendDetail;

        if ($vervoerders === []) {
            return null;
        }

        $bronnen = array_unique(array_column($vervoerders, 'bron'));
        $tarief = array_sum(array_column($vervoerders, 'tarief'));

        return [
            'bron' => count($bronnen) === 1 ? reset($bronnen) : 'gemengd',
            'factor' => $tarief > 0 ? array_sum(array_column($vervoerders, 'verzending')) / $tarief : null,
        ];
    }
}
