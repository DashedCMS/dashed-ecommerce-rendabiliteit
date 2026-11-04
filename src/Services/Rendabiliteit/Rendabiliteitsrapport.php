<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Dashed\DashedEcommerceCore\Models\Order;
use Dashed\DashedEcommerceCore\Models\ProductGroup;
use Dashed\DashedEcommerceRendabiliteit\Models\VasteLast;

/**
 * Bouwt en cachet het rendabiliteitsrapport per maand: omzet en kosten uit
 * OrderKostenBerekenaar, plus advertenties, vaste lasten, winst en break-even.
 * Cache per maand op sleutel 'rendabiliteit:s<schema>:v<versie>:<Y-m>'; RendabiliteitCache::wis()
 * maakt alle maanden ongeldig door de versie te verhogen.
 */
class Rendabiliteitsrapport
{
    /** Ophogen bij elke vormwijziging van MaandRapport of andere berekening (5: perMarketingkanaal, 6: advertentiesDetail zonder 'bron', 7: audit 29-09: betaalde proforma, betaalmethode via id, order-wijzigen-ketens, 8: hele keten terug en kassaproforma met verzendmethode geschat, 9: Etsy-commissie op de order, 10: geen schatting meer voor orders zonder label, 11: verkochte cadeaubonnen geen omzet, bonbetalingen zonder betaalkosten), zodat oude gecachete objecten niet meer gelezen worden. */
    public const CACHE_SCHEMA = 11;

    private const VELDEN = ['omzet', 'kostprijs', 'verpakking', 'verzending', 'betaalkosten', 'commissie', 'marge'];

    public function maand(CarbonInterface $maand): MaandRapport
    {
        $sleutel = 'rendabiliteit:s'.self::CACHE_SCHEMA.':v'.RendabiliteitCache::versie().':'.$maand->format('Y-m');
        $ttl = $maand->copy()->startOfMonth()->gte(Carbon::now()->startOfMonth()) ? 600 : 86400;

        return Cache::remember($sleutel, $ttl, fn () => $this->bouw($maand));
    }

    /** @return Collection<int, MaandRapport> */
    public function periode(CarbonInterface $van, CarbonInterface $tot): Collection
    {
        $rapporten = collect();
        $huidige = $van->copy()->startOfMonth();
        $laatste = $tot->copy()->startOfMonth();

        while ($huidige->lte($laatste)) {
            $rapporten->push($this->maand($huidige));
            $huidige = $huidige->copy()->addMonthNoOverflow();
        }

        return $rapporten;
    }

