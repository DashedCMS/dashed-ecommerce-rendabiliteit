<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Dashed\DashedEcommerceCore\Models\Product;
use Dashed\DashedEcommerceCore\Models\ProductGroup;
use Dashed\DashedEcommerceRendabiliteit\Support\Maatlabel;
use Dashed\DashedEcommerceRendabiliteit\Support\Vervoerders;
use Dashed\DashedEcommerceRendabiliteit\Models\Verzendfactuur;
use Dashed\DashedEcommerceCore\Filament\Resources\OrderResource;
use Dashed\DashedEcommerceRendabiliteit\Models\RendabiliteitGenegeerd;
use Dashed\DashedEcommerceCore\Filament\Resources\ProductGroupResource;
use Dashed\DashedEcommerceRendabiliteit\Instellingen\MargeInstellingen;
use Dashed\DashedEcommerceRendabiliteit\Services\Kostprijzen\Prijsadvies;
use Dashed\DashedEcommerceRendabiliteit\Filament\Pages\Kostprijzen\MargesPage;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\VasteLasten\VasteLastResource;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Kostentarieven\KostentariefResource;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\Verzendfacturen\VerzendfactuurResource;
use Dashed\DashedEcommerceRendabiliteit\Filament\Resources\AdvertentieUitgaven\AdvertentieUitgaveResource;

/**
 * "Aandachtspunten" boven het rendabiliteitsrapport, voor de gekozen periode:
 * 1. te weinig marge (groep × maat op de echte verkoop, kanalen met verlies),
 * 2. wat mist (kostprijzen, tarieven, verzendfacturen, advertentiedagen, vaste lasten, Bol-commissie),
 * 3. advertenties onder de break-even-ROAS.
 * Omzet en kostprijs per orderregel volgen dezelfde definities als OrderKostenBerekenaar
 * (price − btw, kostprijs × quantity, soft-deleted regels niet); de rest komt uit de
 * maandrapporten. Alle bedragen excl. btw. Gecachet met dezelfde versie als het rapport.
 */
class Aandachtspunten
{
    /** Ophogen bij elke vormwijziging van het resultaat. */
    public const CACHE_SCHEMA = 5;

    /**
     * Negeer-sleutel van de informatieregel over de uitgesloten groepen (rendabiliteit()->sluitGroepenUit(),
     * bewust zonder kostprijs). De waarde is historisch, zodat eerder genegeerde regels genegeerd blijven.
     */
    public const UITGESLOTEN_SLEUTEL = 'kostprijs:outlet';

    /** @deprecated gebruik UITGESLOTEN_SLEUTEL */
    public const OUTLET_SLEUTEL = self::UITGESLOTEN_SLEUTEL;

    /** Hoeveel groep × maat-rijen blok 1 toont (na het negeren); de rest wordt alleen geteld. */
    public const MAX_MARGE_RIJEN = 15;

    /** Vanaf deze maand legt Dashed de attributiekolommen vast; eerder heeft geen enkele order een marketingkanaal. */
    public const ATTRIBUTIE_VANAF = '2026-05';

    private const ORIGIN_PER_KANAAL = ['Webshop' => 'own', 'Bol' => 'bol', 'Etsy' => 'etsy', 'Kassa' => 'pos'];

    public function __construct(private Rendabiliteitsrapport $rapport)
    {
    }

