<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit;

use Throwable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Dashed\DashedEcommerceCore\Models\Order;
use Dashed\DashedEcommerceRendabiliteit\Support\Vervoerders;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Dashed\DashedEcommerceRendabiliteit\Models\OrderregelKostprijs;
use Dashed\DashedEcommerceRendabiliteit\Instellingen\MargeInstellingen;

/**
 * Rekent per order kostprijs, verpakking, verzending (per label, tarief op
 * verzenddatum maal de verzendfactuurfactor van de vervoerder), betaalkosten (per betaling) en marktplaatscommissie uit.
 * Ontbrekende gegevens tellen als €0 en worden als waarschuwing gemeld.
 */
class OrderKostenBerekenaar
{
    /** Betaalmethodes zonder kosten en zonder waarschuwing (kleine letters). */
    public const KOSTELOOS = ['via bol', 'etsy', 'contant', 'cash', 'gratis', 'verrekening', 'manual_payment', 'pin', ''];

    /** Relaties die de berekening nodig heeft: orderregels, betalingen en de labels van de actieve vervoerders. */
    public static function relaties(): array
    {
        return ['orderProducts', 'orderPayments.paymentMethod', ...array_values(Vervoerders::relaties())];
    }

    private ?bool $bolCommissieInclBtw = null;

    /** @var array<string, array{omschrijving: string, aantal: int, omzet: float}> */
    private array $waarschuwingen = [];

    /** @var array<string, true> codes waarvan de omzet al eenmaal is meegeteld voor de huidige order */
    private array $omzetAlGeteld = [];

    /** @var array<int, true> product-id's van cadeaubonproducten in de huidige batch */
    private array $bonProducten = [];

    /** @var array<string, array{tarief: float, verzending: float, bronnen: array<string, int>}> verzending van de huidige order per vervoerder */
    private array $verzendDetail = [];

    public function __construct(private Kostentarieven $tarieven, private VerzendfactuurCorrectie $correctie)
    {
    }

    public function voorOrder(Order $order): OrderKosten
    {
        return $this->voorOrders(collect([$order]))->first();
    }

    /** @return Collection<int, OrderKosten> */
    public function voorOrders(Collection $orders): Collection
    {
        if ($orders->isEmpty()) {
            return collect();
        }

        $orders = new EloquentCollection($orders->all());
        $orders->loadMissing(self::relaties());

        $regels = $orders->flatMap(fn (Order $order) => $this->regels($order));
        $kostprijzen = OrderregelKostprijs::whereIn('order_product_id', $regels->pluck('id')->all())
            ->pluck('kostprijs', 'order_product_id');

        $productIds = $regels->pluck('product_id')->filter()->unique()->values();
        $productGroepen = $productIds->isEmpty()
            ? collect()
            : DB::table('dashed__products')->whereIn('id', $productIds)->pluck('product_group_id', 'id');

        $this->bonProducten = $this->bonProducten($productIds);

        // Verzenddatum per label (Verzendlabel::verzenddatum): alle track & traces van de batch in één query.
        $trackAndTraces = DB::table('dashed__order_track_and_traces')
            ->whereIn('order_id', $orders->modelKeys())
            ->whereNotNull('created_at')
            ->get(['order_id', 'code', 'created_at'])
            ->groupBy('order_id');

        return $orders->map(function (Order $order) use ($kostprijzen, $productGroepen, $trackAndTraces) {
            try {
                return $this->bereken($order, $kostprijzen, $productGroepen, $trackAndTraces->get($order->id, collect()));
            } catch (Throwable $e) {
                report($e);

                return $this->overgeslagen($order);
            }
        })->values()->toBase();
    }

