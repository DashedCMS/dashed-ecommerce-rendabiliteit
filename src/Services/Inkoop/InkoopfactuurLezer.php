<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Inkoop;

use Throwable;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Reader;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Dashed\DashedEcommerceRendabiliteit\Models\InkoopfactuurRegel;
use Dashed\DashedEcommerceRendabiliteit\Services\Concerns\LeestMetClaude;

/**
 * Leest een inkoopfactuur (PDF, CSV of XLSX) uit met Claude via de
 * dashed-ai-provider "claude". Een PDF gaat als document, een CSV als tekst en
 * een XLSX eerst omgezet naar CSV. Faalt nooit met een exception: elke fout
 * komt terug als `gelukt=false` met een melding.
 */
class InkoopfactuurLezer
{
    use LeestMetClaude;

    public const MODEL = 'claude-opus-5';

    /** Ruim: een factuur kan veel regels hebben en Opus 5 denkt standaard na. */
    public const MAX_TOKENS = 16000;

    /** Alleen bestanden in deze map op de private local-disk worden gelezen. */
    public const MAP = 'inkoopfacturen';

    public const EXTENSIES = ['pdf', 'csv', 'txt', 'xlsx'];

    /** Tekst (CSV/XLSX) boven deze lengte wordt niet naar Claude gestuurd. */
    public const MAX_TEKENS = 200000;

    public const GEEN_KOPPELING = 'Geen Claude-koppeling ingesteld (Instellingen → AI)';

    private const INSTRUCTIE = <<<'TXT'
        Dit is een inkoopfactuur van een leverancier.
        Antwoord met alleen een JSON-object, zonder uitleg, met precies deze sleutels:
        - "leverancier": de naam van de leverancier (de afzender van de factuur) als tekst
        - "factuurnummer": het factuurnummer als tekst
        - "factuurdatum": de factuurdatum als JJJJ-MM-DD
        - "prijzen_incl_btw": true als de stuksprijzen op de regels inclusief btw zijn, false als ze exclusief btw zijn
        - "regels": lijst met elke regel van de factuur, elk als object met:
          - "artikelcode": de artikelcode of het artikelnummer van de leverancier als tekst, of null
          - "ean": de EAN- of barcode als tekst, of null
          - "omschrijving": de omschrijving als tekst
          - "aantal": het aantal als getal
          - "stuksprijs": de prijs per stuk vóór korting als getal, met een punt als decimaalteken
          - "korting": de korting per stuk als bedrag (geen percentage; reken een percentage om naar een bedrag per stuk), of 0
          - "soort": "kosten" voor verzendkosten, toeslagen, administratiekosten en dergelijke; anders "product"
        Is iets onbekend of staat het niet op de factuur, gebruik dan null. Verzin niets.
        TXT;

    /**
     * Leest een opgeslagen factuur: alleen een relatief pad binnen
     * `inkoopfacturen/` op de private `local`-disk.
     *
     * @return array{gelukt: bool, fout: ?string, twijfel: list<string>, leverancier: ?string, factuurnummer: ?string, factuurdatum: ?string, prijzen_incl_btw: bool, regels: list<array>}
     */
    public function lees(string $pad): array
    {
        if (! self::isVertrouwdPad($pad) || ! in_array(self::extensie($pad), self::EXTENSIES, true)) {
            return $this->mislukt('Onbekend bestand');
        }

        return $this->metClaude(self::extensie($pad), function () use ($pad) {
            $disk = Storage::disk('local');

            return $disk->exists($pad) ? $disk->path($pad) : null;
        });
    }

    /** Leest een nog niet opgeslagen Livewire-upload. */
    public function leesUpload(TemporaryUploadedFile $bestand): array
    {
        $extensie = strtolower($bestand->getClientOriginalExtension() ?: $bestand->extension());

        if (! in_array($extensie, self::EXTENSIES, true)) {
            return $this->mislukt('Upload een PDF, CSV of XLSX.');
        }

        return $this->metClaude($extensie, fn () => $bestand->exists() ? $bestand->getRealPath() : null);
    }

    /** Relatief pad binnen inkoopfacturen/, zonder '..', backslashes of schema. */
    public static function isVertrouwdPad(string $pad): bool
    {
        return self::padBinnen($pad, self::MAP);
    }

    private static function extensie(string $pad): string
    {
        return strtolower(pathinfo($pad, PATHINFO_EXTENSION));
    }

    /** @param callable(): ?string $bestandspad levert het lokale pad, of null als het bestand ontbreekt */
    private function metClaude(string $extensie, callable $bestandspad): array
    {
        try {
            $provider = $this->claudeProvider();
            if (! $provider) {
                return $this->mislukt(self::GEEN_KOPPELING);
            }

            $pad = $bestandspad();
            if (! $pad || ! is_file($pad)) {
                return $this->mislukt('Het bestand is niet gevonden.');
            }

            $blok = $this->inhoudBlok($extensie, $pad);
            if (is_string($blok)) {
                return $this->mislukt($blok);
            }

            $antwoord = $provider->messages([[
                'role' => 'user',
                'content' => [$blok, ['type' => 'text', 'text' => self::INSTRUCTIE]],
            ]], ['model' => self::MODEL, 'max_tokens' => self::MAX_TOKENS]);

            [$tekst, $fout] = $this->tekstUitAntwoord($antwoord, 'deze factuur');
            if ($fout !== null) {
                return $this->mislukt($fout);
            }

            $json = $this->haalJson($tekst);
            if ($json === null) {
                return $this->mislukt('Claude gaf geen leesbaar antwoord: '.Str::limit(trim($tekst), 200));
            }

            return $this->valideer($json);
        } catch (Throwable $e) {
            report($e);

            return $this->mislukt('Uitlezen mislukt: '.Str::limit($e->getMessage(), 300));
        }
    }