    /**
     * @return array{doelmarge: float, marge: array{rijen: list<array>, rest: int, restTekort: float}, kanalen: list<array>, mist: list<array>, outlet: ?array, advertenties: list<array>, genegeerd: array{marge: list<array>, kanalen: list<array>, mist: list<array>, advertenties: list<array>}}
     */
    public function voorPeriode(CarbonInterface $van, CarbonInterface $tot): array
    {
        $van = Carbon::instance($van)->startOfMonth();
        $tot = Carbon::instance($tot)->startOfMonth();
        // Een bijgewerkte kostprijs (inkoopprijs op het product) verandert blok 2 zonder dat de
        // rapportversie ophoogt. Net als de doelmarge (instelling zonder cache-wis).
        $profielen = DB::table('dashed__products')->max('updated_at').'|'.DB::table('dashed__products')->count();
        $sleutel = 'rendabiliteit:aandachtspunten:s'.self::CACHE_SCHEMA.':r'.Rendabiliteitsrapport::CACHE_SCHEMA
            .':v'.RendabiliteitCache::versie().':'.$van->format('Y-m').':'.$tot->format('Y-m')
            .':'.md5($profielen.'|'.MargeInstellingen::doelmarge());
        $ttl = $tot->gte(Carbon::now()->startOfMonth()) ? 600 : 86400;

        // Negeren werkt ná de cache, zodat (niet meer) negeren meteen zichtbaar is.
        return self::scheidGenegeerd(Cache::remember($sleutel, $ttl, fn () => $this->bouw($van, $tot)));
    }

    /**
     * Haalt genegeerde rijen (tabel rendabiliteit_genegeerd) uit elk blok en zet ze onder
     * 'genegeerd' → blok; blok 1 wordt pas daarna afgekapt op MAX_MARGE_RIJEN, zodat een
     * genegeerde rij plaatsmaakt voor de volgende.
     *
     * @param  array{marge: list<array>, kanalen: list<array>, mist: list<array>, advertenties: list<array>}  $punten
     */
    public static function scheidGenegeerd(array $punten): array
    {
        $blokken = ['marge', 'kanalen', 'mist', 'advertenties'];
        $sleutels = array_merge(...array_map(fn (string $blok) => array_column($punten[$blok], 'sleutel'), $blokken));

        if (! empty($punten['outlet'])) {
            $sleutels[] = self::UITGESLOTEN_SLEUTEL;
        }

        $genegeerd = $sleutels === [] ? collect() : RendabiliteitGenegeerd::query()->whereIn('sleutel', $sleutels)->pluck('sleutel')->flip();
        $punten['genegeerd'] = [];

        if (! empty($punten['outlet'])) {
            $punten['outlet']['genegeerd'] = $genegeerd->has(self::UITGESLOTEN_SLEUTEL);
        }

        foreach ($blokken as $blok) {
            $punten['genegeerd'][$blok] = array_values(array_filter($punten[$blok], fn (array $rij) => $genegeerd->has($rij['sleutel'])));
            $punten[$blok] = array_values(array_filter($punten[$blok], fn (array $rij) => ! $genegeerd->has($rij['sleutel'])));
        }

        $rest = array_slice($punten['marge'], self::MAX_MARGE_RIJEN);
        $punten['marge'] = [
            'rijen' => array_slice($punten['marge'], 0, self::MAX_MARGE_RIJEN),
            'rest' => count($rest),
            'restTekort' => round(array_sum(array_column($rest, 'tekort')), 2),
        ];

        return $punten;
    }

    private function bouw(Carbon $van, Carbon $tot): array
    {
        $rapporten = $this->rapport->periode($van, $tot);
        $regels = $this->regels($van, $tot->copy()->endOfMonth());
        $groepNamen = $this->groepNamen($regels->pluck('product_group_id')->filter()->unique()->all());
        $uitgesloten = array_flip(rendabiliteit()->uitgeslotenGroepIds());
        $doelmarge = MargeInstellingen::doelmarge();

        [$mistKostprijs, $outlet] = $this->mistKostprijs($regels, $groepNamen, $uitgesloten);

        $mist = array_merge(
            $mistKostprijs,
            $this->uitWaarschuwingen($rapporten),
            $this->ontbrekendeVerzendfacturen($van, $tot->copy()->endOfMonth()),
            $this->geschatteAdvertenties($rapporten),
            $this->zonderVasteLasten($rapporten),
            $this->bolZonderCommissie($van, $tot->copy()->endOfMonth()),
        );
        usort($mist, fn (array $a, array $b) => $b['omzet'] <=> $a['omzet']);

        return [
            'doelmarge' => $doelmarge,
            'marge' => $this->teWeinigMarge($regels, $groepNamen, $rapporten, $doelmarge, $uitgesloten),
            'kanalen' => $this->kanalenMetVerlies($rapporten),
            'mist' => $mist,
            'outlet' => $outlet,
            'advertenties' => $this->advertentiesOnderBreakEven($rapporten),
        ];
    }

