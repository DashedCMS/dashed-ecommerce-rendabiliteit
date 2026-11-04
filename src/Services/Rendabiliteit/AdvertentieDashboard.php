<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Dashed\DashedEcommerceCore\Models\Order;

/**
 * Cijfers voor het advertentiedashboard: advertentie-uitgaven per platform tegenover de
 * webshopomzet en -marge (na orderkosten, excl. btw), per dag, week of maand.
 * Alleen webshoporders tellen (Bol, kassa en Etsy draaien niet op Meta/Google);
 * omzet, marge en orders volgen dezelfde regels als het rendabiliteitsrapport.
 * Orderdagen worden per maand gecachet met de rapportversie (RendabiliteitCache).
 */
class AdvertentieDashboard
{
    public const CACHE_SCHEMA = 1;

    public const DAG = 'dag';

    public const WEEK = 'week';

    public const MAAND = 'maand';

    public const KWARTAAL = 'kwartaal';

    public const JAAR = 'jaar';

    /** Platform → marketingkanaal waaraan zijn uitgave wordt toegeschreven. */
    public const KANAAL_PER_PLATFORM = ['Google' => Marketingkanalen::GOOGLE, 'Meta' => Marketingkanalen::META];

    /** Platformen die niet voor de webshop adverteren (Bol-ads verkopen op bol.com) en hier dus niet meetellen. */
    public const NIET_WEBSHOP = ['Bol'];

    public function __construct(private AdvertentieBerekenaar $advertenties)
    {
    }

    /** Dag tot ± 2 maanden, week tot ± een half jaar, daarboven maand. */
    public static function standaardGroepering(CarbonInterface $van, CarbonInterface $tot): string
    {
        $dagen = $van->copy()->startOfDay()->diffInDays($tot->copy()->startOfDay()) + 1;

        return match (true) {
            $dagen <= 62 => self::DAG,
            $dagen <= 190 => self::WEEK,
            default => self::MAAND,
        };
    }

    /**
     * @return list<array{label: string, van: string, tot: string, uitgaven: array<string, float>, geschat: array<string, int>, omzet: float, marge: float, orders: int, kanalen: array<string, array{omzet: float, marge: float, orders: int}>}>
     */
    public function reeks(CarbonInterface $van, CarbonInterface $tot, string $groepering): array
    {
        $start = $van->copy()->startOfDay();
        $eind = $tot->copy()->startOfDay();
        $orderdagen = $this->orderdagen($start, $eind);
        $uitgaven = array_diff_key($this->advertenties->perDag($start, $eind), array_flip(self::NIET_WEBSHOP));

        $emmers = [];

        for ($dag = $start->copy(); $dag->lte($eind); $dag->addDay()) {
            $datum = $dag->toDateString();
            [$sleutel, $label, $emmerVan, $emmerTot] = $this->emmer($dag, $groepering, $start, $eind);

            $emmers[$sleutel] ??= [
                'label' => $label,
                'van' => $emmerVan,
                'tot' => $emmerTot,
                'uitgaven' => array_fill_keys(array_keys($uitgaven), 0.0),
                'geschat' => array_fill_keys(array_keys($uitgaven), 0),
                'omzet' => 0.0,
                'marge' => 0.0,
                'orders' => 0,
                'kanalen' => [],
            ];
            $emmer = &$emmers[$sleutel];

            foreach ($uitgaven as $platform => $dagen) {
                if (isset($dagen[$datum])) {
                    $emmer['uitgaven'][$platform] += $dagen[$datum]['bedrag'];
                    $emmer['geschat'][$platform] += $dagen[$datum]['geschat'] ? 1 : 0;
                }
            }

            $cijfers = $orderdagen[$datum] ?? null;

            if ($cijfers !== null) {
                $emmer['omzet'] += $cijfers['omzet'];
                $emmer['marge'] += $cijfers['marge'];
                $emmer['orders'] += $cijfers['orders'];

                foreach ($cijfers['kanalen'] as $kanaal => $kanaalCijfers) {
                    $emmer['kanalen'][$kanaal] ??= ['omzet' => 0.0, 'marge' => 0.0, 'orders' => 0];
                    $emmer['kanalen'][$kanaal]['omzet'] += $kanaalCijfers['omzet'];
                    $emmer['kanalen'][$kanaal]['marge'] += $kanaalCijfers['marge'];
                    $emmer['kanalen'][$kanaal]['orders'] += $kanaalCijfers['orders'];
                }
            }

            unset($emmer);
        }

        return array_map(fn (array $emmer) => $this->rondAf($emmer), array_values($emmers));
    }

