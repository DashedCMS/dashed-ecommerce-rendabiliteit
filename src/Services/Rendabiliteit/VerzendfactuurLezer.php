<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit;

use Throwable;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Dashed\DashedEcommerceRendabiliteit\Support\Vervoerders;
use Dashed\DashedEcommerceRendabiliteit\Models\Verzendfactuur;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Dashed\DashedEcommerceRendabiliteit\Services\Concerns\LeestMetClaude;

/**
 * Leest een verzendfactuur (PDF) uit met Claude via de dashed-ai-provider
 * "claude" en geeft voorgevulde formuliervelden terug. Faalt nooit met een
 * exception: elke fout komt terug als `gelukt=false` met een melding.
 * Velden die niet kloppen worden null en staan in `twijfel`.
 */
class VerzendfactuurLezer
{
    use LeestMetClaude;

    public const MODEL = 'claude-opus-5';

    /** Ruim: Opus 5 denkt standaard na en dat telt mee voor max_tokens (de provider kan thinking/effort niet doorgeven). */
    public const MAX_TOKENS = 8000;

    public const ONBEKEND_BESTAND = 'Onbekend bestand';

    /** Alleen bestanden in deze map op de private local-disk worden gelezen. */
    public const MAP = 'verzendfacturen';

    public const GEEN_KOPPELING = 'Geen Claude-koppeling ingesteld (Instellingen → AI)';

    private const INSTRUCTIE = <<<'TXT'
        Dit is een factuur van een vervoerder (Veloyd of MyParcel) voor verzonden pakketten.
        Antwoord met alleen een JSON-object, zonder uitleg, met precies deze sleutels:
        - "vervoerder": "Veloyd" of "MyParcel", of null als het geen van beide is
        - "factuurnummer": het factuurnummer als tekst
        - "factuurdatum": de factuurdatum als JJJJ-MM-DD
        - "periode_van" en "periode_tot": de periode waarover gefactureerd wordt, als JJJJ-MM-DD; noemt de factuur alleen een maand, gebruik dan de eerste en de laatste dag van die maand
        - "bedrag_excl_btw": ALLEEN de frankeer-/verzendkosten (portokosten) exclusief btw als getal, met een punt als decimaalteken; tel abonnementen/vaste kosten (zoals "Abonnement dagelijkse pakketservice") en credit-/compensatieregels (zoals "Credit ... vergoeding inhoud") hier niet bij op
        - "btw_bedrag": het btw-bedrag over de hele factuur als getal, met een punt als decimaalteken
        - "aantal_zendingen": het aantal zendingen of pakketten als geheel getal, of null
        - "overige_regels": lijst van de overige regels op de factuur die niet in bedrag_excl_btw zitten (abonnementen, vaste kosten, credit-/compensatieregels e.d.), elk als object met "omschrijving" (tekst) en "bedrag_excl_btw" (getal, met een punt als decimaalteken); lege lijst als er geen zijn
        Is iets onbekend of staat het niet op de factuur, gebruik dan null. Verzin niets.
        TXT;

    /**
     * Leest een opgeslagen factuur: alleen een relatief pad binnen
     * `verzendfacturen/` op de private `local`-disk, nooit een absoluut pad
     * (het pad komt uit formulierstate die de browser kan aanpassen).
     *
     * @return array{gelukt: bool, velden: array<string, mixed>, fout: ?string, twijfel: list<string>, overige_regels: list<array{omschrijving: string, bedrag_excl_btw: ?float}>}
     */
    public function lees(string $pdfPad): array
    {
        if (! self::isVertrouwdPad($pdfPad)) {
            return $this->mislukt(self::ONBEKEND_BESTAND);
        }

        return $this->metClaude(function () use ($pdfPad) {
            $disk = Storage::disk('local');

            return $disk->exists($pdfPad) ? (string) $disk->get($pdfPad) : null;
        });
    }

    /**
     * Leest een nog niet opgeslagen Livewire-upload.
     *
     * @return array{gelukt: bool, velden: array<string, mixed>, fout: ?string, twijfel: list<string>, overige_regels: list<array{omschrijving: string, bedrag_excl_btw: ?float}>}
     */
    public function leesUpload(TemporaryUploadedFile $bestand): array
    {
        return $this->metClaude(fn () => $bestand->exists() ? (string) $bestand->get() : null);
    }

    /** Relatief pad binnen verzendfacturen/, zonder '..', backslashes of schema. */
    public static function isVertrouwdPad(string $pad): bool
    {
        return self::padBinnen($pad, self::MAP);
    }