    /**
     * Orderregels met product van de orders die ook in het rapport tellen (paid/return, maand op besteldatum),
     * met kostprijs per stuk (null = onbekend), groep en maatsleutel.
     *
     * @return Collection<int, object{omzet: float, quantity: int, kostprijs: ?float, vat_rate: float, product_group_id: int, sleutel: string}>
     */
    private function regels(Carbon $start, Carbon $eind): Collection
    {
        // Verkochte cadeaubonnen zijn een betaalmiddel zonder kostprijs (zoals in OrderKostenBerekenaar):
        // ze horen niet bij "mist kostprijs" of "te weinig marge".
        $rijen = DB::table('dashed__order_products as r')
            ->join('dashed__orders as o', 'o.id', '=', 'r.order_id')
            ->leftJoin('orderregel_kostprijzen as k', 'k.order_product_id', '=', 'r.id')
            ->leftJoin('dashed__products as p', 'p.id', '=', 'r.product_id')
            ->leftJoin('dashed__product_groups as g', 'g.id', '=', 'p.product_group_id')
            ->whereIn('o.status', ['paid', 'return'])
            ->whereBetween('o.created_at', [$start, $eind])
            ->whereNull('o.deleted_at')
            ->whereNull('r.deleted_at')
            ->whereNotNull('r.product_id')
            ->where(fn ($q) => $q->whereNull('p.is_giftcard_product')->orWhere('p.is_giftcard_product', 0))
            ->where(fn ($q) => $q->whereNull('g.is_giftcard_product')->orWhere('g.is_giftcard_product', 0))
            ->get(['r.product_id', 'r.quantity', 'r.price', 'r.btw', 'r.vat_rate', 'k.kostprijs', 'p.product_group_id']);

        $producten = $rijen->filter(fn ($rij) => $rij->product_group_id !== null)
            ->unique('product_id')
            ->map(fn ($rij) => (new Product())->forceFill(['id' => (int) $rij->product_id, 'product_group_id' => (int) $rij->product_group_id]));
        $sleutels = rendabiliteit()->maatsleutelsVoor($producten);

        return $rijen->map(fn ($rij) => (object) [
            'omzet' => (float) $rij->price - (float) $rij->btw,
            'quantity' => (int) $rij->quantity,
            'kostprijs' => $rij->kostprijs === null ? null : (float) $rij->kostprijs,
            'vat_rate' => $rij->vat_rate === null ? 21.0 : (float) $rij->vat_rate,
            'product_group_id' => (int) ($rij->product_group_id ?? 0),
            'sleutel' => $sleutels[(int) $rij->product_id] ?? '',
        ]);
    }