    private function bouw(CarbonInterface $maand): MaandRapport
    {
        $start = $maand->copy()->startOfMonth();
        $eind = $maand->copy()->endOfMonth();

        $berekenaar = app(OrderKostenBerekenaar::class);

        $totaal = $this->leegTotaal();
        $perKanaal = [];
        $perProductgroep = [];
        $waarschuwingen = [];
        $verzendDetail = [];
        $perMarketingkanaal = [];

        Order::query()
            // Ook betaalde proforma-orders (maatwerk via kassa + betaallink): echte verkoop met factuurnummer.
            // Een onbetaalde proforma staat op 'concept' en valt al buiten deze statussen.
            ->whereIn('status', ['paid', 'return'])
            ->whereBetween('created_at', [$start, $eind])
            ->with(OrderKostenBerekenaar::relaties())
            ->chunkById(200, function (Collection $orders) use ($berekenaar, &$totaal, &$perKanaal, &$perProductgroep, &$waarschuwingen, &$verzendDetail, &$perMarketingkanaal) {
                $statussen = $orders->pluck('status', 'id');
                $vervangen = $orders->whereNotNull('replaced_by_order_id')->pluck('id')->flip();

                foreach ($berekenaar->voorOrders($orders) as $kosten) {
                    $this->voegToe($totaal, $kosten);

                    $perKanaal[$kosten->kanaal] ??= $this->leegTotaal();
                    $this->voegToe($perKanaal[$kosten->kanaal], $kosten);

                    $this->voegProductgroepToe($perProductgroep, $kosten);
                    $this->voegWaarschuwingenToe($waarschuwingen, $kosten->waarschuwingen);
                    $this->voegVerzendDetailToe($verzendDetail, $kosten->verzendDetail);
                    $telt = $statussen->get($kosten->orderId) === 'paid' && ! $vervangen->has($kosten->orderId);
                    $this->voegMarketingkanaalToe($perMarketingkanaal, $kosten, $telt);
                }
            });

        $margePercentageRaw = $this->margePercentage($totaal['marge'], $totaal['omzet']);

        $advertentiesDetail = app(AdvertentieBerekenaar::class)->voorMaand($maand);
        $advertentiesPerPlatform = array_map(
            fn (array $detail) => round($detail['werkelijk'] + $detail['geschat'], 2),
            $advertentiesDetail,
        );
        $advertenties = round(array_sum($advertentiesPerPlatform), 2);
        $this->voegAdvertentieWaarschuwingenToe($waarschuwingen, $advertentiesDetail);
        $vasteLasten = round((float) VasteLast::query()->lopendIn($maand)->sum('bedrag_per_maand'), 2);
        $winst = round($totaal['marge'] - $advertenties - $vasteLasten, 2);
        $breakEven = $margePercentageRaw > 0
            ? round(($advertenties + $vasteLasten) / ($margePercentageRaw / 100), 2)
            : null;

        $this->rondAf($totaal);

        foreach ($perKanaal as &$rij) {
            $this->rondAf($rij);
        }
        unset($rij);

        $perProductgroep = $this->metNaamEnMarge($perProductgroep);

        return new MaandRapport(
            maand: $maand->format('Y-m'),
            totaal: $totaal,
            perKanaal: $perKanaal,
            perProductgroep: $perProductgroep,
            advertenties: $advertenties,
            advertentiesPerPlatform: $advertentiesPerPlatform,
            advertentiesDetail: $advertentiesDetail,
            vasteLasten: $vasteLasten,
            winst: $winst,
            breakEven: $breakEven,
            waarschuwingen: $waarschuwingen,
            verzendDetail: $this->rondVerzendDetailAf($verzendDetail),
            perMarketingkanaal: $this->metAdvertentiesEnRoas($perMarketingkanaal, $advertentiesDetail),
        );
    }

    /**
     * Orders = betaalde orders met omzet > 0, zonder vervangen originelen ("order wijzigen": origineel,
     * creditorder en vervanger zijn samen één verkoop); omzet en marge tellen alle orders (ook retouren en creditorders).
     */
    private function voegMarketingkanaalToe(array &$perMarketingkanaal, OrderKosten $kosten, bool $teltAlsOrder): void
    {
        $perMarketingkanaal[$kosten->marketingkanaal] ??= ['omzet' => 0.0, 'orders' => 0, 'marge' => 0.0];
        $perMarketingkanaal[$kosten->marketingkanaal]['omzet'] += $kosten->omzet;
        $perMarketingkanaal[$kosten->marketingkanaal]['marge'] += $kosten->marge();

        if ($teltAlsOrder && $kosten->omzet > 0) {
            $perMarketingkanaal[$kosten->marketingkanaal]['orders']++;
        }
    }

