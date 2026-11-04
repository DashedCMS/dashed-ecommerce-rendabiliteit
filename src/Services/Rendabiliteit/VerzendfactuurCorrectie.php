<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Dashed\DashedEcommerceRendabiliteit\Support\Vervoerders;
use Dashed\DashedEcommerceRendabiliteit\Models\Verzendfactuur;

/**
 * Vertaalt verzendfacturen naar een correctiefactor op het geschatte
 * verzendtarief per label (zie OrderKostenBerekenaar). Facturen worden één
 * keer per instantie uit de database geladen; de schatting per factuur wordt
 * daarna gememoïseerd.
 */
class VerzendfactuurCorrectie
{
    /**
     * Zo ver vóór het begin van de factuurperiode kan een label zijn aangemaakt en toch pas in
     * de periode verzonden zijn (verzenddatum = aanmaak track & trace).
     */
    private const VOORLOOP_DAGEN = 60;

    /** @var Collection<int, Verzendfactuur>|null alle facturen, één keer geladen */
    private ?Collection $facturen = null;

    /** @var array<int, float> factuur-id → schatting */
    private array $schattingen = [];

    public function __construct(private Kostentarieven $tarieven)
    {
    }

    /** Som van het verzendtarief over alle labels van de vervoerder met een verzenddatum binnen de factuurperiode. */
    public function schattingVoor(Verzendfactuur $factuur): float
    {
        return $this->schattingen[$factuur->id] ??= $this->berekenSchatting($factuur);
    }

    /** Bedrag ÷ schatting; null als de schatting 0 is. */
    public function factorVoor(Verzendfactuur $factuur): ?float
    {
        $schatting = $this->schattingVoor($factuur);

        if ($schatting <= 0.0) {
            return null;
        }

        return (float) $factuur->bedrag / $schatting;
    }

    /**
     * Factor voor een label van $vervoerder op $datum (de verzenddatum): de factuur waarvan de
     * periode $datum bevat (bij overlap de hoogste id), anders het gewogen
     * gemiddelde over de laatste 3 facturen vóór $datum (of, bij gebrek
     * daaraan, de laatste 3 facturen van de vervoerder), anders 1.
     *
     * @return array{factor: float, bron: 'factuur'|'gemiddelde'|'tarief'}
     */
    public function factorOp(string $vervoerder, CarbonInterface $datum): array
    {
        $facturen = $this->facturenVoor($vervoerder);

        $binnenPeriode = $facturen
            ->filter(fn (Verzendfactuur $f) => $f->periode_van->copy()->startOfDay()->lte($datum)
                && $f->periode_tot->copy()->endOfDay()->gte($datum))
            ->sortByDesc('id')
            ->first();

        if ($binnenPeriode !== null) {
            $factor = $this->factorVoor($binnenPeriode);

            if ($factor !== null) {
                return ['factor' => $factor, 'bron' => 'factuur'];
            }
        }

        // Een factuur zonder schatting (bijv. geen labels in de periode) mag het gemiddelde niet
        // verdunnen: buitensluiten vóór take(3), niet pas bij het optellen.
        $heeftSchatting = fn (Verzendfactuur $f) => $this->schattingVoor($f) > 0.0;

        $voorDatum = $facturen
            ->filter(fn (Verzendfactuur $f) => $f->periode_tot->lt($datum))
            ->filter($heeftSchatting)
            ->sortByDesc('periode_tot')
            ->take(3);

        $kandidaten = $voorDatum->isNotEmpty()
            ? $voorDatum
            : $facturen->filter($heeftSchatting)->sortByDesc('periode_tot')->take(3);

        if ($kandidaten->isNotEmpty()) {
            $sumBedrag = 0.0;
            $sumSchatting = 0.0;

            foreach ($kandidaten as $f) {
                $sumBedrag += (float) $f->bedrag;
                $sumSchatting += $this->schattingVoor($f);
            }

            return ['factor' => $sumBedrag / $sumSchatting, 'bron' => 'gemiddelde'];
        }

        return ['factor' => 1.0, 'bron' => 'tarief'];
    }

    /** @return Collection<int, Verzendfactuur> */
    private function facturenVoor(string $vervoerder): Collection
    {
        $this->facturen ??= Verzendfactuur::all();

        return $this->facturen->where('vervoerder', $vervoerder)->values();
    }

    private function berekenSchatting(Verzendfactuur $factuur): float
    {
        $tabel = Vervoerders::tabellen()[$factuur->vervoerder] ?? null;

        if ($tabel === null) {
            return 0.0;
        }

        $van = $factuur->periode_van->copy()->startOfDay();
        $tot = $factuur->periode_tot->copy()->endOfDay();

        return (float) $this->labelRijen($tabel, $van, $tot)
            ->sum(fn ($rij) => $this->verzendtarief($rij));
    }

    /**
     * Labels waarvan de verzenddatum (Verzendlabel::verzenddatum) in [$van, $tot] valt. Kandidaten
     * komen uit een ruim venster op de aanmaakdatum van het label (VOORLOOP_DAGEN vóór $van tot en
     * met $tot); één query, met de track & trace-rijen van de order er via een left join bij.
     * Alleen labels met een gevulde track & trace tellen mee (Verzendlabel::heeftTrackAndTrace),
     * ongeacht orderstatus of retour/uitgaand: de factuur rekent ook geannuleerde orders en retouren.
     */
    private function labelRijen(string $tabel, CarbonInterface $van, CarbonInterface $tot): Collection
    {
        $rijen = DB::table($tabel)
            ->join('dashed__orders', 'dashed__orders.id', '=', "{$tabel}.order_id")
            ->leftJoin('dashed__order_track_and_traces as tt', 'tt.order_id', '=', "{$tabel}.order_id")
            ->whereBetween("{$tabel}.created_at", [$van->copy()->subDays(self::VOORLOOP_DAGEN), $tot])
            ->select(
                "{$tabel}.id as label_id",
                "{$tabel}.track_and_trace as track_and_trace",
                "{$tabel}.package_type as package_type",
                "{$tabel}.created_at as label_created_at",
                'dashed__orders.country as country',
                'tt.code as tt_code',
                'tt.created_at as tt_created_at'
            )
            ->get();

        return $rijen
            ->filter(fn ($rij) => Verzendlabel::heeftTrackAndTrace($rij->track_and_trace))
            ->groupBy('label_id')
            ->map(function (Collection $perLabel) {
                $label = $perLabel->first();
                $trackAndTraces = $perLabel
                    ->filter(fn ($rij) => $rij->tt_created_at !== null)
                    ->map(fn ($rij) => (object) ['code' => $rij->tt_code, 'created_at' => $rij->tt_created_at]);
                $label->verzenddatum = Verzendlabel::verzenddatum($label->track_and_trace, $label->label_created_at, $trackAndTraces);

                return $label;
            })
            ->filter(fn ($label) => $label->verzenddatum !== null && $label->verzenddatum->between($van, $tot))
            ->values();
    }

    private function verzendtarief(object $rij): float
    {
        $sleutel = Verzendlabel::tariefSleutel($rij->package_type, $rij->country);
        $tarief = $this->tarieven->geldig('verzending', $sleutel, $rij->verzenddatum);

        return $this->tarieven->bedrag($tarief, 0);
    }
}