    /** @param callable(): ?string $inhoud levert de PDF-bytes, of null als het bestand ontbreekt */
    private function metClaude(callable $inhoud): array
    {
        try {
            $provider = $this->claudeProvider();

            if (! $provider) {
                return $this->mislukt(self::GEEN_KOPPELING);
            }

            $pdf = $inhoud();
            if ($pdf === null || $pdf === '') {
                return $this->mislukt('Het PDF-bestand is niet gevonden.');
            }

            $antwoord = $provider->messages([[
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'document',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => 'application/pdf',
                            'data' => base64_encode($pdf),
                        ],
                    ],
                    ['type' => 'text', 'text' => self::INSTRUCTIE],
                ],
            ]], ['model' => self::MODEL, 'max_tokens' => self::MAX_TOKENS]);

            return $this->verwerk($antwoord);
        } catch (Throwable $e) {
            report($e);

            return $this->mislukt('Uitlezen mislukt: '.Str::limit($e->getMessage(), 300));
        }
    }

    private function verwerk(array $antwoord): array
    {
        [$tekst, $fout] = $this->tekstUitAntwoord($antwoord, 'deze factuur');
        if ($fout !== null) {
            return $this->mislukt($fout);
        }

        $json = $this->haalJson($tekst);
        if ($json === null) {
            return $this->mislukt('Claude gaf geen leesbaar antwoord: '.Str::limit(trim($tekst), 200));
        }

        return $this->valideer($json);
    }

    private function valideer(array $json): array
    {
        $twijfel = [];

        $vervoerder = $this->vervoerder($json['vervoerder'] ?? null);
        if ($vervoerder === null) {
            $twijfel[] = 'Vervoerder niet herkend'.(filled($json['vervoerder'] ?? null) ? ' ("'.Str::limit((string) $json['vervoerder'], 40).'")' : '').'; kies Veloyd of MyParcel.';
        }

        $factuurnummer = filled($json['factuurnummer'] ?? null) && is_scalar($json['factuurnummer'])
            ? Str::limit(trim((string) $json['factuurnummer']), 255, '')
            : null;

        $factuurdatum = $this->datum($json['factuurdatum'] ?? null, 'Factuurdatum', $twijfel);
        $van = $this->datum($json['periode_van'] ?? null, 'Periode van', $twijfel);
        $tot = $this->datum($json['periode_tot'] ?? null, 'Periode tot', $twijfel);
        if ($van === null || $tot === null) {
            $twijfel[] = 'Periode niet (volledig) gevonden; vul hem zelf in.';
        } elseif ($van > $tot) {
            $twijfel[] = "Periode klopt niet ({$van} ligt na {$tot}); vul hem zelf in.";
            $van = $tot = null;
        }

        $bedrag = $this->getal($json['bedrag_excl_btw'] ?? null);
        if ($bedrag === null || $bedrag <= 0) {
            $twijfel[] = 'Bedrag excl. btw niet gevonden of niet groter dan 0.';
            $bedrag = null;
        }

        $btw = $this->getal($json['btw_bedrag'] ?? null);
        if (($json['btw_bedrag'] ?? null) !== null && ($btw === null || $btw < 0)) {
            $twijfel[] = 'Btw-bedrag onleesbaar of negatief.';
            $btw = null;
        }

        $aantal = $this->getal($json['aantal_zendingen'] ?? null);
        if (($json['aantal_zendingen'] ?? null) !== null && ($aantal === null || $aantal < 0 || floor($aantal) != $aantal)) {
            $twijfel[] = 'Aantal zendingen onleesbaar of geen geheel getal.';
            $aantal = null;
        }

        return [
            'gelukt' => true,
            'velden' => [
                'vervoerder' => $vervoerder,
                'factuurnummer' => $factuurnummer,
                'factuurdatum' => $factuurdatum,
                'periode_van' => $van,
                'periode_tot' => $tot,
                'bedrag' => $bedrag === null ? null : round($bedrag, 2),
                'btw_bedrag' => $btw === null ? null : round($btw, 2),
                'aantal_zendingen' => $aantal === null ? null : (int) $aantal,
            ],
            'fout' => null,
            'twijfel' => $twijfel,
            'overige_regels' => $this->overigeRegels($json['overige_regels'] ?? null),
        ];
    }

    /**
     * Regels op de factuur die bewust niet in bedrag_excl_btw zitten (abonnementen,
     * vaste kosten, credit-/compensatieregels). Ongeldige of onvolledige regels worden
     * stilzwijgend overgeslagen; dit is puur informatief, geen basis voor twijfel.
     *
     * @return list<array{omschrijving: string, bedrag_excl_btw: ?float}>
     */
    private function overigeRegels(mixed $waarde): array
    {
        if (! is_array($waarde)) {
            return [];
        }

        $regels = [];
        foreach ($waarde as $regel) {
            if (! is_array($regel)) {
                continue;
            }

            $omschrijving = filled($regel['omschrijving'] ?? null) && is_scalar($regel['omschrijving'])
                ? Str::limit(trim((string) $regel['omschrijving']), 255, '')
                : null;
            if ($omschrijving === null) {
                continue;
            }

            $regels[] = [
                'omschrijving' => $omschrijving,
                'bedrag_excl_btw' => $this->getal($regel['bedrag_excl_btw'] ?? null),
            ];
        }

        return $regels;
    }

    private function vervoerder(mixed $waarde): ?string
    {
        if (! is_string($waarde)) {
            return null;
        }

        return Vervoerders::herken($waarde);
    }

    private function mislukt(string $fout): array
    {
        return ['gelukt' => false, 'velden' => [], 'fout' => $fout, 'twijfel' => [], 'overige_regels' => []];
    }
}
