<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit;

use Throwable;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Dashed\DashedEcommerceRendabiliteit\Services\Concerns\LeestMetClaude;
use Dashed\DashedEcommerceRendabiliteit\Services\Inkoop\InkoopfactuurLezer;

/**
 * Zet een export van Google Ads, Meta Ads Manager of Bol-advertenties (CSV of XLSX, of een
 * PDF via Claude) om naar de plaktekst `datum;platform;bedrag` voor AdvertentieImport.
 *
 * De drie formaten worden zonder AI herkend aan hun kopregel. De kopregels in de tests
 * (tests/Fixtures/ads) zijn de standaard Nederlandse en Engelse kopregels: nog te
 * controleren tegen een echte export. Een onbekend formaat gaat naar Claude met dezelfde
 * uitvoervorm. Bedragen zijn excl. btw, zoals in de export.
 */
class AdvertentieExportLezer
{
    use LeestMetClaude;

    public const MODEL = 'claude-opus-5';

    public const MAX_TOKENS = 16000;

    /** Alleen bestanden in deze map op de private local-disk worden gelezen. */
    public const MAP = 'advertentie-exports';

    public const EXTENSIES = ['csv', 'txt', 'xlsx', 'pdf'];

    public const MAX_TEKENS = 200000;

    public const GEEN_KOPPELING = 'Geen Claude-koppeling ingesteld (Instellingen → AI)';

    private const DATUM_KOLOMMEN = ['dag', 'day', 'datum', 'date', 'reporting starts', 'begin rapportage'];

    private const KOSTEN_KOLOMMEN = ['kosten', 'cost', 'besteed bedrag', 'amount spent', 'uitgegeven bedrag', 'spend', 'uitgaven'];

    private const META_KOSTEN = ['besteed bedrag', 'amount spent', 'uitgegeven bedrag'];

    private const INSTRUCTIE = <<<'TXT'
        Dit is een export met advertentie-uitgaven. Geef per dag en per advertentieplatform de totale kosten excl. btw.
        Antwoord met alleen regels in het formaat datum;platform;bedrag, zonder uitleg en zonder kopregel:
        - datum als JJJJ-MM-DD
        - platform als Google, Meta, Bol, TikTok, Pinterest of Microsoft (anders de naam zoals in de export)
        - bedrag met een komma als decimaalteken en zonder duizendtalteken of valutateken, bijvoorbeeld 14,20
        Tel meerdere campagnes op dezelfde dag bij elkaar op. Sla totaalregels over. Verzin niets.
        TXT;

    /**
     * Plaktekst voor AdvertentieImport, of null als het formaat niet wordt herkend
     * (geen datum- en kostenkolom, of geen platform te bepalen).
     */
    public function naarRegels(string $inhoud, ?string $platform): ?string
    {
        $regels = preg_split('/\r\n|\r|\n/', $this->naarUtf8($inhoud)) ?: [];
        $gekozen = AdvertentiePlatformen::normaliseer($platform);

        foreach (["\t", ';', ','] as $scheiding) {
            foreach (array_slice($regels, 0, 40, true) as $index => $regel) {
                $kop = $this->kopregel($this->cellen($regel, $scheiding));

                if ($kop === null) {
                    continue;
                }

                $label = $gekozen !== '' ? $gekozen : $kop['platform'];

                return $label === null || $label === ''
                    ? null
                    : $this->leesRijen(array_slice($regels, $index + 1), $scheiding, $kop, $label);
            }
        }

        return null;
    }

