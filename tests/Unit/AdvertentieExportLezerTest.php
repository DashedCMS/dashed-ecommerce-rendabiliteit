<?php

namespace Dashed\DashedEcommerceRendabiliteit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\AdvertentieExportLezer;

class AdvertentieExportLezerTest extends TestCase
{
    private function fixture(string $naam): string
    {
        return (string) file_get_contents(__DIR__.'/../Fixtures/ads/'.$naam);
    }

    public function test_google_export_geeft_regels_per_dag_en_somt_campagnes(): void
    {
        $this->assertSame(
            "2026-09-01;Google;1334,56\n2026-09-02;Google;20,50\n2026-09-03;Google;18,00",
            (new AdvertentieExportLezer())->naarRegels($this->fixture('google.csv'), null),
        );
    }

    public function test_meta_export_met_engelse_kopregels(): void
    {
        $this->assertSame(
            "2026-09-01;Meta;22,75\n2026-09-02;Meta;24,50",
            (new AdvertentieExportLezer())->naarRegels($this->fixture('meta.csv'), null),
        );
    }

    public function test_bol_export_met_nederlandse_datums(): void
    {
        $this->assertSame(
            "2026-09-01;Bol;7,80\n2026-09-02;Bol;9,35",
            (new AdvertentieExportLezer())->naarRegels($this->fixture('bol.csv'), null),
        );
    }

    public function test_totaalregel_onderaan_wordt_overgeslagen(): void
    {
        $regels = (new AdvertentieExportLezer())->naarRegels($this->fixture('bol.csv'), null);

        $this->assertStringNotContainsString('Totaal', (string) $regels);
        $this->assertCount(2, explode("\n", (string) $regels));
    }

    public function test_euroteken_en_duizendtalpunt_worden_goed_gelezen(): void
    {
        $csv = "Dag,Kosten\n2026-09-01,\"€ 1.234,56\"\n";

        $this->assertSame('2026-09-01;Google;1234,56', (new AdvertentieExportLezer())->naarRegels($csv, 'Google'));
    }

    public function test_onduidelijk_bedrag_gaat_ongewijzigd_door(): void
    {
        $csv = "Dag,Kosten\n2026-09-01,1.234\n";

        $this->assertSame('2026-09-01;Google;1.234', (new AdvertentieExportLezer())->naarRegels($csv, 'Google'));
    }

    public function test_gekozen_platform_wint_van_het_herkende(): void
    {
        $this->assertStringContainsString(';TikTok;', (string) (new AdvertentieExportLezer())->naarRegels($this->fixture('meta.csv'), 'tiktok'));
    }

    public function test_onbekend_formaat_geeft_null(): void
    {
        $lezer = new AdvertentieExportLezer();

        $this->assertNull($lezer->naarRegels("Naam,Leeftijd\nJan,30\n", null));
        $this->assertNull($lezer->naarRegels('', null));
        // Wel een datum en kosten, maar geen herkenbaar platform en niet gekozen.
        $this->assertNull($lezer->naarRegels("Date,Spend\n2026-09-01,5\n", null));
    }
}