    /**
     * Koppelt de advertentie-uitgave van Google aan "Google Ads" en die van Meta aan "Meta Ads"
     * (andere kanalen €0); ROAS = omzet ÷ advertenties, break-even-ROAS = omzet ÷ marge
     * (de ROAS waarbij de uitgave de marge precies opeet). Google Ads, Meta Ads en Google + Meta
     * staan er altijd, de rest alleen met orders; volgorde volgens Marketingkanalen::VOLGORDE.
     *
     * @param  array<string, array{omzet: float, orders: int, marge: float}>  $perMarketingkanaal
     * @param  array<string, array{werkelijk: float, geschat: float, dagenGeschat: int}>  $advertentiesDetail
     * @return array<string, array{omzet: float, orders: int, marge: float, advertenties: float, roas: ?float, breakEvenRoas: ?float}>
     */
    private function metAdvertentiesEnRoas(array $perMarketingkanaal, array $advertentiesDetail): array
    {
        $platformPerKanaal = [Marketingkanalen::GOOGLE => 'Google', Marketingkanalen::META => 'Meta'];
        $altijd = [Marketingkanalen::GOOGLE, Marketingkanalen::META, Marketingkanalen::BEIDE];
        $volgorde = array_flip(Marketingkanalen::VOLGORDE);

        foreach ($altijd as $kanaal) {
            $perMarketingkanaal[$kanaal] ??= ['omzet' => 0.0, 'orders' => 0, 'marge' => 0.0];
        }

        uksort($perMarketingkanaal, fn (string $a, string $b) => ($volgorde[$a] ?? PHP_INT_MAX) <=> ($volgorde[$b] ?? PHP_INT_MAX) ?: strcmp($a, $b));

        $resultaat = [];

        foreach ($perMarketingkanaal as $kanaal => $data) {
            $detail = $advertentiesDetail[$platformPerKanaal[$kanaal] ?? ''] ?? null;
            $advertenties = $detail === null ? 0.0 : round($detail['werkelijk'] + $detail['geschat'], 2);
            $omzet = round($data['omzet'], 2);
            $marge = round($data['marge'], 2);

            $resultaat[$kanaal] = [
                'omzet' => $omzet,
                'orders' => $data['orders'],
                'marge' => $marge,
                'advertenties' => $advertenties,
                'roas' => $advertenties > 0 ? round($data['omzet'] / $advertenties, 2) : null,
                'breakEvenRoas' => $data['marge'] > 0 ? round($data['omzet'] / $data['marge'], 2) : null,
            ];
        }

        return $resultaat;
    }

    /** @param  array<string, array{tarief: float, verzending: float, bronnen: array<string, int>}>  $nieuw */
    private function voegVerzendDetailToe(array &$verzendDetail, array $nieuw): void
    {
        foreach ($nieuw as $vervoerder => $data) {
            $verzendDetail[$vervoerder] ??= ['tarief' => 0.0, 'verzending' => 0.0, 'bronnen' => []];
            $verzendDetail[$vervoerder]['tarief'] += $data['tarief'];
            $verzendDetail[$vervoerder]['verzending'] += $data['verzending'];

            foreach ($data['bronnen'] as $bron => $aantal) {
                $verzendDetail[$vervoerder]['bronnen'][$bron] = ($verzendDetail[$vervoerder]['bronnen'][$bron] ?? 0) + $aantal;
            }
        }
    }

    /**
     * Factor = gecorrigeerde verzending ÷ ongecorrigeerd tarief (null bij tarief 0);
     * bron = de enige bron van alle labels, anders 'gemengd'.
     *
     * @param  array<string, array{tarief: float, verzending: float, bronnen: array<string, int>}>  $verzendDetail
     * @return array<string, array{tarief: float, verzending: float, factor: ?float, bron: string}>
     */
    private function rondVerzendDetailAf(array $verzendDetail): array
    {
        ksort($verzendDetail);

        return array_map(fn (array $data) => [
            'tarief' => round($data['tarief'], 2),
            'verzending' => round($data['verzending'], 2),
            'factor' => $data['tarief'] > 0 ? round($data['verzending'] / $data['tarief'], 4) : null,
            'bron' => count($data['bronnen']) === 1 ? (string) array_key_first($data['bronnen']) : 'gemengd',
        ], $verzendDetail);
    }

    /** @return array{omzet: float, kostprijs: float, verpakking: float, verzending: float, betaalkosten: float, commissie: float, marge: float, margePercentage: float, aantalOrders: int} */
    private function leegTotaal(): array
    {
        return array_merge(array_fill_keys(self::VELDEN, 0.0), ['margePercentage' => 0.0, 'aantalOrders' => 0]);
    }