    /**
     * Groep × maat met negatieve productmarge of een productmarge onder de doelmarge, alleen over regels
     * mét kostprijs (regels zonder kostprijs staan onder "Wat mist"). Gesorteerd op het tekort t.o.v. de
     * doelmarge (omzet × doelmarge − marge). De marge na orderkosten verdeelt de orderkosten van de groep uit
     * het rapport naar omzetaandeel over de maten.
     */
    /** @param array<int, int> $uitgesloten groep-id → index (uitgesloten groepen tellen niet mee) */
    private function teWeinigMarge(Collection $regels, array $groepNamen, Collection $rapporten, float $doelmarge, array $uitgesloten): array
    {
        $orderkostenRatio = $this->orderkostenRatioPerGroep($rapporten);
        $perRij = [];

        foreach ($regels as $regel) {
            if ($regel->kostprijs === null || $regel->product_group_id === 0 || isset($uitgesloten[$regel->product_group_id])) {
                continue;
            }

            $sleutel = $regel->product_group_id.'|'.$regel->sleutel;
            $perRij[$sleutel] ??= ['groepId' => $regel->product_group_id, 'sleutel' => $regel->sleutel, 'omzet' => 0.0, 'kostprijs' => 0.0, 'aantal' => 0, 'btwGewogen' => 0.0];
            $perRij[$sleutel]['omzet'] += $regel->omzet;
            $perRij[$sleutel]['kostprijs'] += $regel->kostprijs * $regel->quantity;
            $perRij[$sleutel]['aantal'] += $regel->quantity;
            $perRij[$sleutel]['btwGewogen'] += $regel->vat_rate * abs($regel->omzet);
        }

        $rijen = [];

        foreach ($perRij as $data) {
            $omzet = $data['omzet'];
            $marge = $omzet - $data['kostprijs'];

            if ($omzet <= 0 || $data['aantal'] <= 0) {
                continue;
            }

            $margeProcent = $marge / $omzet * 100;

            if ($marge >= 0 && round($margeProcent, 1) >= $doelmarge) {
                continue;
            }

            $btw = $data['btwGewogen'] > 0 ? $data['btwGewogen'] / abs($omzet) : 21.0;
            $kostprijsPerStuk = $data['kostprijs'] / $data['aantal'];
            $adviesIncl = $kostprijsPerStuk > 0 ? Prijsadvies::adviesprijs($kostprijsPerStuk, $btw, $doelmarge) : null;
            $ratio = $orderkostenRatio[$data['groepId']] ?? 0.0;

            $rijen[] = [
                'sleutel' => "marge:{$data['groepId']}:{$data['sleutel']}",
                'groepId' => $data['groepId'],
                'groep' => $groepNamen[$data['groepId']] ?? 'Onbekend',
                'maat' => Maatlabel::voor($data['groepId'], $data['sleutel']),
                'omzet' => round($omzet, 2),
                'aantal' => $data['aantal'],
                'marge' => round($marge, 2),
                'margeProcent' => round($margeProcent, 1),
                'margeNaOrderkosten' => round($marge - $omzet * $ratio, 2),
                'tekort' => round($omzet * $doelmarge / 100 - $marge, 2),
                'gemiddeldePrijs' => round($omzet / $data['aantal'], 2),
                'adviesprijs' => $adviesIncl === null ? null : round($adviesIncl / (1 + $btw / 100), 2),
                'adviesprijsIncl' => $adviesIncl,
                'url' => MargesPage::getUrl(['filters' => ['product_group_id' => ['value' => $data['groepId']]]]),
            ];
        }

        usort($rijen, fn (array $a, array $b) => $b['tekort'] <=> $a['tekort']);

        return $rijen;
    }

    /** @return array<int, float> groep-id → orderkosten ÷ omzet over de periode (uit het rapport) */
    private function orderkostenRatioPerGroep(Collection $rapporten): array
    {
        $som = [];

        foreach ($rapporten as $rapport) {
            foreach ($rapport->perProductgroep as $groepId => $groep) {
                $som[$groepId] ??= ['omzet' => 0.0, 'orderkosten' => 0.0];
                $som[$groepId]['omzet'] += $groep['omzet'];
                $som[$groepId]['orderkosten'] += $groep['orderkosten'];
            }
        }

        return array_map(fn (array $s) => $s['omzet'] > 0 ? $s['orderkosten'] / $s['omzet'] : 0.0, $som);
    }

