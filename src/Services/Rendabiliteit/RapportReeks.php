<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Dashed\DashedEcommerceCore\Models\Order;
use Dashed\DashedEcommerceRendabiliteit\Models\VasteLast;

/**
 * Het rendabiliteitsrapport per dag, week of maand voor een vrije periode: omzet, orders,
 * marge, advertenties, vaste lasten en winst, met dezelfde regels als Rendabiliteitsrapport
 * (alle kanalen, alle advertentieplatformen, excl. btw). Vaste lasten van een maand worden
 * gelijk over de dagen van die maand verdeeld, zodat een volle maand optelt tot het rapport.
 * Ordercijfers per dag worden per maand gecachet met de rapportversie (RendabiliteitCache).
 */
class RapportReeks
{
    public const CACHE_SCHEMA = 1;

    public const DAG = 'dag';

    public const WEEK = 'week';

    public const MAAND = 'maand';

    public const PER = [self::DAG => 'Per dag', self::WEEK => 'Per week', self::MAAND => 'Per maand'];

    /** Langste periode (± 3 jaar), zoals het rapport zelf. */
    public const MAX_DAGEN = 1100;

    public function __construct(private AdvertentieBerekenaar $advertenties)
    {
    }

    /** Dag tot ± 2 maanden, week tot ± een half jaar, daarboven maand. */
    public static function standaardPer(CarbonInterface $van, CarbonInterface $tot): string
    {
        $dagen = $van->copy()->startOfDay()->diffInDays($tot->copy()->startOfDay()) + 1;

        return match (true) {
            $dagen <= 62 => self::DAG,
            $dagen <= 190 => self::WEEK,
            default => self::MAAND,
        };
    }

    /**
     * @return list<array{label: string, van: string, tot: string, omzet: float, orders: int, marge: float, margePercentage: ?float, advertenties: float, geschat: float, vasteLasten: float, winst: float}>
     */
    public function reeks(CarbonInterface $van, CarbonInterface $tot, string $per): array
    {
        $start = $van->copy()->startOfDay();
        $eind = $tot->copy()->startOfDay();
        $per = array_key_exists($per, self::PER) ? $per : self::DAG;

        $orderdagen = $this->orderdagen($start, $eind);
        $advertenties = $this->advertenties->perDag($start, $eind);
        $vasteLastenPerMaand = [];

        $emmers = [];

        for ($dag = $start->copy(); $dag->lte($eind); $dag->addDay()) {
            $datum = $dag->toDateString();
            [$sleutel, $label, $emmerVan, $emmerTot] = self::emmer($dag, $per, $start, $eind);

            $emmers[$sleutel] ??= [
                'label' => $label,
                'van' => $emmerVan,
                'tot' => $emmerTot,
                'omzet' => 0.0,
                'orders' => 0,
                'marge' => 0.0,
                'advertenties' => 0.0,
                'geschat' => 0.0,
                'vasteLasten' => 0.0,
            ];
            $emmer = &$emmers[$sleutel];

            if (isset($orderdagen[$datum])) {
                $emmer['omzet'] += $orderdagen[$datum]['omzet'];
                $emmer['marge'] += $orderdagen[$datum]['marge'];
                $emmer['orders'] += $orderdagen[$datum]['orders'];
            }

            foreach ($advertenties as $dagen) {
                if (isset($dagen[$datum])) {
                    $emmer['advertenties'] += $dagen[$datum]['bedrag'];

                    if ($dagen[$datum]['geschat']) {
                        $emmer['geschat'] += $dagen[$datum]['bedrag'];
                    }
                }
            }

            $maand = $dag->format('Y-m');
            $vasteLastenPerMaand[$maand] ??= (float) VasteLast::query()->lopendIn($dag)->sum('bedrag_per_maand');
            $emmer['vasteLasten'] += $vasteLastenPerMaand[$maand] / $dag->daysInMonth;

            unset($emmer);
        }

        return array_map(fn (array $emmer) => self::rondAf($emmer), array_values($emmers));
    }

