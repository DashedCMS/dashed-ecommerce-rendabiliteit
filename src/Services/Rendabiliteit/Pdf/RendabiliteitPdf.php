<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\Pdf;

use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use Illuminate\Support\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\MaandRapport;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\PeriodeTotaal;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\Rendabiliteitsrapport;

/**
 * PDF-export van het rendabiliteitsrapport in vier soorten: één maand in detail, een periode
 * (maand per rij, optioneel met detail per maand), twee maanden/periodes naast elkaar en de
 * progressie per maand met staafgrafieken. Rekent zelf niets: alles komt uit de (gecachete)
 * MaandRapporten en PeriodeTotaal, net als op de rapportpagina. Alle bedragen excl. btw.
 */
class RendabiliteitPdf
{
    public const SOORTEN = [
        'maand' => 'Per maand',
        'periode' => 'Periode',
        'vergelijken' => 'Vergelijken',
        'progressie' => 'Progressie',
    ];

    /** Zelfde grens als de rapportpagina: elke maand is een eigen berekening. */
    public const MAX_MAANDEN = 36;

    /** Hoogte van het staafgebied van een grafiek in px (zonder de ruimte voor de labels). */
    private const GRAFIEK_HOOGTE = 110;

    /** Ruimte boven/onder de staven voor het bedrag. */
    private const LABEL_RUIMTE = 13;

    /** Kostenposten: een daling is gunstig. */
    private const KOSTEN = ['kostprijs', 'verpakking', 'verzending', 'betaalkosten', 'commissie', 'advertenties', 'vasteLasten'];

    public function __construct(private readonly Rendabiliteitsrapport $rapport)
    {
    }

    /**
     * Zet de invoer van het exportformulier om naar de gegevens voor de PDF.
     * Maanden als 'Y-m'; een omgekeerde periode wordt rechtgezet.
     *
     * @param  array{maand?: string, van?: string, tot?: string, met_detail?: bool, a_van?: string, a_tot?: string, b_van?: string, b_tot?: string}  $invoer
     */
    public function gegevens(string $soort, array $invoer): array
    {
        switch ($soort) {
            case 'maand':
                return $this->maand($this->parseMaand($invoer['maand'] ?? ''));
            case 'periode':
                [$van, $tot] = $this->parsePeriode($invoer['van'] ?? '', $invoer['tot'] ?? '');

                return $this->periode($van, $tot, (bool) ($invoer['met_detail'] ?? false));
            case 'vergelijken':
                [$aVan, $aTot] = $this->parsePeriode($invoer['a_van'] ?? '', $invoer['a_tot'] ?? '');
                [$bVan, $bTot] = $this->parsePeriode($invoer['b_van'] ?? '', $invoer['b_tot'] ?? '');

                return $this->vergelijken($aVan, $aTot, $bVan, $bTot);
            case 'progressie':
                [$van, $tot] = $this->parsePeriode($invoer['van'] ?? '', $invoer['tot'] ?? '');

                return $this->progressie($van, $tot);
            default:
                throw new InvalidArgumentException("Onbekende PDF-soort: {$soort}");
        }
    }

    public function maand(CarbonInterface $maand): array
    {
        $rapport = $this->rapport->maand($maand->copy()->startOfMonth());

        return [
            'soort' => 'maand',
            'titel' => self::SOORTEN['maand'],
            'periode' => PeriodeLabel::van($maand, $maand),
            'bestand' => 'maand-'.$rapport->maand,
            'orientatie' => 'portrait',
            'rapport' => $rapport,
            'waarschuwingen' => PeriodeTotaal::waarschuwingen(collect([$rapport])),
        ];
    }

    public function periode(CarbonInterface $van, CarbonInterface $tot, bool $metDetail = false): array
    {
        $rapporten = $this->rapport->periode($van, $tot);

        return [
            'soort' => 'periode',
            'titel' => self::SOORTEN['periode'],
            'periode' => PeriodeLabel::van($van, $tot),
            'bestand' => 'periode-'.$van->format('Y-m').'-tot-'.$tot->format('Y-m'),
            'orientatie' => 'landscape',
            'rapporten' => $rapporten,
            'totaal' => PeriodeTotaal::totaal($rapporten),
            'breakEvenGemiddeld' => PeriodeTotaal::breakEvenGemiddeld($rapporten),
            'waarschuwingen' => PeriodeTotaal::waarschuwingen($rapporten),
            'metDetail' => $metDetail,
        ];
    }