    /** Verkoopkanalen (per kanaal uit het rapport) met een negatieve marge na orderkosten over de periode. */
    private function kanalenMetVerlies(Collection $rapporten): array
    {
        $som = [];

        foreach ($rapporten as $rapport) {
            foreach ($rapport->perKanaal as $kanaal => $rij) {
                $som[$kanaal] ??= ['omzet' => 0.0, 'marge' => 0.0, 'orders' => 0];
                $som[$kanaal]['omzet'] += $rij['omzet'];
                $som[$kanaal]['marge'] += $rij['marge'];
                $som[$kanaal]['orders'] += $rij['aantalOrders'];
            }
        }

        $rijen = [];

        foreach ($som as $kanaal => $data) {
            if (round($data['marge'], 2) >= 0) {
                continue;
            }

            $origin = self::ORIGIN_PER_KANAAL[$kanaal] ?? null;
            $rijen[] = [
                'sleutel' => "kanaal:{$kanaal}",
                'kanaal' => $kanaal,
                'orders' => $data['orders'],
                'omzet' => round($data['omzet'], 2),
                'marge' => round($data['marge'], 2),
                'url' => OrderResource::getUrl('index', $origin ? ['filters' => ['order_origin' => ['values' => [$origin]]]] : []),
            ];
        }

        usort($rijen, fn (array $a, array $b) => $a['marge'] <=> $b['marge']);

        return $rijen;
    }

    /**
     * Verkochte regels zonder kostprijs per groep × maat, met de reden en een link uit
     * rendabiliteit()->kostprijsLink() (standaard: de productgroep in het CMS). De uitgesloten
     * groepen hebben bewust geen kostprijs: die worden samen één informatieregel.
     *
     * @param  array<int, int>  $uitgesloten  groep-id → index
     * @return array{0: list<array>, 1: ?array}
     */
    private function mistKostprijs(Collection $regels, array $groepNamen, array $uitgesloten): array
    {
        $perRij = [];
        $outlet = null;
        $uitgeslotenGroepen = [];

        foreach ($regels as $regel) {
            if ($regel->kostprijs !== null || $regel->quantity === 0) {
                continue;
            }

            if (isset($uitgesloten[$regel->product_group_id])) {
                // Eigen sleutel zodat ook deze regel genegeerd kan worden (scheidGenegeerd zet 'genegeerd').
                $uitgeslotenGroepen[$regel->product_group_id] = true;
                $naam = count($uitgeslotenGroepen) === 1 ? ($groepNamen[$regel->product_group_id] ?? 'Uitgesloten groepen') : 'Uitgesloten groepen';
                $outlet ??= ['sleutel' => self::UITGESLOTEN_SLEUTEL, 'groep' => $naam, 'titel' => $naam, 'regels' => 0, 'omzet' => 0.0];
                $outlet['groep'] = $outlet['titel'] = $naam;
                $outlet['regels']++;
                $outlet['omzet'] = round($outlet['omzet'] + $regel->omzet, 2);

                continue;
            }

            $sleutel = $regel->product_group_id.'|'.$regel->sleutel;
            $perRij[$sleutel] ??= ['groepId' => $regel->product_group_id, 'sleutel' => $regel->sleutel, 'regels' => 0, 'aantal' => 0, 'omzet' => 0.0];
            $perRij[$sleutel]['regels']++;
            $perRij[$sleutel]['aantal'] += $regel->quantity;
            $perRij[$sleutel]['omzet'] += $regel->omzet;
        }

        $items = [];

        foreach ($perRij as $data) {
            $groepId = $data['groepId'];
            $aantal = $data['aantal'].' '.($data['aantal'] === 1 ? 'stuk' : 'stuks');
            $negeerSleutel = "printprofiel:{$groepId}:{$data['sleutel']}";
            $link = rendabiliteit()->linkVoor($groepId, $data['sleutel']);

            if ($groepId === 0) {
                $items[] = $this->item(
                    $negeerSleutel,
                    'kostprijs',
                    'Producten zonder productgroep',
                    "{$aantal} verkocht zonder kostprijs; ".($link['reden'] ?? 'koppel het product aan een productgroep'),
                    $data['omzet'],
                    $link['url'] ?? null,
                    $link['label'] ?? null,
                ) + ['regels' => $data['regels']];

                continue;
            }

            $reden = $link['reden'] ?? 'geen kostprijs bekend (vul de inkoopprijs van het product in)';

            $items[] = $this->item(
                $negeerSleutel,
                'kostprijs',
                ($groepNamen[$groepId] ?? 'Onbekend').' · '.Maatlabel::voor($groepId, $data['sleutel']),
                "{$aantal} verkocht zonder kostprijs: {$reden}",
                $data['omzet'],
                $link['url'] ?? self::productgroepUrl($groepId),
                $link['label'] ?? 'Productgroep',
            ) + ['regels' => $data['regels']];
        }

        return [$items, $outlet];
    }