    private function voegToe(array &$rij, OrderKosten $kosten): void
    {
        $rij['omzet'] += $kosten->omzet;
        $rij['kostprijs'] += $kosten->kostprijs;
        $rij['verpakking'] += $kosten->verpakking;
        $rij['verzending'] += $kosten->verzending;
        $rij['betaalkosten'] += $kosten->betaalkosten;
        $rij['commissie'] += $kosten->commissie;
        $rij['marge'] += $kosten->marge();
        $rij['aantalOrders']++;
    }

    private function rondAf(array &$rij): void
    {
        $rij['margePercentage'] = round($this->margePercentage($rij['marge'], $rij['omzet']), 2);

        foreach (self::VELDEN as $veld) {
            $rij[$veld] = round($rij[$veld], 2);
        }
    }

    private function margePercentage(float $marge, float $omzet): float
    {
        return $omzet > 0 ? $marge / $omzet * 100 : 0.0;
    }

    /** @param  array<int, array{omzet: float, kostprijs: float, orderkosten: float}>  $perProductgroep */
    private function voegProductgroepToe(array &$perProductgroep, OrderKosten $kosten): void
    {
        foreach ($kosten->perProductgroep as $groepId => $data) {
            $perProductgroep[$groepId] ??= ['omzet' => 0.0, 'kostprijs' => 0.0, 'orderkosten' => 0.0];
            $perProductgroep[$groepId]['omzet'] += $data['omzet'];
            $perProductgroep[$groepId]['kostprijs'] += $data['kostprijs'];
            $perProductgroep[$groepId]['orderkosten'] += $data['orderkosten'];
        }
    }

    /** @param  array<string, array{omschrijving: string, aantal: int, omzet: float}>  $nieuw */
    private function voegWaarschuwingenToe(array &$waarschuwingen, array $nieuw): void
    {
        foreach ($nieuw as $code => $data) {
            $waarschuwingen[$code] ??= ['omschrijving' => $data['omschrijving'], 'aantal' => 0, 'omzet' => 0.0];
            $waarschuwingen[$code]['aantal'] += $data['aantal'];
            $waarschuwingen[$code]['omzet'] = round($waarschuwingen[$code]['omzet'] + $data['omzet'], 2);
        }
    }

    /**
     * @param  array<string, array{omschrijving: string, aantal: int, omzet: float}>  $waarschuwingen
     * @param  array<string, array{werkelijk: float, geschat: float, dagenGeschat: int}>  $advertentiesDetail
     */
    private function voegAdvertentieWaarschuwingenToe(array &$waarschuwingen, array $advertentiesDetail): void
    {
        foreach ($advertentiesDetail as $platform => $detail) {
            if ($detail['dagenGeschat'] > 0) {
                $waarschuwingen['advertenties_geschat:'.$platform] = [
                    'omschrijving' => "Advertenties {$platform}: {$detail['dagenGeschat']} dagen geschat met het daggemiddelde",
                    'aantal' => $detail['dagenGeschat'],
                    'omzet' => 0.0,
                ];
            }
        }
    }

    /**
     * @param  array<int, array{omzet: float, kostprijs: float, orderkosten: float}>  $perProductgroep
     * @return array<int, array{naam: string, omzet: float, kostprijs: float, orderkosten: float, marge: float}>
     */
    private function metNaamEnMarge(array $perProductgroep): array
    {
        $ids = array_filter(array_keys($perProductgroep), fn (int $id) => $id !== 0);
        $namen = $ids === []
            ? collect()
            : ProductGroup::query()->whereIn('id', $ids)->get()
                ->mapWithKeys(fn (ProductGroup $groep) => [(int) $groep->id => (string) $groep->getTranslation('name', 'nl', false)]);

        $resultaat = [];

        foreach ($perProductgroep as $groepId => $data) {
            $resultaat[$groepId] = [
                'naam' => $groepId === 0 ? 'Zonder productgroep' : ($namen->get($groepId) ?? 'Onbekend'),
                'omzet' => round($data['omzet'], 2),
                'kostprijs' => round($data['kostprijs'], 2),
                'orderkosten' => round($data['orderkosten'], 2),
                'marge' => round($data['omzet'] - $data['kostprijs'] - $data['orderkosten'], 2),
            ];
        }

        return $resultaat;
    }
}