    /**
     * Totaal over een reeks, met de afgeleide kengetallen.
     *
     * @param  list<array>  $reeks  uitkomst van reeks()
     * @return array{uitgaven: float, perPlatform: array<string, array{uitgaven: float, dagenGeschat: int, omzet: float, marge: float, orders: int, roas: ?float, breakEvenRoas: ?float, kostenPerOrder: ?float}>, omzet: float, marge: float, orders: int, mer: ?float, breakEvenRoas: ?float, netto: float, kostenPerOrder: ?float, toegeschrevenOmzet: float, kanalen: array<string, array{omzet: float, marge: float, orders: int}>}
     */
    public static function totaal(array $reeks): array
    {
        $uitgavenPerPlatform = [];
        $geschat = [];
        $kanalen = [];
        $omzet = 0.0;
        $marge = 0.0;
        $orders = 0;

        foreach ($reeks as $emmer) {
            foreach ($emmer['uitgaven'] as $platform => $bedrag) {
                $uitgavenPerPlatform[$platform] = ($uitgavenPerPlatform[$platform] ?? 0.0) + $bedrag;
                $geschat[$platform] = ($geschat[$platform] ?? 0) + $emmer['geschat'][$platform];
            }

            foreach ($emmer['kanalen'] as $kanaal => $cijfers) {
                $kanalen[$kanaal] ??= ['omzet' => 0.0, 'marge' => 0.0, 'orders' => 0];
                $kanalen[$kanaal]['omzet'] += $cijfers['omzet'];
                $kanalen[$kanaal]['marge'] += $cijfers['marge'];
                $kanalen[$kanaal]['orders'] += $cijfers['orders'];
            }

            $omzet += $emmer['omzet'];
            $marge += $emmer['marge'];
            $orders += $emmer['orders'];
        }

        $perPlatform = [];

        foreach ($uitgavenPerPlatform as $platform => $bedrag) {
            $kanaal = $kanalen[self::KANAAL_PER_PLATFORM[$platform] ?? ''] ?? ['omzet' => 0.0, 'marge' => 0.0, 'orders' => 0];

            $perPlatform[$platform] = [
                'uitgaven' => round($bedrag, 2),
                'dagenGeschat' => $geschat[$platform],
                'omzet' => round($kanaal['omzet'], 2),
                'marge' => round($kanaal['marge'], 2),
                'orders' => $kanaal['orders'],
                'roas' => self::deel($kanaal['omzet'], $bedrag),
                'breakEvenRoas' => $kanaal['marge'] > 0 ? self::deel($kanaal['omzet'], $kanaal['marge']) : null,
                'kostenPerOrder' => self::deel($bedrag, $kanaal['orders']),
            ];
        }

        $uitgaven = array_sum($uitgavenPerPlatform);
        $toegeschreven = array_sum(array_map(
            fn (string $kanaal) => $kanalen[$kanaal]['omzet'] ?? 0.0,
            [Marketingkanalen::GOOGLE, Marketingkanalen::META, Marketingkanalen::BEIDE],
        ));

        return [
            'uitgaven' => round($uitgaven, 2),
            'perPlatform' => $perPlatform,
            'omzet' => round($omzet, 2),
            'marge' => round($marge, 2),
            'orders' => $orders,
            'mer' => self::deel($omzet, $uitgaven),
            'breakEvenRoas' => $marge > 0 ? self::deel($omzet, $marge) : null,
            'netto' => round($marge - $uitgaven, 2),
            'kostenPerOrder' => self::deel($uitgaven, $orders),
            'toegeschrevenOmzet' => round($toegeschreven, 2),
            'kanalen' => array_map(fn (array $c) => ['omzet' => round($c['omzet'], 2), 'marge' => round($c['marge'], 2), 'orders' => $c['orders']], $kanalen),
        ];
    }