    /**
     * Leest een opgeslagen export: een relatief pad binnen `advertentie-exports/` op de private
     * `local`-disk (nooit de `dashed`-disk). Faalt nooit met een exception.
     *
     * @return array{gelukt: bool, fout: ?string, tekst: string, bron: ?string} bron: 'export' of 'claude'
     */
    public function lees(string $pad, ?string $platform = null): array
    {
        if (! self::padBinnen($pad, self::MAP)) {
            return $this->mislukt('Onbekend bestand');
        }

        $extensie = strtolower(pathinfo($pad, PATHINFO_EXTENSION));
        $disk = Storage::disk('local');

        if (! in_array($extensie, self::EXTENSIES, true) || ! $disk->exists($pad)) {
            return $this->mislukt('Upload een CSV, XLSX of PDF.');
        }

        try {
            $volledig = $disk->path($pad);

            if ($extensie !== 'pdf') {
                $tekst = $extensie === 'xlsx' ? (new InkoopfactuurLezer())->xlsxAlsCsv($volledig) : (string) file_get_contents($volledig);
                $regels = $this->naarRegels($tekst, $platform);

                if ($regels !== null) {
                    return ['gelukt' => true, 'fout' => null, 'tekst' => $regels, 'bron' => 'export'];
                }
            }

            return $this->metClaude($extensie, $volledig, $extensie === 'pdf' ? null : ($tekst ?? ''), $platform);
        } catch (Throwable $e) {
            report($e);

            return $this->mislukt('Inlezen mislukt: '.Str::limit($e->getMessage(), 300));
        }
    }

