<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\Koppelingen;

use Throwable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Dashed\DashedEcommerceRendabiliteit\Models\AdvertentieUitgave;
use Dashed\DashedEcommerceRendabiliteit\Instellingen\KoppelingInstellingen;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\AdvertentieApiOpslag;

/**
 * Haalt de uitgaven op van elke koppeling die aan staat en is ingevuld. Een platform
 * dat faalt laat de rest door. Een gelukte dag zonder uitgaven wordt € 0, anders vult
 * het rapport hem met een schatting. Handmatige regels blijven staan.
 */
class AdvertentieKoppelingen
{
    public const BRON = 'koppeling';

    public const STANDAARD_DAGEN = 7;

    public function __construct(private AdvertentieApiOpslag $opslag)
    {
    }

    /** @return list<AdvertentieKoppeling> */
    public function alle(): array
    {
        return [new MetaKoppeling(), new GoogleKoppeling(), new BolKoppeling()];
    }

    /**
     * Zonder datums: de afgelopen 7 volle dagen, want platforms corrigeren recente dagen nog.
     *
     * @return array<string, array{gelukt: bool, fout: ?string, dagen: int, totaal: float}> per platform
     */
    public function haalOp(?CarbonImmutable $van = null, ?CarbonImmutable $tot = null, ?string $sleutel = null): array
    {
        $tot ??= CarbonImmutable::yesterday();
        $van ??= $tot->subDays(self::STANDAARD_DAGEN - 1);
        $uitkomst = [];

        foreach ($this->alle() as $koppeling) {
            if ($sleutel !== null && $koppeling->sleutel() !== $sleutel) {
                continue;
            }

            if (! KoppelingInstellingen::aan($koppeling->sleutel()) || ! $koppeling->isIngesteld()) {
                continue;
            }

            $uitkomst[$koppeling->platform()] = $this->verwerk($koppeling, $van, $tot);
            KoppelingInstellingen::zetStand($koppeling->sleutel(), ['at' => now()->toIso8601String()] + $uitkomst[$koppeling->platform()]);
        }

        return $uitkomst;
    }

    /**
     * Test de inloggegevens van één koppeling: vraagt gisteren op, ook als ophalen uit staat,
     * en slaat niets op.
     *
     * @return array{gelukt: bool, melding: string}
     */
    public function controleer(string $sleutel): array
    {
        $koppeling = collect($this->alle())->first(fn (AdvertentieKoppeling $k) => $k->sleutel() === $sleutel);

        if (! $koppeling) {
            return ['gelukt' => false, 'melding' => 'Onbekende koppeling.'];
        }

        $leeg = self::lege($sleutel);

        if (! $koppeling->isIngesteld() || $leeg !== []) {
            return ['gelukt' => false, 'melding' => 'Nog niet ingevuld: '.implode(', ', $leeg ?: ['verplichte velden']).'.'];
        }

        $gisteren = CarbonImmutable::yesterday();

        try {
            $uitgaven = $koppeling->uitgaven($gisteren, $gisteren);
        } catch (KoppelingFout $fout) {
            return ['gelukt' => false, 'melding' => $fout->getMessage()];
        } catch (Throwable $fout) {
            report($fout);

            return ['gelukt' => false, 'melding' => 'Onverwachte fout: '.$fout->getMessage()];
        }

        $bedrag = number_format(round(array_sum($uitgaven), 2), 2, ',', '.');
        $nacht = KoppelingInstellingen::aan($sleutel) ? '' : ' Let op: "Elke nacht ophalen" staat nog uit.';

        return ['gelukt' => true, 'melding' => "De verbinding werkt. Uitgaven gisteren ({$gisteren->format('d-m-Y')}): € {$bedrag}.{$nacht}"];
    }

    /** Verplichte velden die nog leeg zijn (labels). @return list<string> */
    public static function lege(string $sleutel): array
    {
        $optioneel = ['google_login_customer_id'];
        $leeg = [];

        foreach (KoppelingPrompt::VELDEN[$sleutel] ?? [] as $veld => [$label, $geheim]) {
            if (in_array($veld, $optioneel, true)) {
                continue;
            }

            $waarde = $geheim ? KoppelingInstellingen::geheim($veld) : KoppelingInstellingen::waarde($veld);

            if ($waarde === null) {
                $leeg[] = $label;
            }
        }

        return $leeg;
    }

    /** @return array{gelukt: bool, fout: ?string, dagen: int, totaal: float} */
    private function verwerk(AdvertentieKoppeling $koppeling, CarbonImmutable $van, CarbonImmutable $tot): array
    {
        $grens = $koppeling->vroegsteDag();
        $van = $grens && $van->lt($grens) ? $grens : $van;

        try {
            $uitgaven = $koppeling->uitgaven($van, $tot);
        } catch (KoppelingFout $fout) {
            return $this->mislukt($koppeling, $fout->getMessage());
        } catch (Throwable $fout) {
            report($fout);

            return $this->mislukt($koppeling, 'Onverwachte fout, zie de log.');
        }

        $handmatig = AdvertentieUitgave::query()
            ->where('platform', $koppeling->platform())
            ->where('bron', 'handmatig')
            ->whereBetween('datum', [$van->toDateString(), $tot->toDateString()])
            ->pluck('datum')
            ->map(fn ($datum) => $datum->toDateString())
            ->all();

        $regels = [];
        $totaal = 0.0;

        for ($dag = $van; $dag->lte($tot); $dag = $dag->addDay()) {
            $datum = $dag->toDateString();

            if (in_array($datum, $handmatig, true)) {
                continue;
            }

            $bedrag = round($uitgaven[$datum] ?? 0.0, 2);
            $regels[] = ['datum' => $datum, 'platform' => $koppeling->platform(), 'bedrag' => $bedrag];
            $totaal += $bedrag;
        }

        $this->opslag->sla($regels, self::BRON);

        return ['gelukt' => true, 'fout' => null, 'dagen' => count($regels), 'totaal' => round($totaal, 2)];
    }

    /** @return array{gelukt: bool, fout: ?string, dagen: int, totaal: float} */
    private function mislukt(AdvertentieKoppeling $koppeling, string $fout): array
    {
        Log::warning("Advertentiekoppeling {$koppeling->platform()}: {$fout}");

        return ['gelukt' => false, 'fout' => $fout, 'dagen' => 0, 'totaal' => 0.0];
    }
}
