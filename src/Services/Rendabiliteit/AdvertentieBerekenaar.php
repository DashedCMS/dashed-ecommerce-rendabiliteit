<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Dashed\DashedEcommerceRendabiliteit\Models\Kostentarief;
use Dashed\DashedEcommerceRendabiliteit\Models\AdvertentieUitgave;

/**
 * Advertentie-uitgaven per platform voor één maand: werkelijke dagregels (Google, Meta) plus
 * een schatting met het daggemiddelde voor de resterende dagen tot en met vandaag (excl. btw).
 */
class AdvertentieBerekenaar
{
    /** @var array<string, list<Kostentarief>>|null  genormaliseerd platform → dagtarieven aflopend op geldig_vanaf */
    private ?array $dagtarieven = null;

    /** @return array<string, array{werkelijk: float, geschat: float, dagenGeschat: int}> */
    public function voorMaand(CarbonInterface $maand): array
    {
        $resultaat = [];

        foreach ($this->perDag($maand->copy()->startOfMonth(), $maand->copy()->endOfMonth()) as $platform => $dagen) {
            $werkelijk = 0.0;
            $geschat = 0.0;
            $dagenGeschat = 0;

            foreach ($dagen as $dag) {
                if ($dag['geschat']) {
                    $geschat += $dag['bedrag'];
                    $dagenGeschat++;
                } else {
                    $werkelijk += $dag['bedrag'];
                }
            }

            $resultaat[$platform] = [
                'werkelijk' => round($werkelijk, 2),
                'geschat' => round($geschat, 2),
                'dagenGeschat' => $dagenGeschat,
            ];
        }

        return $resultaat;
    }

    /**
     * Uitgave per platform per dag: de werkelijke dagregel, anders (tot en met vandaag) het
     * geldige daggemiddelde als schatting. Platformen zonder enig bedrag vallen weg.
     *
     * @return array<string, array<string, array{bedrag: float, geschat: bool}>>  platform → datum ('Y-m-d') → dag
     */
    public function perDag(CarbonInterface $van, CarbonInterface $tot): array
    {
        $start = $van->copy()->startOfDay();
        $eind = $tot->copy()->startOfDay();
        $vandaag = Carbon::now()->startOfDay();

        $dagregelsPerPlatform = $this->dagregelsPerPlatform($start, $eind);

        $platformen = collect()
            ->merge($dagregelsPerPlatform->keys())
            ->merge(array_keys($this->dagtarieven()))
            ->unique()
            ->sort()
            ->values();

        $resultaat = [];

        foreach ($platformen as $platform) {
            $dagregels = $dagregelsPerPlatform->get($platform, collect());
            $dagen = [];
            $totaal = 0.0;

            for ($dag = $start->copy(); $dag->lte($eind); $dag->addDay()) {
                $rij = $dagregels->get($dag->toDateString());

                if ($rij !== null) {
                    $dagen[$dag->toDateString()] = ['bedrag' => (float) $rij->bedrag, 'geschat' => false];
                    $totaal += (float) $rij->bedrag;

                    continue;
                }

                if ($dag->gt($vandaag)) {
                    continue;
                }

                $tarief = $this->geldigDagtarief($platform, $dag);

                if ($tarief !== null) {
                    $dagen[$dag->toDateString()] = ['bedrag' => (float) $tarief->vast_bedrag, 'geschat' => true];
                    $totaal += (float) $tarief->vast_bedrag;
                }
            }

            if ($totaal !== 0.0) {
                $resultaat[$platform] = $dagen;
            }
        }

        return $resultaat;
    }

    /** @return Collection<string, Collection<string, AdvertentieUitgave>> platform → (datum → rij) */
    private function dagregelsPerPlatform(CarbonInterface $start, CarbonInterface $eind): Collection
    {
        return AdvertentieUitgave::query()
            ->whereBetween('datum', [$start->toDateString(), $eind->toDateString()])
            ->get()
            ->groupBy(fn (AdvertentieUitgave $rij) => AdvertentiePlatformen::normaliseer($rij->platform))
            ->map(fn (Collection $rijen) => $rijen->keyBy(fn (AdvertentieUitgave $rij) => $rij->datum->toDateString()));
    }

    /**
     * Dagtarieven per genormaliseerde platformnaam, zodat ook een sleutel als "facebook"
     * (buiten het formulier opgeslagen) voor Meta telt.
     *
     * @return array<string, list<Kostentarief>>
     */
    private function dagtarieven(): array
    {
        return $this->dagtarieven ??= Kostentarief::query()
            ->where('soort', 'advertentie_dag')
            ->orderByDesc('geldig_vanaf')
            // Bij een gelijke geldig_vanaf (zelfde genormaliseerde platform) wint de laatst toegevoegde rij.
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (Kostentarief $tarief) => AdvertentiePlatformen::normaliseer($tarief->sleutel))
            ->map(fn (Collection $rijen) => $rijen->values()->all())
            ->all();
    }

    private function geldigDagtarief(string $platform, CarbonInterface $dag): ?Kostentarief
    {
        foreach ($this->dagtarieven()[$platform] ?? [] as $tarief) {
            if ($tarief->geldig_vanaf->lte($dag->copy()->startOfDay())) {
                return $tarief;
            }
        }

        return null;
    }
}
