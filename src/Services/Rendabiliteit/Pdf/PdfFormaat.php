<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\Pdf;

use Illuminate\Support\Carbon;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\MaandRapport;
use Dashed\DashedEcommerceRendabiliteit\Filament\Pages\Rendabiliteit\RendabiliteitPage;

/** Opmaak voor de rendabiliteits-PDF's; bedragen en maanden precies zoals op de rapportpagina. */
final class PdfFormaat
{
    public function geld(?float $bedrag): string
    {
        return RendabiliteitPage::geld($bedrag);
    }

    public function procent(?float $waarde): string
    {
        return $waarde === null ? '—' : RendabiliteitPage::procent($waarde);
    }

    /** Marge % van een rij met omzet en margePercentage; zinloos bij omzet ≤ 0. */
    public function margeProcent(array $rij): string
    {
        return $rij['omzet'] > 0 ? RendabiliteitPage::procent($rij['margePercentage']) : '—';
    }

    public function getal(?float $waarde, int $decimalen = 2): string
    {
        return $waarde === null ? '—' : number_format($waarde, $decimalen, ',', '.');
    }

    public function maand(string $maand): string
    {
        return RendabiliteitPage::maandLabel($maand);
    }

    /** Korte maand voor grafiekassen: "mrt '26". */
    public function maandKort(string $maand): string
    {
        $datum = Carbon::createFromFormat('!Y-m', $maand)->locale('nl');

        return rtrim($datum->translatedFormat('M'), '.')." '".$datum->format('y');
    }

    /** Verschil in euro met teken: "+€ 12,00" / "−€ 12,00". */
    public function verschilGeld(?float $bedrag): string
    {
        if ($bedrag === null) {
            return '—';
        }

        return $this->teken($bedrag).RendabiliteitPage::geld(abs($bedrag));
    }

    /** Verschil in procent met teken: "+12,3%". */
    public function verschilProcent(?float $procent): string
    {
        if ($procent === null) {
            return '—';
        }

        return $this->teken($procent).RendabiliteitPage::procent(abs($procent));
    }

    /** Verschil in procentpunten (bij marge %): "+1,2 pp". */
    public function verschilPunten(?float $punten): string
    {
        if ($punten === null) {
            return '—';
        }

        return $this->teken($punten).number_format(abs($punten), 1, ',', '.').' pp';
    }

    /** Verschil in een kaal getal (bij ROAS): "+0,35". */
    public function verschilGetal(?float $waarde): string
    {
        if ($waarde === null) {
            return '—';
        }

        return $this->teken($waarde).number_format(abs($waarde), 2, ',', '.');
    }

    /** Zelfde notitie bij de verzending als op de rapportpagina: werkelijk, tarief of geschat met factor. */
    public function verzendNotitie(MaandRapport $rapport): ?string
    {
        $samen = $rapport->verzendBron();

        return match ($samen['bron'] ?? null) {
            'factuur' => '(werkelijk)',
            'tarief' => '(tarief)',
            'gemiddelde', 'gemengd' => '(geschat, factor '.$this->getal($samen['factor']).')',
            default => null,
        };
    }

    public function bronLabel(string $bron): string
    {
        return ['factuur' => 'Factuur', 'gemiddelde' => 'Factuurgemiddelde', 'tarief' => 'Tarief', 'gemengd' => 'Gemengd'][$bron] ?? $bron;
    }

    /** Groen boven de break-even-ROAS, rood eronder (zoals op de rapportpagina). */
    public function roasKlasse(?float $roas, ?float $breakEvenRoas): string
    {
        if ($roas === null || $breakEvenRoas === null || $roas == $breakEvenRoas) {
            return '';
        }

        return $roas > $breakEvenRoas ? 'goed' : 'slecht';
    }

    /** Klasse voor een verschil: groen als gunstig, rood als ongunstig. */
    public function gunstigKlasse(?bool $gunstig): string
    {
        return match ($gunstig) {
            true => 'goed',
            false => 'slecht',
            null => '',
        };
    }

    /** Korte bedragen voor labels boven grafiekbalken: "€ 12.345". */
    public function geldKort(float $bedrag): string
    {
        return '€ '.number_format($bedrag, 0, ',', '.');
    }

    private function teken(float $waarde): string
    {
        return match (true) {
            round($waarde, 2) > 0 => '+',
            round($waarde, 2) < 0 => '−',
            default => '',
        };
    }
}