    /** MER boven break-even × deze buffer = gezond; ertussen = krap; eronder = verlies. */
    public const GEZOND_BUFFER = 1.2;

    /**
     * Per soort overzicht: hoeveel perioden minstens in beeld, met hoeveel volle perioden ervoor
     * elke periode vergeleken wordt (en de richting: laatste N tegen de N ervoor), en hoeveel
     * perioden terug dezelfde periode vorig jaar ligt (null = geen jaarvergelijking).
     */
    public const OVERZICHT = [
        self::WEEK => ['toon' => 12, 'trend' => 4, 'jaarTerug' => null],
        self::MAAND => ['toon' => 12, 'trend' => 3, 'jaarTerug' => 12],
        self::KWARTAAL => ['toon' => 8, 'trend' => 2, 'jaarTerug' => 4],
        self::JAAR => ['toon' => 4, 'trend' => 1, 'jaarTerug' => null],
    ];

    /** Weken in het voortschrijdend gemiddelde waarmee elke week vergeleken wordt. */
    public const TREND_WEKEN = 4;

    public static function weekoverzicht(array $reeks): array
    {
        return self::overzicht($reeks, self::WEEK);
    }

    /** Dag van de eerste order: vroeger valt er niets te rekenen. */
    public static function eersteOrderdag(): ?CarbonInterface
    {
        $eerste = Cache::remember('advertentie-dashboard:eerste-orderdag', now()->addDay(), fn () => Order::min('created_at'));

        return $eerste ? Carbon::parse($eerste)->startOfDay() : null;
    }

    /** Begin van de periode (week, maand, kwartaal of jaar) waarin $dag valt. */
    public static function periodeBegin(CarbonInterface $dag, string $per): CarbonInterface
    {
        return match ($per) {
            self::MAAND => $dag->copy()->startOfMonth(),
            self::KWARTAAL => $dag->copy()->startOfQuarter(),
            self::JAAR => $dag->copy()->startOfYear(),
            default => $dag->copy()->startOfWeek(),
        };
    }

    public static function periodenTerug(CarbonInterface $begin, string $per, int $aantal): CarbonInterface
    {
        return match ($per) {
            self::MAAND => $begin->copy()->subMonthsNoOverflow($aantal),
            self::KWARTAAL => $begin->copy()->subMonthsNoOverflow(3 * $aantal),
            self::JAAR => $begin->copy()->subYears($aantal),
            default => $begin->copy()->subWeeks($aantal),
        };
    }