    /** Het inhoudsblok voor Claude, of een foutmelding. */
    private function inhoudBlok(string $extensie, string $pad): array|string
    {
        if ($extensie === 'pdf') {
            return [
                'type' => 'document',
                'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode((string) file_get_contents($pad))],
            ];
        }

        $tekst = $extensie === 'xlsx' ? $this->xlsxAlsCsv($pad) : (string) file_get_contents($pad);
        $tekst = trim(mb_convert_encoding($tekst, 'UTF-8', 'UTF-8, Windows-1252'));

        if ($tekst === '') {
            return 'Het bestand is leeg.';
        }
        if (mb_strlen($tekst) > self::MAX_TEKENS) {
            return 'Het bestand is te groot om uit te lezen; splits het op of voer de regels zelf in.';
        }

        return ['type' => 'text', 'text' => "Inhoud van de factuur ({$extensie}):\n\n".$tekst];
    }

    /** Alle tabbladen van een XLSX als CSV-tekst (OpenSpout, meegeleverd met Filament). */
    private function xlsxAlsCsv(string $pad): string
    {
        $reader = new Reader();
        $reader->open($pad);

        $regels = [];

        try {
            foreach ($reader->getSheetIterator() as $blad) {
                foreach ($blad->getRowIterator() as $rij) {
                    $velden = array_map(function ($waarde) {
                        $waarde = $waarde instanceof \DateTimeInterface ? $waarde->format('Y-m-d') : (string) $waarde;

                        return preg_match('/[",\n]/', $waarde) ? '"'.str_replace('"', '""', $waarde).'"' : $waarde;
                    }, $rij->toArray());

                    if (implode('', $velden) !== '') {
                        $regels[] = implode(',', $velden);
                    }

                    if (count($regels) > 5000) {
                        break 2;
                    }
                }
            }
        } finally {
            $reader->close();
        }

        return implode("\n", $regels);
    }

    private function valideer(array $json): array
    {
        $twijfel = [];

        $leverancier = $this->tekst($json['leverancier'] ?? null);
        if ($leverancier === null) {
            $twijfel[] = 'Leverancier niet gevonden; vul hem zelf in.';
        }

        $factuurnummer = $this->tekst($json['factuurnummer'] ?? null);
        if ($factuurnummer === null) {
            $twijfel[] = 'Factuurnummer niet gevonden; vul het zelf in.';
        }

        $inclBtw = $json['prijzen_incl_btw'] ?? null;
        if (! is_bool($inclBtw)) {
            $twijfel[] = 'Niet duidelijk of de prijzen incl. of excl. btw zijn; controleer dit.';
        }

        $factuurdatum = $this->datum($json['factuurdatum'] ?? null, 'Factuurdatum', $twijfel);
        $twijfel = [...$twijfel, ...$this->regelTwijfel($json['regels'] ?? null, $regels)];

        return [
            'gelukt' => true,
            'fout' => null,
            'twijfel' => $twijfel,
            'leverancier' => $leverancier,
            'factuurnummer' => $factuurnummer,
            'factuurdatum' => $factuurdatum,
            'prijzen_incl_btw' => $inclBtw === true,
            'regels' => $regels,
        ];
    }

    /**
     * Zet de regels in $regels en geeft de twijfels erover terug. Een regel
     * zonder leesbare stuksprijs wordt weggelaten (met een melding).
     *
     * @param-out list<array> $regels
     */
    private function regelTwijfel(mixed $waarde, ?array &$regels): array
    {
        $regels = [];
        $twijfel = [];

        if (! is_array($waarde) || $waarde === []) {
            return ['Geen regels gevonden; voer ze zelf in.'];
        }

        foreach ($waarde as $nr => $regel) {
            if (! is_array($regel)) {
                continue;
            }

            $omschrijving = $this->tekst($regel['omschrijving'] ?? null);
            $label = 'Regel '.($nr + 1).($omschrijving ? ' ('.Str::limit($omschrijving, 40).')' : '');

            $stuksprijs = $this->getal($regel['stuksprijs'] ?? null, 4);
            if ($stuksprijs === null) {
                $twijfel[] = "{$label}: geen stuksprijs gevonden, niet overgenomen.";

                continue;
            }

            $aantal = $this->getal($regel['aantal'] ?? null, 3);
            if ($aantal === null || $aantal <= 0) {
                $twijfel[] = "{$label}: aantal onleesbaar, op 1 gezet.";
                $aantal = 1.0;
            }

            $ean = preg_replace('/\s+/', '', (string) $this->tekst($regel['ean'] ?? null, 50));

            $regels[] = [
                'artikelcode' => $this->tekst($regel['artikelcode'] ?? null),
                'ean' => $ean === '' ? null : $ean,
                'omschrijving' => $omschrijving,
                'aantal' => $aantal,
                'stuksprijs' => round($stuksprijs, 4),
                'korting' => round(max(0.0, (float) $this->getal($regel['korting'] ?? null, 4)), 4),
                'soort' => ($regel['soort'] ?? null) === InkoopfactuurRegel::SOORT_KOSTEN ? InkoopfactuurRegel::SOORT_KOSTEN : InkoopfactuurRegel::SOORT_PRODUCT,
            ];
        }

        return $twijfel;
    }

    private function mislukt(string $fout): array
    {
        return [
            'gelukt' => false,
            'fout' => $fout,
            'twijfel' => [],
            'leverancier' => null,
            'factuurnummer' => null,
            'factuurdatum' => null,
            'prijzen_incl_btw' => false,
            'regels' => [],
        ];
    }
}