    private function bereken(Order $order, Collection $kostprijzen, Collection $productGroepen, Collection $trackAndTraces): OrderKosten
    {
        $this->waarschuwingen = [];
        $this->omzetAlGeteld = [];
        $this->verzendDetail = [];

        // Een verkochte cadeaubon is een vooruitbetaling, geen omzet: de omzet komt
        // pas bij het inwisselen, op de order waarmee de bon betaald wordt.
        $bonOmzet = $this->regels($order)
            ->filter(fn ($regel) => $this->isBonregel($regel))
            ->sum(fn ($regel) => (float) $regel->price - (float) $regel->btw);
        $omzet = round((float) $order->total - (float) $order->btw - $bonOmzet, 2);
        $kanaal = Kanalen::voor($order->order_origin);
        $datum = $order->created_at ?? Carbon::now();

        $kostprijs = $this->kostprijs($order, $kostprijzen);
        [$verzending, $verpakking] = $this->verzendkosten($order, $datum, $omzet, $trackAndTraces);
        $betaalkosten = $this->betaalkosten($order, $omzet);
        $commissie = $this->commissie($order, $kanaal, $omzet, $datum);
        $orderkostenTotaal = $verpakking + $verzending + $betaalkosten + $commissie;

        return new OrderKosten(
            orderId: (int) $order->id,
            kanaal: $kanaal,
            omzet: $omzet,
            kostprijs: round($kostprijs, 4),
            verpakking: round($verpakking, 4),
            verzending: round($verzending, 4),
            betaalkosten: round($betaalkosten, 4),
            commissie: round($commissie, 4),
            perProductgroep: $this->perProductgroep($order, $kostprijzen, $productGroepen, $omzet, $orderkostenTotaal),
            waarschuwingen: $this->waarschuwingen,
            verzendDetail: $this->verzendDetail,
            marketingkanaal: Marketingkanalen::voor($order),
        );
    }

    /**
     * Verdeelt de order over productgroepen. De omzet buiten de productregels
     * (verzendbijdrage, regels zonder product) en de orderkosten gaan naar het
     * aandeel van elke groep in de absolute productregel-omzet, zodat de groepen
     * samen de orderomzet en de ordermarge geven. Zonder productomzet valt alles
     * onder groep 0 (zonder productgroep).
     *
     * @return array<int, array{omzet: float, kostprijs: float, orderkosten: float}>
     */
    private function perProductgroep(Order $order, Collection $kostprijzen, Collection $productGroepen, float $omzet, float $orderkostenTotaal): array
    {
        $perGroep = [];

        foreach ($this->productregels($order) as $regel) {
            if ($regel->product_id === null) {
                continue;
            }

            $groepId = (int) ($productGroepen->get((int) $regel->product_id) ?? 0);
            $regelOmzet = (float) $regel->price - (float) $regel->btw;
            $regelKostprijs = $kostprijzen->has($regel->id)
                ? (float) $kostprijzen->get($regel->id) * (int) $regel->quantity
                : 0.0;

            $perGroep[$groepId] ??= ['omzet' => 0.0, 'kostprijs' => 0.0, 'absOmzet' => 0.0];
            $perGroep[$groepId]['omzet'] += $regelOmzet;
            $perGroep[$groepId]['kostprijs'] += $regelKostprijs;
            $perGroep[$groepId]['absOmzet'] += abs($regelOmzet);
        }

        $totaalAbsOmzet = array_sum(array_column($perGroep, 'absOmzet'));

        if ($perGroep === [] || $totaalAbsOmzet === 0.0) {
            return [0 => [
                'omzet' => round($omzet, 2),
                'kostprijs' => round(array_sum(array_column($perGroep, 'kostprijs')), 4),
                'orderkosten' => round($orderkostenTotaal, 4),
            ]];
        }

        // Alles wat niet in de productregels zit; in de praktijk de regels zonder product_id.
        $restOmzet = $omzet - array_sum(array_column($perGroep, 'omzet'));
        $resultaat = [];

        foreach ($perGroep as $groepId => $data) {
            $aandeel = $data['absOmzet'] / $totaalAbsOmzet;
            $resultaat[$groepId] = [
                'omzet' => round($data['omzet'] + $restOmzet * $aandeel, 2),
                'kostprijs' => round($data['kostprijs'], 4),
                'orderkosten' => round($orderkostenTotaal * $aandeel, 4),
            ];
        }

        return $resultaat;
    }

    /**
     * Orderregels zonder de soft-deleted exemplaren: Dashed laadt orderProducts
     * met withTrashed(), maar een verwijderde regel hoort niet meer bij de order.
     */
    private function regels(Order $order): Collection
    {
        return $order->orderProducts->filter(fn ($regel) => $regel->deleted_at === null)->values();
    }

    /** De regels zonder verkochte cadeaubonnen: die hebben geen omzet, kostprijs of productgroep. */
    private function productregels(Order $order): Collection
    {
        return $this->regels($order)->reject(fn ($regel) => $this->isBonregel($regel))->values();
    }

    private function isBonregel(object $regel): bool
    {
        return $regel->product_id !== null && isset($this->bonProducten[(int) $regel->product_id]);
    }