    /**
     * Overzicht per week, maand, kwartaal of jaar met de gezondheid per periode en de richting:
     * winst (marge min advertenties) tegenover het gemiddelde van de N volle perioden ervoor,
     * en bij maand en kwartaal tegenover dezelfde periode vorig jaar (seizoen). Een lopende
     * periode telt niet mee in de trend. Perioden vóór $vanaf dienen alleen als vergelijking,
     * perioden van vóór de eerste omzet of uitgave vallen weg.
     *
     * @param  list<array>  $reeks  uitkomst van reeks() met dezelfde groepering, oudste eerst
     * @return array{rijen: list<array>, laatste: ?float, ervoor: ?float, richting: ?string, trend: int}
     */
    public static function overzicht(array $reeks, string $per = self::WEEK, ?CarbonInterface $vanaf = null): array
    {
        $instelling = self::OVERZICHT[$per] ?? self::OVERZICHT[self::WEEK];
        $trend = $instelling['trend'];
        $rijen = [];
        $winsten = [];
        $perBegin = [];
        $begonnen = false;

        foreach ($reeks as $emmer) {
            $uitgaven = round(array_sum($emmer['uitgaven']), 2);
            $winst = round($emmer['marge'] - $uitgaven, 2);
            $mer = self::deel($emmer['omzet'], $uitgaven);
            $breakEven = $emmer['marge'] > 0 ? self::deel($emmer['omzet'], $emmer['marge']) : null;
            $van = Carbon::parse($emmer['van']);
            $begin = self::periodeBegin($van, $per);
            $vol = $begin->isSameDay($van)
                && self::periodenTerug($begin, $per, -1)->subDay()->isSameDay(Carbon::parse($emmer['tot']));

            $vergelijk = array_slice($winsten, -$trend);
            $vorigJaar = null;

            if ($instelling['jaarTerug'] !== null && $vol) {
                $eerder = $perBegin[self::periodenTerug($begin, $per, $instelling['jaarTerug'])->toDateString()] ?? null;
                $vorigJaar = $eerder !== null && $eerder['vol'] ? round($winst - $eerder['winst'], 2) : null;
            }

            $rij = [
                'label' => $emmer['label'],
                'van' => $emmer['van'],
                'tot' => $emmer['tot'],
                'vol' => $vol,
                'uitgaven' => $uitgaven,
                'geschat' => array_sum($emmer['geschat']),
                'omzet' => $emmer['omzet'],
                'orders' => $emmer['orders'],
                'marge' => $emmer['marge'],
                'winst' => $winst,
                'winstProcent' => $emmer['omzet'] > 0 ? round($winst / $emmer['omzet'] * 100, 1) : null,
                'mer' => $mer,
                'breakEven' => $breakEven,
                'kostenPerOrder' => self::deel($uitgaven, $emmer['orders']),
                'gezondheid' => self::gezondheid($emmer['marge'], $uitgaven, $mer, $breakEven),
                'tovGemiddelde' => count($vergelijk) === $trend ? round($winst - array_sum($vergelijk) / $trend, 2) : null,
                'tovVorigJaar' => $vorigJaar,
            ];
            $perBegin[$begin->toDateString()] = $rij;

            // Perioden van vóór de eerste omzet of uitgave (bijv. jaren zonder data) blijven weg.
            $begonnen = $begonnen || $emmer['omzet'] != 0 || $uitgaven != 0;
            $leeg = ! $begonnen;

            if (($vanaf === null || $van->gte($vanaf)) && ! $leeg) {
                $rijen[] = $rij;
            }

            if ($vol && ! $leeg) {
                $winsten[] = $winst;
            }
        }

        $laatste = count($winsten) >= $trend ? round(array_sum(array_slice($winsten, -$trend)) / $trend, 2) : null;
        $ervoor = count($winsten) >= 2 * $trend ? round(array_sum(array_slice($winsten, -2 * $trend, $trend)) / $trend, 2) : null;
        $richting = null;

        if ($laatste !== null && $ervoor !== null) {
            // Binnen 5% van het oude niveau (minstens € 25) telt als gelijk gebleven.
            $marge = max(25, abs($ervoor) * 0.05);
            $richting = match (true) {
                $laatste > $ervoor + $marge => 'beter',
                $laatste < $ervoor - $marge => 'slechter',
                default => 'gelijk',
            };
        }

        return ['rijen' => array_reverse($rijen), 'laatste' => $laatste, 'ervoor' => $ervoor, 'richting' => $richting, 'trend' => $trend];
    }

    /** @return 'gezond'|'krap'|'verlies'|'geen' */
    public static function gezondheid(float $marge, float $uitgaven, ?float $mer, ?float $breakEven): string
    {
        if ($uitgaven <= 0) {
            return $marge > 0 ? 'gezond' : 'geen';
        }

        if ($marge - $uitgaven < 0 || $mer === null || $breakEven === null) {
            return 'verlies';
        }

        return $mer >= $breakEven * self::GEZOND_BUFFER ? 'gezond' : 'krap';
    }

    public static function deel(float $teller, float $noemer): ?float
    {
        return $noemer > 0 ? round($teller / $noemer, 2) : null;
    }

    /**
     * Webshopcijfers per dag ('Y-m-d') in de periode, per maand gecachet.
     *
     * @return array<string, array{omzet: float, marge: float, orders: int, kanalen: array<string, array{omzet: float, marge: float, orders: int}>}>
     */
    private function orderdagen(CarbonInterface $van, CarbonInterface $tot): array
    {
        $dagen = [];
        $maand = $van->copy()->startOfMonth();

        while ($maand->lte($tot)) {
            $dagen += $this->maand($maand);
            $maand = $maand->copy()->addMonthNoOverflow();
        }

        return $dagen;
    }