    private function metClaude(string $extensie, string $pad, ?string $tekst, ?string $platform): array
    {
        $provider = $this->claudeProvider();
        if (! $provider) {
            return $this->mislukt('Dit formaat herkennen we niet en er is geen Claude-koppeling ingesteld (Instellingen → AI). Plak de regels zelf via Importeren.');
        }

        if ($extensie === 'pdf') {
            $blok = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode((string) file_get_contents($pad))]];
        } else {
            $tekst = trim($this->naarUtf8((string) $tekst));
            if ($tekst === '') {
                return $this->mislukt('Het bestand is leeg.');
            }
            if (mb_strlen($tekst) > self::MAX_TEKENS) {
                return $this->mislukt('Het bestand is te groot om uit te lezen; splits het op of plak de regels zelf.');
            }
            $blok = ['type' => 'text', 'text' => "Inhoud van de export ({$extensie}):\n\n".$tekst];
        }

        $instructie = self::INSTRUCTIE.(filled($platform) ? "\nHet platform is ".AdvertentiePlatformen::normaliseer($platform).'.' : '');

        $antwoord = $provider->messages([[
            'role' => 'user',
            'content' => [$blok, ['type' => 'text', 'text' => $instructie]],
        ]], ['model' => self::MODEL, 'max_tokens' => self::MAX_TOKENS]);

        [$uitkomst, $fout] = $this->tekstUitAntwoord($antwoord, 'deze export');
        if ($fout !== null) {
            return $this->mislukt($fout);
        }

        $uitkomst = trim((string) preg_replace('/^`{3,}[a-z]*\s*|\s*`{3,}$/mi', '', (string) $uitkomst));

        return $uitkomst === ''
            ? $this->mislukt('Claude vond geen uitgaven in dit bestand.')
            : ['gelukt' => true, 'fout' => null, 'tekst' => $uitkomst, 'bron' => 'claude'];
    }

    /** @param  list<string>  $cellen */
    private function kopregel(array $cellen): ?array
    {
        $datum = $kosten = null;

        foreach ($cellen as $index => $cel) {
            $naam = trim((string) preg_replace('/\(.*?\)/u', '', mb_strtolower(trim($cel))));

            if ($datum === null && in_array($naam, self::DATUM_KOLOMMEN, true)) {
                $datum = ['index' => $index, 'naam' => $naam];
            } elseif ($kosten === null && in_array($naam, self::KOSTEN_KOLOMMEN, true)) {
                $kosten = ['index' => $index, 'naam' => $naam];
            }
        }

        if ($datum === null || $kosten === null) {
            return null;
        }

        $platform = match (true) {
            in_array($kosten['naam'], self::META_KOSTEN, true), in_array($datum['naam'], ['reporting starts', 'begin rapportage'], true) => 'Meta',
            in_array($datum['naam'], ['dag', 'day'], true) && in_array($kosten['naam'], ['kosten', 'cost'], true) => 'Google',
            in_array($datum['naam'], ['datum', 'date'], true) && in_array($kosten['naam'], ['kosten', 'cost'], true) => 'Bol',
            default => null,
        };

        return ['datum' => $datum['index'], 'kosten' => $kosten['index'], 'platform' => $platform];
    }

    /**
     * @param  list<string>  $regels
     * @param  array{datum: int, kosten: int, platform: ?string}  $kop
     */
    private function leesRijen(array $regels, string $scheiding, array $kop, string $platform): string
    {
        $groepen = [];

        foreach ($regels as $regel) {
            if (trim($regel) === '') {
                continue;
            }

            $cellen = $this->cellen($regel, $scheiding);
            $eerste = trim((string) ($cellen[0] ?? ''));
            $datumTekst = trim((string) ($cellen[$kop['datum']] ?? ''));
            $kostenTekst = trim((string) ($cellen[$kop['kosten']] ?? ''));

            if ($datumTekst === '' || preg_match('/^(totaal|total|--+|-)(\b|:|$)/iu', $eerste) || preg_match('/^(totaal|total)/iu', $datumTekst)) {
                continue;
            }

            $groepen[$this->datum($datumTekst)][] = $kostenTekst;
        }

        $uit = [];

        foreach ($groepen as $datum => $bedragen) {
            $waarden = array_map(fn (string $b) => $this->getal($b), $bedragen);

            if (in_array(null, $waarden, true)) {
                // Onduidelijk bedrag: ongewijzigd doorgeven, AdvertentieImport meldt het als fout.
                foreach ($bedragen as $bedrag) {
                    $uit[] = "{$datum};{$platform};{$bedrag}";
                }

                continue;
            }

            $uit[] = "{$datum};{$platform};".number_format(array_sum($waarden), 2, ',', '');
        }

        return implode("\n", $uit);
    }

    /** @return list<string> */
    private function cellen(string $regel, string $scheiding): array
    {
        return array_map(fn ($cel) => (string) $cel, str_getcsv($regel, $scheiding, '"', ''));
    }

    private function datum(string $tekst): string
    {
        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $tekst, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        return $tekst;
    }

    /** Zelfde regels als AdvertentieImport; null bij een onduidelijk of ongeldig bedrag. */
    private function getal(string $tekst): ?float
    {
        $waarde = (string) preg_replace('/[\s\x{00A0}€]/u', '', $tekst);
        $negatief = str_starts_with($waarde, '-');
        $waarde = ltrim($waarde, '-');
        $komma = strrpos($waarde, ',');
        $punt = strrpos($waarde, '.');

        if ($komma !== false && $punt !== false) {
            $positie = max($komma, $punt);
            if (strlen($waarde) - $positie - 1 > 2) {
                return null;
            }
            $waarde = $komma > $punt ? str_replace(',', '.', str_replace('.', '', $waarde)) : str_replace(',', '', $waarde);
        } elseif ($komma !== false && substr_count($waarde, ',') === 1) {
            if (strlen($waarde) - $komma - 1 > 2) {
                return null;
            }
            $waarde = str_replace(',', '.', $waarde);
        } elseif ($komma !== false) {
            $waarde = str_replace(',', '', $waarde);
        } elseif ($punt !== false && substr_count($waarde, '.') === 1) {
            if (strlen($waarde) - $punt - 1 > 2) {
                return null;
            }
        } elseif ($punt !== false) {
            $waarde = str_replace('.', '', $waarde);
        }

        if ($waarde === '' || ! preg_match('/^\d+(\.\d+)?$/', $waarde)) {
            return null;
        }

        return $negatief ? -(float) $waarde : (float) $waarde;
    }

    /** UTF-8 met BOM weg; UTF-16 (Google Ads) en Windows-1252 worden omgezet. */
    private function naarUtf8(string $inhoud): string
    {
        if (str_starts_with($inhoud, "\xFF\xFE")) {
            $inhoud = mb_convert_encoding(substr($inhoud, 2), 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($inhoud, "\xFE\xFF")) {
            $inhoud = mb_convert_encoding(substr($inhoud, 2), 'UTF-8', 'UTF-16BE');
        } elseif (str_starts_with($inhoud, "\xEF\xBB\xBF")) {
            $inhoud = substr($inhoud, 3);
        }

        return mb_check_encoding($inhoud, 'UTF-8') ? $inhoud : mb_convert_encoding($inhoud, 'UTF-8', 'Windows-1252');
    }

    private function mislukt(string $fout): array
    {
        return ['gelukt' => false, 'fout' => $fout, 'tekst' => '', 'bron' => null];
    }
}