    /** A en B zijn elk een maand (van = tot) of een periode; verschil = B − A, procent t.o.v. A. */
    public function vergelijken(CarbonInterface $aVan, CarbonInterface $aTot, CarbonInterface $bVan, CarbonInterface $bTot): array
    {
        $a = $this->rapport->periode($aVan, $aTot);
        $b = $this->rapport->periode($bVan, $bTot);
        $totaalA = PeriodeTotaal::totaal($a);
        $totaalB = PeriodeTotaal::totaal($b);

        $regels = [];

        foreach ([
            'aantalOrders' => 'Orders',
            'omzet' => 'Omzet',
            'kostprijs' => 'Kostprijs',
            'verpakking' => 'Verpakking',
            'verzending' => 'Verzending',
            'betaalkosten' => 'Betaalkosten',
            'commissie' => 'Commissie',
            'marge' => 'Marge',
            'margePercentage' => 'Marge %',
            'advertenties' => 'Advertenties',
            'vasteLasten' => 'Vaste lasten',
            'winst' => 'Winst',
        ] as $veld => $label) {
            $verschil = self::verschil($totaalA[$veld], $totaalB[$veld]);
            $regels[$veld] = [
                'label' => $label,
                'soort' => match ($veld) {
                    'aantalOrders' => 'aantal',
                    'margePercentage' => 'punten',
                    default => 'geld',
                },
                'a' => $totaalA[$veld],
                'b' => $totaalB[$veld],
                'verschil' => $verschil['bedrag'],
                // Bij marge % is het verschil al in procentpunten; een procent daarvan zegt niets.
                'procent' => $veld === 'margePercentage' ? null : $verschil['procent'],
                'gunstig' => self::gunstig($veld, $verschil['bedrag']),
            ];
        }

        return [
            'soort' => 'vergelijken',
            'titel' => self::SOORTEN['vergelijken'],
            'periode' => 'A: '.PeriodeLabel::van($aVan, $aTot).' · B: '.PeriodeLabel::van($bVan, $bTot),
            'bestand' => 'vergelijken-'.PeriodeLabel::kort($aVan, $aTot).'-met-'.PeriodeLabel::kort($bVan, $bTot),
            'orientatie' => 'portrait',
            'a' => ['label' => PeriodeLabel::van($aVan, $aTot), 'maanden' => $a->count()],
            'b' => ['label' => PeriodeLabel::van($bVan, $bTot), 'maanden' => $b->count()],
            'regels' => $regels,
            'perKanaal' => self::vergelijkRijen(PeriodeTotaal::perKanaal($a), PeriodeTotaal::perKanaal($b), fn ($sleutel) => $sleutel),
            'perProductgroep' => self::vergelijkRijen(PeriodeTotaal::perProductgroep($a), PeriodeTotaal::perProductgroep($b), fn ($sleutel, $rij) => $rij['naam']),
            'waarschuwingen' => PeriodeTotaal::waarschuwingen($a->merge($b)->unique('maand')->values()),
        ];
    }