    private function maand(CarbonInterface $maand): array
    {
        $sleutel = 'advertentiedashboard:s'.self::CACHE_SCHEMA.':v'.RendabiliteitCache::versie().':'.$maand->format('Y-m');
        $ttl = $maand->copy()->startOfMonth()->gte(Carbon::now()->startOfMonth()) ? 600 : 86400;

        return Cache::remember($sleutel, $ttl, fn () => $this->bouwMaand($maand));
    }

    private function bouwMaand(CarbonInterface $maand): array
    {
        $berekenaar = app(OrderKostenBerekenaar::class);
        $dagen = [];

        Order::query()
            ->whereIn('status', ['paid', 'return'])
            ->where('order_origin', 'own')
            ->whereBetween('created_at', [$maand->copy()->startOfMonth(), $maand->copy()->endOfMonth()])
            ->with(OrderKostenBerekenaar::relaties())
            ->chunkById(200, function (Collection $orders) use ($berekenaar, &$dagen) {
                $perId = $orders->keyBy('id');

                foreach ($berekenaar->voorOrders($orders) as $kosten) {
                    if ($kosten->kanaal !== 'Webshop') {
                        continue;
                    }

                    $order = $perId->get($kosten->orderId);
                    $datum = $order->created_at->toDateString();
                    // Zoals het rapport: een order = betaald, omzet > 0 en geen vervangen origineel ("order wijzigen").
                    $telt = $order->status === 'paid' && $order->replaced_by_order_id === null && $kosten->omzet > 0 ? 1 : 0;

                    $dagen[$datum] ??= ['omzet' => 0.0, 'marge' => 0.0, 'orders' => 0, 'kanalen' => []];
                    $dagen[$datum]['omzet'] += $kosten->omzet;
                    $dagen[$datum]['marge'] += $kosten->marge();
                    $dagen[$datum]['orders'] += $telt;

                    $kanaal = $kosten->marketingkanaal;
                    $dagen[$datum]['kanalen'][$kanaal] ??= ['omzet' => 0.0, 'marge' => 0.0, 'orders' => 0];
                    $dagen[$datum]['kanalen'][$kanaal]['omzet'] += $kosten->omzet;
                    $dagen[$datum]['kanalen'][$kanaal]['marge'] += $kosten->marge();
                    $dagen[$datum]['kanalen'][$kanaal]['orders'] += $telt;
                }
            });

        return $dagen;
    }

    /** @return array{0: string, 1: string, 2: string, 3: string}  sleutel, label, van, tot (begrensd op de periode) */
    private function emmer(CarbonInterface $dag, string $groepering, CarbonInterface $start, CarbonInterface $eind): array
    {
        [$van, $tot, $label] = match ($groepering) {
            self::WEEK => [$dag->copy()->startOfWeek(), $dag->copy()->endOfWeek(), 'wk '.$dag->isoWeek().' '.$dag->copy()->startOfWeek()->translatedFormat('j M')],
            self::MAAND => [$dag->copy()->startOfMonth(), $dag->copy()->endOfMonth(), $dag->translatedFormat('M Y')],
            self::KWARTAAL => [$dag->copy()->startOfQuarter(), $dag->copy()->endOfQuarter(), 'K'.$dag->quarter.' '.$dag->year],
            self::JAAR => [$dag->copy()->startOfYear(), $dag->copy()->endOfYear(), (string) $dag->year],
            default => [$dag->copy(), $dag->copy(), $dag->translatedFormat('D j M')],
        };

        $van = $van->max($start);
        $tot = $tot->startOfDay()->min($eind);

        return [$van->toDateString(), $label, $van->toDateString(), $tot->toDateString()];
    }

    private function rondAf(array $emmer): array
    {
        $emmer['uitgaven'] = array_map(fn (float $bedrag) => round($bedrag, 2), $emmer['uitgaven']);
        $emmer['omzet'] = round($emmer['omzet'], 2);
        $emmer['marge'] = round($emmer['marge'], 2);
        $emmer['kanalen'] = array_map(fn (array $c) => ['omzet' => round($c['omzet'], 2), 'marge' => round($c['marge'], 2), 'orders' => $c['orders']], $emmer['kanalen']);

        return $emmer;
    }
}