    /**
     * Totaalrij over een reeks.
     *
     * @param  list<array>  $reeks  uitkomst van reeks()
     * @return array{omzet: float, orders: int, marge: float, margePercentage: ?float, advertenties: float, geschat: float, vasteLasten: float, winst: float}
     */
    public static function totaal(array $reeks): array
    {
        $totaal = ['omzet' => 0.0, 'orders' => 0, 'marge' => 0.0, 'advertenties' => 0.0, 'geschat' => 0.0, 'vasteLasten' => 0.0];

        foreach ($reeks as $emmer) {
            foreach (array_keys($totaal) as $veld) {
                $totaal[$veld] += $emmer[$veld];
            }
        }

        return self::rondAf($totaal);
    }

    /** @return array{0: string, 1: string, 2: string, 3: string}  sleutel, label, van, tot (begrensd op de periode) */
    private static function emmer(CarbonInterface $dag, string $per, CarbonInterface $start, CarbonInterface $eind): array
    {
        [$van, $tot, $label] = match ($per) {
            self::WEEK => [$dag->copy()->startOfWeek(), $dag->copy()->endOfWeek(), 'Week '.$dag->isoWeek().' ('.$dag->copy()->startOfWeek()->locale('nl')->translatedFormat('j F Y').')'],
            self::MAAND => [$dag->copy()->startOfMonth(), $dag->copy()->endOfMonth(), $dag->copy()->locale('nl')->translatedFormat('F Y')],
            default => [$dag->copy(), $dag->copy(), $dag->copy()->locale('nl')->translatedFormat('l j F Y')],
        };

        $van = $van->max($start);
        $tot = $tot->startOfDay()->min($eind);

        return [$van->toDateString(), $label, $van->toDateString(), $tot->toDateString()];
    }

    private static function rondAf(array $rij): array
    {
        foreach (['omzet', 'marge', 'advertenties', 'geschat', 'vasteLasten'] as $veld) {
            $rij[$veld] = round($rij[$veld], 2);
        }

        $rij['margePercentage'] = $rij['omzet'] > 0 ? round($rij['marge'] / $rij['omzet'] * 100, 2) : null;
        $rij['winst'] = round($rij['marge'] - $rij['advertenties'] - $rij['vasteLasten'], 2);

        return $rij;
    }

    /**
     * Omzet, marge en orders per dag ('Y-m-d'), per maand gecachet.
     *
     * @return array<string, array{omzet: float, marge: float, orders: int}>
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
        $sleutel = 'dashed-ecommerce-rendabiliteit.rapportreeks:s'.self::CACHE_SCHEMA.':r'.Rendabiliteitsrapport::CACHE_SCHEMA.':v'.RendabiliteitCache::versie().':'.$maand->format('Y-m');
        $ttl = $maand->copy()->startOfMonth()->gte(Carbon::now()->startOfMonth()) ? 600 : 86400;

        return Cache::remember($sleutel, $ttl, fn () => $this->bouwMaand($maand));
    }

    /** Zelfde selectie en telling als Rendabiliteitsrapport::bouw(): elke betaalde of geretourneerde order telt. */
    private function bouwMaand(CarbonInterface $maand): array
    {
        $berekenaar = app(OrderKostenBerekenaar::class);
        $dagen = [];

        Order::query()
            ->whereIn('status', ['paid', 'return'])
            ->whereBetween('created_at', [$maand->copy()->startOfMonth(), $maand->copy()->endOfMonth()])
            ->with(OrderKostenBerekenaar::relaties())
            ->chunkById(200, function (Collection $orders) use ($berekenaar, &$dagen) {
                $datums = $orders->mapWithKeys(fn (Order $order) => [$order->id => $order->created_at->toDateString()]);

                foreach ($berekenaar->voorOrders($orders) as $kosten) {
                    $datum = $datums->get($kosten->orderId);

                    $dagen[$datum] ??= ['omzet' => 0.0, 'marge' => 0.0, 'orders' => 0];
                    $dagen[$datum]['omzet'] += $kosten->omzet;
                    $dagen[$datum]['marge'] += $kosten->marge();
                    $dagen[$datum]['orders']++;
                }
            });

        return $dagen;
    }
}