    /** Per maand omzet, marge, winst en ROAS (omzet ÷ alle advertenties), met de wijziging t.o.v. de vorige maand. */
    public function progressie(CarbonInterface $van, CarbonInterface $tot): array
    {
        $rapporten = $this->rapport->periode($van, $tot);
        $velden = ['omzet', 'marge', 'winst', 'roas'];
        $maanden = [];
        $vorige = null;

        foreach ($rapporten as $rapport) {
            $rij = [
                'maand' => $rapport->maand,
                'omzet' => $rapport->totaal['omzet'],
                'marge' => $rapport->totaal['marge'],
                'winst' => $rapport->winst,
                'advertenties' => $rapport->advertenties,
                'roas' => $rapport->advertenties > 0 ? round($rapport->totaal['omzet'] / $rapport->advertenties, 2) : null,
                'wijziging' => null,
            ];

            if ($vorige !== null) {
                $rij['wijziging'] = [];

                foreach ($velden as $veld) {
                    $rij['wijziging'][$veld] = $rij[$veld] === null || $vorige[$veld] === null
                        ? ['bedrag' => null, 'procent' => null]
                        : self::verschil($vorige[$veld], $rij[$veld]);
                }
            }

            $maanden[] = $rij;
            $vorige = $rij;
        }

        $grafieken = [];

        foreach (['omzet' => 'Omzet', 'marge' => 'Marge', 'winst' => 'Winst', 'roas' => 'ROAS (omzet ÷ advertenties)'] as $veld => $titel) {
            $grafieken[$veld] = self::grafiek($titel, $veld === 'roas' ? 'getal' : 'geld', array_column($maanden, $veld, 'maand'));
        }

        return [
            'soort' => 'progressie',
            'titel' => self::SOORTEN['progressie'],
            'periode' => PeriodeLabel::van($van, $tot),
            'bestand' => 'progressie-'.$van->format('Y-m').'-tot-'.$tot->format('Y-m'),
            'orientatie' => 'landscape',
            'maanden' => $maanden,
            'grafieken' => $grafieken,
            'totaal' => PeriodeTotaal::totaal($rapporten),
            'waarschuwingen' => PeriodeTotaal::waarschuwingen($rapporten),
        ];
    }

    /** Rendert de gegevens van gegevens()/maand()/… als PDF (binair) met paginanummers. */
    public function pdf(array $gegevens): string
    {
        $html = view('dashed-ecommerce-rendabiliteit::pdf.rendabiliteit.'.$gegevens['soort'], $gegevens + [
            'f' => new PdfFormaat(),
            'gegenereerd' => Carbon::now()->locale('nl'),
        ])->render();

        $dompdf = Pdf::loadHTML($html)
            ->setPaper('a4', $gegevens['orientatie'])
            ->setOption('isFontSubsettingEnabled', true)
            ->getDomPDF();
        $dompdf->render();
        $this->paginanummers($dompdf);

        return (string) $dompdf->output();
    }

    public function bestandsnaam(array $gegevens): string
    {
        return 'lovora-rendabiliteit-'.$gegevens['bestand'].'.pdf';
    }

    /**
     * Verschil B − A en het procent t.o.v. A (over |A|, zodat een verbetering van een
     * negatieve winst ook positief is); procent null bij A = 0.
     *
     * @return array{bedrag: float, procent: ?float}
     */
    public static function verschil(float|int $a, float|int $b): array
    {
        $bedrag = round($b - $a, 2);

        return [
            'bedrag' => $bedrag,
            'procent' => round((float) $a, 2) == 0.0 ? null : round($bedrag / abs($a) * 100, 1),
        ];
    }

    private static function gunstig(string $veld, float $verschil): ?bool
    {
        if (round($verschil, 2) == 0.0) {
            return null;
        }

        return in_array($veld, self::KOSTEN, true) ? $verschil < 0 : $verschil > 0;
    }

    /**
     * Omzet en marge per sleutel (kanaal of productgroep) van A en B naast elkaar,
     * gesorteerd op de grootste omzet van de twee.
     *
     * @param  array<array-key, array{omzet: float, marge: float}>  $a
     * @param  array<array-key, array{omzet: float, marge: float}>  $b
     * @return list<array{naam: string, omzetA: float, omzetB: float, omzet: array{bedrag: float, procent: ?float}, margeA: float, margeB: float, marge: array{bedrag: float, procent: ?float}}>
     */
    private static function vergelijkRijen(array $a, array $b, callable $naam): array
    {
        $rijen = [];

        foreach (array_keys($a + $b) as $sleutel) {
            $rijA = $a[$sleutel] ?? ['omzet' => 0.0, 'marge' => 0.0];
            $rijB = $b[$sleutel] ?? ['omzet' => 0.0, 'marge' => 0.0];

            $rijen[] = [
                'naam' => (string) $naam($sleutel, $a[$sleutel] ?? $b[$sleutel]),
                'omzetA' => $rijA['omzet'],
                'omzetB' => $rijB['omzet'],
                'omzet' => self::verschil($rijA['omzet'], $rijB['omzet']),
                'margeA' => $rijA['marge'],
                'margeB' => $rijB['marge'],
                'marge' => self::verschil($rijA['marge'], $rijB['marge']),
            ];
        }

        usort($rijen, fn ($x, $y) => max($y['omzetA'], $y['omzetB']) <=> max($x['omzetA'], $x['omzetB']));

        return $rijen;
    }

