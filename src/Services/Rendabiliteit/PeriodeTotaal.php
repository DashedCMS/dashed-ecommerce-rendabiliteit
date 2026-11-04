<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit;

use Illuminate\Support\Collection;

/**
 * Telt maandrapporten op tot één periode: de totaalrij, de waarschuwingen en de
 * uitsplitsing per kanaal en per productgroep. Gedeeld door de rapportpagina en
 * de PDF-export, zodat een periode overal hetzelfde optelt. Percentages worden
 * over de opgetelde bedragen berekend, nooit als gemiddelde van de maanden.
 */
final class PeriodeTotaal
{
    public const VELDEN = ['omzet', 'kostprijs', 'verpakking', 'verzending', 'betaalkosten', 'commissie', 'marge'];

    /**
     * @param  Collection<int, MaandRapport>  $rapporten
     * @return array{omzet: float, kostprijs: float, verpakking: float, verzending: float, betaalkosten: float, commissie: float, marge: float, margePercentage: float, aantalOrders: int, advertenties: float, vasteLasten: float, winst: float}
     */
    public static function totaal(Collection $rapporten): array
    {
        $totaal = [];

        foreach (self::VELDEN as $veld) {
            $totaal[$veld] = round($rapporten->sum(fn (MaandRapport $r) => $r->totaal[$veld]), 2);
        }

        $totaal['margePercentage'] = self::margePercentage($totaal['marge'], $totaal['omzet']);
        $totaal['aantalOrders'] = (int) $rapporten->sum(fn (MaandRapport $r) => $r->totaal['aantalOrders']);
        $totaal['advertenties'] = round($rapporten->sum('advertenties'), 2);
        $totaal['vasteLasten'] = round($rapporten->sum('vasteLasten'), 2);
        $totaal['winst'] = round($rapporten->sum('winst'), 2);

        return $totaal;
    }

    /**
     * Waarschuwingen van alle maanden samengevoegd per code, grootste omzet eerst.
     *
     * @param  Collection<int, MaandRapport>  $rapporten
     * @return array<string, array{omschrijving: string, aantal: int, omzet: float}>
     */
    public static function waarschuwingen(Collection $rapporten): array
    {
        $samen = [];

        foreach ($rapporten as $rapport) {
            foreach ($rapport->waarschuwingen as $code => $data) {
                $samen[$code] ??= ['omschrijving' => $data['omschrijving'], 'aantal' => 0, 'omzet' => 0.0];
                $samen[$code]['aantal'] += $data['aantal'];
                $samen[$code]['omzet'] = round($samen[$code]['omzet'] + $data['omzet'], 2);
            }
        }

        uasort($samen, fn ($a, $b) => $b['omzet'] <=> $a['omzet']);

        return $samen;
    }

    /**
     * @param  Collection<int, MaandRapport>  $rapporten
     * @return array<string, array{omzet: float, kostprijs: float, verpakking: float, verzending: float, betaalkosten: float, commissie: float, marge: float, margePercentage: float, aantalOrders: int}>
     */
    public static function perKanaal(Collection $rapporten): array
    {
        $samen = [];

        foreach ($rapporten as $rapport) {
            foreach ($rapport->perKanaal as $kanaal => $rij) {
                $samen[$kanaal] ??= array_merge(array_fill_keys(self::VELDEN, 0.0), ['aantalOrders' => 0]);

                foreach (self::VELDEN as $veld) {
                    $samen[$kanaal][$veld] += $rij[$veld];
                }

                $samen[$kanaal]['aantalOrders'] += $rij['aantalOrders'];
            }
        }

        foreach ($samen as &$rij) {
            foreach (self::VELDEN as $veld) {
                $rij[$veld] = round($rij[$veld], 2);
            }

            $rij['margePercentage'] = self::margePercentage($rij['marge'], $rij['omzet']);
        }
        unset($rij);

        uasort($samen, fn ($a, $b) => $b['omzet'] <=> $a['omzet']);

        return $samen;
    }

    /**
     * @param  Collection<int, MaandRapport>  $rapporten
     * @return array<int, array{naam: string, omzet: float, kostprijs: float, orderkosten: float, marge: float}>
     */
    public static function perProductgroep(Collection $rapporten): array
    {
        $samen = [];

        foreach ($rapporten as $rapport) {
            foreach ($rapport->perProductgroep as $groepId => $groep) {
                $samen[$groepId] ??= ['naam' => $groep['naam'], 'omzet' => 0.0, 'kostprijs' => 0.0, 'orderkosten' => 0.0, 'marge' => 0.0];

                foreach (['omzet', 'kostprijs', 'orderkosten', 'marge'] as $veld) {
                    $samen[$groepId][$veld] += $groep[$veld];
                }
            }
        }

        foreach ($samen as &$groep) {
            foreach (['omzet', 'kostprijs', 'orderkosten', 'marge'] as $veld) {
                $groep[$veld] = round($groep[$veld], 2);
            }
        }
        unset($groep);

        uasort($samen, fn ($a, $b) => $b['omzet'] <=> $a['omzet']);

        return $samen;
    }

    /**
     * Gemiddelde break-even: gemiddelde advertenties + vaste lasten per maand, gedeeld
     * door het margepercentage over de hele periode (null zonder maanden of bij marge ≤ 0).
     *
     * @param  Collection<int, MaandRapport>  $rapporten
     */
    public static function breakEvenGemiddeld(Collection $rapporten): ?float
    {
        $aantal = $rapporten->count();
        $totaal = self::totaal($rapporten);

        if ($aantal === 0 || $totaal['margePercentage'] <= 0) {
            return null;
        }

        return round((($totaal['advertenties'] + $totaal['vasteLasten']) / $aantal) / ($totaal['margePercentage'] / 100), 2);
    }

    public static function margePercentage(float $marge, float $omzet): float
    {
        return $omzet > 0 ? round($marge / $omzet * 100, 2) : 0.0;
    }
}