    private static function productgroepUrl(int $groepId): ?string
    {
        try {
            return ProductGroupResource::getUrl('edit', ['record' => $groepId]);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Ontbrekende tarieven (betaalmethode, verzending, verpakking, Etsy) en overgeslagen orders uit de rapportwaarschuwingen. */
    private function uitWaarschuwingen(Collection $rapporten): array
    {
        $items = [];

        foreach (PeriodeTotaal::waarschuwingen($rapporten) as $code => $w) {
            [$soort, $sleutel] = array_pad(explode(':', $code, 2), 2, null);
            $tarieven = fn (string $tariefsoort) => KostentariefResource::getUrl('index', ['filters' => ['soort' => ['value' => $tariefsoort]]]);

            $item = match ($soort) {
                'kostprijs_onbekend', 'advertenties_geschat' => null,
                'geen_betaaltarief' => $this->item("betaaltarief:{$sleutel}", 'tarief', "Betaalmethode {$sleutel} zonder tarief", "{$w['aantal']} betalingen tellen als € 0 betaalkosten", $w['omzet'], $tarieven('betaalmethode'), 'Tarieven'),
                'geen_verzendtarief' => $this->item("verzendtarief:{$sleutel}", 'tarief', "Geen verzendtarief voor {$sleutel}", "{$w['aantal']} labels tellen als € 0 verzending", $w['omzet'], $tarieven('verzending'), 'Tarieven'),
                'geen_verpakkingstarief' => $this->item('verpakkingstarief', 'tarief', 'Geen verpakkingstarief', "{$w['aantal']} labels tellen als € 0 verpakking", $w['omzet'], $tarieven('verpakking'), 'Tarieven'),
                'geen_etsytarief' => $this->item('commissie:etsy', 'commissie', 'Etsy-orders zonder commissie en zonder tarief', "{$w['aantal']} orders tellen als € 0 commissie", $w['omzet'], $tarieven('etsy_commissie'), 'Tarieven'),
                default => $this->item("overig:{$code}", 'overig', $w['omschrijving'], "{$w['aantal']} keer", $w['omzet'], null, null),
            };

            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Per vervoerder × verzendmaand waarin labels zonder eigen factuur zitten. Net als het rapport
     * (OrderKostenBerekenaar/VerzendfactuurCorrectie::factorOp) telt de verzenddatum van het label
     * (Verzendlabel::verzenddatum), niet de besteldatum: een label dat weken na de order is verzonden,
     * hoort bij de factuur van de verzendmaand. Een label geldt als geschat als factorOp 'gemiddelde'
     * of 'tarief' geeft (facturen zonder schatting tellen daar al niet mee). De lopende maand telt
     * niet mee: die factuur kan er nog niet zijn.
     */
    private function ontbrekendeVerzendfacturen(Carbon $start, Carbon $eind): array
    {
        $huidige = Carbon::now()->format('Y-m');
        $correctie = app(VerzendfactuurCorrectie::class);
        $orders = DB::table('dashed__orders')
            ->whereIn('status', ['paid', 'return'])
            ->whereBetween('created_at', [$start, $eind])
            ->whereNull('deleted_at')
            ->get(['id', 'created_at', DB::raw('total - btw as omzet')])
            ->keyBy('id');
        $trackAndTraces = DB::table('dashed__order_track_and_traces')
            ->whereIn('order_id', $orders->keys())
            ->whereNotNull('created_at')
            ->get(['order_id', 'code', 'created_at'])
            ->groupBy('order_id');
        $perMaand = [];

        foreach (Vervoerders::tabellen() as $vervoerder => $tabel) {
            $labels = DB::table($tabel)->whereIn('order_id', $orders->keys())->get(['order_id', 'track_and_trace', 'created_at']);

            foreach ($labels as $label) {
                if (! Verzendlabel::heeftTrackAndTrace($label->track_and_trace)) {
                    continue;
                }

                $datum = Verzendlabel::verzenddatum($label->track_and_trace, $label->created_at, $trackAndTraces->get($label->order_id, collect()))
                    ?? Carbon::parse($orders[$label->order_id]->created_at);
                $maand = $datum->format('Y-m');

                if ($maand >= $huidige || $correctie->factorOp($vervoerder, $datum)['bron'] === 'factuur') {
                    continue;
                }

                $perMaand[$vervoerder][$maand] ??= ['labels' => 0, 'orders' => []];
                $perMaand[$vervoerder][$maand]['labels']++;
                $perMaand[$vervoerder][$maand]['orders'][$label->order_id] = (float) $orders[$label->order_id]->omzet;
            }
        }

        $items = [];

        foreach ($perMaand as $vervoerder => $maanden) {
            ksort($maanden);

            foreach ($maanden as $maand => $data) {
                $items[] = $this->item(
                    "verzendfactuur:{$vervoerder}:{$maand}",
                    'verzendfactuur',
                    "Verzendfactuur {$vervoerder} ontbreekt",
                    $data['labels'].' '.($data['labels'] === 1 ? 'label' : 'labels').' verzonden zonder factuur voor die maand: verzending geschat',
                    array_sum($data['orders']),
                    VerzendfactuurResource::getUrl('index', ['filters' => ['vervoerder' => ['value' => $vervoerder]]]),
                    'Verzendfacturen',
                    [$maand],
                );
            }
        }

        return $items;
    }

    /** Dagen zonder geïmporteerde uitgave die met het daggemiddelde zijn geschat, per platform × maand. */
    private function geschatteAdvertenties(Collection $rapporten): array
    {
        $items = [];

        foreach ($rapporten as $rapport) {
            foreach ($rapport->advertentiesDetail as $platform => $detail) {
                if ($detail['dagenGeschat'] <= 0) {
                    continue;
                }

                $items[] = $this->item(
                    "advertenties:{$platform}:{$rapport->maand}",
                    'advertenties',
                    "Advertenties {$platform}: {$detail['dagenGeschat']} dagen geschat",
                    'Geen geïmporteerde dagregel; geschat € '.number_format($detail['geschat'], 2, ',', '.').' met het daggemiddelde',
                    $rapport->totaal['omzet'],
                    AdvertentieUitgaveResource::getUrl('index'),
                    'Advertentie-uitgaven',
                    [$rapport->maand],
                );
            }
        }

        return $items;
    }

    private function zonderVasteLasten(Collection $rapporten): array
    {
        return $rapporten->filter(fn (MaandRapport $r) => $r->vasteLasten == 0)
            ->map(fn (MaandRapport $r) => $this->item(
                "vaste_lasten:{$r->maand}",
                'vaste_lasten',
                'Geen vaste lasten',
                'Maand zonder vaste lasten: de winst is te hoog',
                $r->totaal['omzet'],
                VasteLastResource::getUrl('index'),
                'Vaste lasten',
                [$r->maand],
            ))
            ->values()
            ->all();
    }

    /** Bol-orders (geen creditorders) zonder commissie, per maand: die tellen als € 0. */
    private function bolZonderCommissie(Carbon $start, Carbon $eind): array
    {
        return DB::table('dashed__orders')
            ->whereIn('status', ['paid', 'return'])
            ->whereBetween('created_at', [$start, $eind])
            ->where('order_origin', 'bol')
            ->whereNull('deleted_at')
            ->whereNull('credit_for_order_id')
            ->whereRaw('total - btw > 0')
            ->where(fn ($q) => $q->whereNull('bol_order_commission')->orWhere('bol_order_commission', 0))
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as maand, COUNT(*) as aantal, COALESCE(SUM(total - btw), 0) as omzet")
            ->groupBy('maand')
            ->orderBy('maand')
            ->get()
            ->map(fn ($rij) => $this->item(
                "commissie:bol:{$rij->maand}",
                'commissie',
                'Bol-orders zonder commissie',
                $rij->aantal.((int) $rij->aantal === 1 ? ' order telt' : ' orders tellen').' als € 0 Bol-commissie',
                (float) $rij->omzet,
                OrderResource::getUrl('index', ['filters' => ['order_origin' => ['values' => ['bol']]]]),
                'Orders',
                [$rij->maand],
            ))
            ->all();
    }

    /**
     * Maand × marketingkanaal met advertentie-uitgave waar de ROAS onder de break-even-ROAS zit
     * (of de marge ≤ 0 is, dan is er geen break-even), met het tekort = uitgave − marge. Maanden vóór
     * ATTRIBUTIE_VANAF tellen niet mee: daar heeft geen order een klik-ID, dus elke uitgave zou verlies lijken.
     */
    private function advertentiesOnderBreakEven(Collection $rapporten): array
    {
        $platformPerKanaal = [Marketingkanalen::GOOGLE => 'Google', Marketingkanalen::META => 'Meta'];
        $rijen = [];

        foreach ($rapporten as $rapport) {
            if ($rapport->maand < self::ATTRIBUTIE_VANAF) {
                continue;
            }

            foreach ($rapport->perMarketingkanaal as $kanaal => $rij) {
                if ($rij['advertenties'] <= 0) {
                    continue;
                }

                $onder = $rij['breakEvenRoas'] === null || ($rij['roas'] !== null && $rij['roas'] < $rij['breakEvenRoas']);

                if (! $onder) {
                    continue;
                }

                $rijen[] = [
                    'sleutel' => "roas:{$kanaal}:{$rapport->maand}",
                    'maand' => $rapport->maand,
                    'kanaal' => $kanaal,
                    'advertenties' => $rij['advertenties'],
                    'deelsGeschat' => ($rapport->advertentiesDetail[$platformPerKanaal[$kanaal] ?? '']['dagenGeschat'] ?? 0) > 0,
                    'omzet' => $rij['omzet'],
                    'marge' => $rij['marge'],
                    'roas' => $rij['roas'],
                    'breakEvenRoas' => $rij['breakEvenRoas'],
                    'tekort' => round($rij['advertenties'] - $rij['marge'], 2),
                    'url' => AdvertentieUitgaveResource::getUrl('index', ['filters' => ['maand' => ['value' => $rapport->maand]]]),
                ];
            }
        }

        usort($rijen, fn (array $a, array $b) => $b['tekort'] <=> $a['tekort']);

        return $rijen;
    }

    /**
     * @param  string  $sleutel  stabiele sleutel voor "Negeren" (bijv. printprofiel:{groep}:{opties}, verzendfactuur:{vervoerder}:{Y-m})
     * @param  list<string>  $maanden  'Y-m'
     */
    private function item(string $sleutel, string $soort, string $titel, string $toelichting, float $omzet, ?string $url, ?string $linkLabel, array $maanden = []): array
    {
        return [
            'sleutel' => $sleutel,
            'soort' => $soort,
            'titel' => $titel,
            'toelichting' => $toelichting,
            'omzet' => round($omzet, 2),
            'maanden' => $maanden,
            'url' => $url,
            'linkLabel' => $linkLabel,
        ];
    }

    /** @return array<int, string> */
    private function groepNamen(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return ProductGroup::withoutGlobalScopes()->whereIn('id', $ids)->get()
            ->mapWithKeys(fn (ProductGroup $groep) => [(int) $groep->id => (string) $groep->getTranslation('name', 'nl', false)])
            ->all();
    }
}