    /**
     * Staafgrafiek als maten in px: positieve staven omhoog vanaf de nullijn, negatieve omlaag.
     * Het staafgebied wordt verdeeld naar verhouding van de grootste positieve en negatieve waarde.
     *
     * @param  array<string, ?float>  $waarden  maand 'Y-m' → waarde (null = geen waarde)
     */
    private static function grafiek(string $titel, string $soort, array $waarden): array
    {
        $bekend = array_filter($waarden, fn ($w) => $w !== null);
        $maxPositief = max([0.0, ...array_values($bekend)]);
        $maxNegatief = max([0.0, ...array_map(fn ($w) => -$w, array_values($bekend))]);
        $bereik = $maxPositief + $maxNegatief;
        $schaal = $bereik > 0 ? self::GRAFIEK_HOOGTE / $bereik : 0.0;

        $balken = [];

        foreach ($waarden as $maand => $waarde) {
            $hoogte = $waarde === null ? 0 : (int) round(abs($waarde) * $schaal);

            if ($waarde !== null && round($waarde, 2) != 0.0) {
                $hoogte = max(1, $hoogte);
            }

            $balken[] = [
                'maand' => $maand,
                'waarde' => $waarde,
                'positief' => $waarde !== null && $waarde > 0 ? $hoogte : 0,
                'negatief' => $waarde !== null && $waarde < 0 ? $hoogte : 0,
            ];
        }

        $gebiedPositief = (int) round($maxPositief * $schaal);
        $gebiedNegatief = (int) round($maxNegatief * $schaal);

        return [
            'titel' => $titel,
            'soort' => $soort,
            'balken' => $balken,
            // Ruimte voor het bedrag boven (of onder) de langste staaf; geen gebied zonder staven.
            'hoogtePositief' => $maxPositief > 0 || $maxNegatief == 0 ? max($gebiedPositief, 1) + self::LABEL_RUIMTE : 0,
            'hoogteNegatief' => $maxNegatief > 0 ? $gebiedNegatief + self::LABEL_RUIMTE : 0,
        ];
    }

    /** "Pagina x van y" rechtsonder, precies rechts uitgelijnd op de paginamarge en op één lijn met de voettekst. */
    private function paginanummers(Dompdf $dompdf): void
    {
        $marge = 12 / 25.4 * 72;

        $dompdf->getCanvas()->page_script(function (int $pagina, int $totaal, Canvas $canvas, FontMetrics $metrics) use ($marge) {
            $font = $metrics->getFont('DejaVu Sans');
            $grootte = 7;
            $tekst = "Pagina {$pagina} van {$totaal}";

            $canvas->text(
                $canvas->get_width() - $marge - $metrics->getTextWidth($tekst, $font, $grootte),
                $canvas->get_height() - 11 / 25.4 * 72,
                $tekst,
                $font,
                $grootte,
                [0.45, 0.45, 0.45],
            );
        });
    }

    private function parseMaand(string $waarde): Carbon
    {
        if (! preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/', trim($waarde))) {
            throw new InvalidArgumentException("Ongeldige maand: {$waarde}");
        }

        return Carbon::createFromFormat('!Y-m', trim($waarde))->startOfMonth();
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function parsePeriode(string $van, string $tot): array
    {
        $van = $this->parseMaand($van);
        $tot = $this->parseMaand($tot);

        if ($van->gt($tot)) {
            [$van, $tot] = [$tot, $van];
        }

        if ($van->diffInMonths($tot) >= self::MAX_MAANDEN) {
            throw new InvalidArgumentException('Een periode mag hoogstens '.self::MAX_MAANDEN.' maanden zijn.');
        }

        return [$van, $tot];
    }

    /** @param  Collection<int, MaandRapport>  $rapporten */
    public static function geschatteAdvertenties(Collection $rapporten): float
    {
        return round($rapporten->sum(fn (MaandRapport $r) => array_sum(array_column($r->advertentiesDetail, 'geschat'))), 2);
    }
}