    /**
     * Cadeaubonproducten (eigenschap op product of productgroep, zoals
     * Product::isGiftcardProduct()), ook verwijderde.
     *
     * @return array<int, true>
     */
    private function bonProducten(Collection $productIds): array
    {
        if ($productIds->isEmpty()) {
            return [];
        }

        return DB::table('dashed__products as p')
            ->leftJoin('dashed__product_groups as g', 'g.id', '=', 'p.product_group_id')
            ->whereIn('p.id', $productIds->all())
            ->where(fn ($q) => $q->where('p.is_giftcard_product', 1)->orWhere('g.is_giftcard_product', 1))
            ->pluck('p.id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    private function kostprijs(Order $order, Collection $kostprijzen): float
    {
        $totaal = 0.0;

        foreach ($this->productregels($order) as $regel) {
            if ($regel->product_id === null) {
                continue;
            }

            $quantity = (int) $regel->quantity;

            if ($kostprijzen->has($regel->id)) {
                $totaal += (float) $kostprijzen->get($regel->id) * $quantity;
            } elseif ($quantity !== 0) {
                $this->waarschuw('kostprijs_onbekend', 'Orderregels zonder kostprijs', (float) $regel->price - (float) $regel->btw);
            }
        }

        return $totaal;
    }

    /**
     * Per label: tarief op de verzenddatum (Verzendlabel::verzenddatum: aanmaak van de
     * bijpassende track & trace) × factor van de vervoerder op die datum (VerzendfactuurCorrectie). Verpakking per uitgaand label.
     * Een order zonder label heeft geen verzend- of verpakkingskosten (ophalen, kassa, of de zending
     * hoort bij een andere order in een "order wijzigen"-keten).
     *
     * @return array{0: float, 1: float} [verzending, verpakking]
     */
    private function verzendkosten(Order $order, CarbonInterface $datum, float $omzet, Collection $trackAndTraces): array
    {
        $labels = collect(Vervoerders::relaties())
            ->flatMap(fn (string $relatie, string $vervoerder) => collect($order->{$relatie} ?? [])->map(fn ($label) => [$vervoerder, $label]))
            ->filter(fn (array $paar) => Verzendlabel::heeftTrackAndTrace($paar[1]->getRawOriginal('track_and_trace')));
        $verzending = 0.0;
        $verpakking = 0.0;
        $uitgaand = 0;

        foreach ($labels as [$vervoerder, $label]) {
            $labelDatum = Verzendlabel::verzenddatum($label->getRawOriginal('track_and_trace'), $label->created_at, $trackAndTraces) ?? $datum;
            $tarief = $this->verzendtarief(Verzendlabel::tariefSleutel($label->package_type, $order->country), $labelDatum, $omzet);
            ['factor' => $factor, 'bron' => $bron] = $this->correctie->factorOp($vervoerder, $labelDatum);
            $verzending += $tarief * $factor;
            $this->voegVerzendDetailToe($vervoerder, $tarief, $tarief * $factor, $bron);

            if (! $label->is_return) {
                $uitgaand++;
                $verpakking += $this->verpakkingstarief($labelDatum, $omzet);
            }
        }

        return [$verzending, $verpakking];
    }

    private function voegVerzendDetailToe(string $vervoerder, float $tarief, float $verzending, string $bron): void
    {
        $this->verzendDetail[$vervoerder] ??= ['tarief' => 0.0, 'verzending' => 0.0, 'bronnen' => []];
        $this->verzendDetail[$vervoerder]['tarief'] += $tarief;
        $this->verzendDetail[$vervoerder]['verzending'] += $verzending;
        $this->verzendDetail[$vervoerder]['bronnen'][$bron] = ($this->verzendDetail[$vervoerder]['bronnen'][$bron] ?? 0) + 1;
    }

    private function verzendtarief(string $sleutel, CarbonInterface $datum, float $omzet): float
    {
        $tarief = $this->tarieven->geldig('verzending', $sleutel, $datum);

        if ($tarief === null) {
            $this->waarschuw("geen_verzendtarief:{$sleutel}", "Geen verzendtarief voor {$sleutel}", $omzet, true);
        }

        return $this->tarieven->bedrag($tarief, 0);
    }

    private function verpakkingstarief(CarbonInterface $datum, float $omzet): float
    {
        $tarief = $this->tarieven->geldig('verpakking', 'pakket', $datum);

        if ($tarief === null) {
            $this->waarschuw('geen_verpakkingstarief', 'Geen verpakkingstarief', $omzet, true);
        }

        return $this->tarieven->bedrag($tarief, 0);
    }

    private function betaalkosten(Order $order, float $omzet): float
    {
        $totaal = 0.0;

        foreach ($order->orderPayments as $betaling) {
            // Een terugbetaling (negatief bedrag) of een betaling van € 0 is geen transactie met betaalkosten.
            if ($betaling->status !== 'paid' || (float) $betaling->amount <= 0) {
                continue;
            }

            // Betaald met een cadeaubon: geen transactie bij een betaalprovider.
            if ($betaling->psp === 'giftcard') {
                continue;
            }

            $methode = $this->methodeNaam($betaling);

            if (in_array(mb_strtolower($methode), self::KOSTELOOS, true)) {
                continue;
            }

            $tarief = $this->tarieven->geldig('betaalmethode', $methode, $betaling->created_at ?? $order->created_at ?? Carbon::now());

            if ($tarief === null) {
                $this->waarschuw("geen_betaaltarief:{$methode}", "Geen tarief voor betaalmethode {$methode}", $omzet, true);

                continue;
            }

            $totaal += $this->tarieven->bedrag($tarief, (float) $betaling->amount);
        }

        return $totaal;
    }

    /**
     * De naam zoals op de betaling; Pay.nl-betalingen hebben die vaak niet en alleen een
     * payment_method_id, dan de (Nederlandse) naam van die betaalmethode.
     */
    private function methodeNaam(object $betaling): string
    {
        $methode = trim((string) $betaling->payment_method);

        if ($methode === '' && $betaling->paymentMethod !== null) {
            $methode = trim((string) $betaling->paymentMethod->getTranslation('name', 'nl', true));
        }

        return $methode;
    }

    /** Bij een creditorder (credit_for_order_id of negatieve omzet) komt de commissie terug: negatief. */
    private function commissie(Order $order, string $kanaal, float $omzet, CarbonInterface $datum): float
    {
        $commissie = $this->brutoCommissie($order, $kanaal, $omzet, $datum);
        $isCredit = $order->credit_for_order_id !== null || $omzet < 0;

        return $isCredit ? -abs($commissie) : $commissie;
    }

    private function brutoCommissie(Order $order, string $kanaal, float $omzet, CarbonInterface $datum): float
    {
        if ($kanaal === 'Bol') {
            $commissie = (float) $order->bol_order_commission;

            return $this->bolCommissieInclBtw() ? $commissie / 1.21 : $commissie;
        }

        if ($kanaal === 'Etsy') {
            // dashed-ecommerce-etsy ≥ v4.4.0 zet de transactiekosten (6,5% van het orderbedrag incl. verzending
            // en cadeauverpakking) op de order. Dat is de fee zelf, zonder de btw die Etsy erbovenop factureert:
            // het rapport rekent excl. btw, dus geen ÷ 1,21 zoals bij Bol. Oudere orders zonder bedrag: het tarief.
            if ($order->etsy_order_commission !== null) {
                return abs((float) $order->etsy_order_commission);
            }

            $tarief = $this->tarieven->geldig('etsy_commissie', 'etsy', $datum);

            if ($tarief === null) {
                $this->waarschuw('geen_etsytarief', 'Geen Etsy-commissietarief', $omzet, true);
            }

            return $this->tarieven->bedrag($tarief, abs((float) $order->total));
        }

        return 0.0;
    }

    private function bolCommissieInclBtw(): bool
    {
        return $this->bolCommissieInclBtw ??= MargeInstellingen::bolCommissieInclBtw();
    }

    private function overgeslagen(Order $order): OrderKosten
    {
        return new OrderKosten(
            orderId: (int) $order->id,
            kanaal: Kanalen::voor($order->order_origin),
            omzet: 0.0,
            kostprijs: 0.0,
            verpakking: 0.0,
            verzending: 0.0,
            betaalkosten: 0.0,
            commissie: 0.0,
            perProductgroep: [],
            waarschuwingen: [
                'order_overgeslagen' => ['omschrijving' => 'Orders overgeslagen door een fout', 'aantal' => 1, 'omzet' => 0.0],
            ],
            marketingkanaal: Marketingkanalen::voor($order),
        );
    }

    /**
     * $eenmaligeOmzet: de omzet telt maar één keer mee voor deze code binnen de order,
     * ook als de waarschuwing meerdere keren afgaat (bijv. per label of per betaling).
     */
    private function waarschuw(string $code, string $omschrijving, float $omzet, bool $eenmaligeOmzet = false): void
    {
        $this->waarschuwingen[$code] ??= ['omschrijving' => $omschrijving, 'aantal' => 0, 'omzet' => 0.0];
        $this->waarschuwingen[$code]['aantal']++;

        if ($eenmaligeOmzet && isset($this->omzetAlGeteld[$code])) {
            return;
        }

        $this->omzetAlGeteld[$code] = true;
        $this->waarschuwingen[$code]['omzet'] = round($this->waarschuwingen[$code]['omzet'] + $omzet, 2);
    }
}
