<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit;

use Illuminate\Support\Facades\DB;
use Dashed\DashedEcommerceRendabiliteit\Models\AdvertentieUitgave;

/**
 * Plakimport van advertentie-uitgaven per dag: regels `datum;platform;bedrag` (kopregel
 * optioneel; scheidingsteken tab, `;` of anders `,`). `voorbeeld()` slaat niets op;
 * `importeer()` schrijft alleen nieuwe en gewijzigde regels (bron `import`) en wist daarna
 * één keer de rapportcache.
 */
class AdvertentieImport
{
    public const DUBBEL = 'dubbel, later in de lijst overschreven';

    public const ONDUIDELIJK_BEDRAG = 'onduidelijk bedrag (gebruik twee decimalen, bijv. 1234,00)';

    /**
     * @return list<array{regel: int, datum: ?string, platform: ?string, bedrag: ?float, status: 'nieuw'|'bijgewerkt'|'gelijk'|'fout', oud: ?float, reden: ?string}>
     */
    public function voorbeeld(string $tekst): array
    {
        return array_map(function (array $regel) {
            unset($regel['bestaand']);

            return $regel;
        }, $this->analyseer($tekst));
    }

    /** @return array{nieuw: int, bijgewerkt: int, gelijk: int, fout: int} */
    public function importeer(string $tekst): array
    {
        $regels = $this->analyseer($tekst);
        $telling = ['nieuw' => 0, 'bijgewerkt' => 0, 'gelijk' => 0, 'fout' => 0];

        DB::transaction(function () use ($regels, &$telling) {
            foreach ($regels as $regel) {
                $telling[$regel['status']]++;

                if ($regel['status'] === 'nieuw') {
                    (new AdvertentieUitgave([
                        'datum' => $regel['datum'],
                        'platform' => $regel['platform'],
                        'bedrag' => $regel['bedrag'],
                        'bron' => 'import',
                    ]))->saveQuietly();
                }

                if ($regel['status'] === 'bijgewerkt') {
                    $regel['bestaand']->fill([
                        'platform' => $regel['platform'],
                        'bedrag' => $regel['bedrag'],
                        'bron' => 'import',
                    ])->saveQuietly();
                }
            }
        });

        if ($telling['nieuw'] + $telling['bijgewerkt'] > 0) {
            RendabiliteitCache::wis();
        }

        return $telling;
    }

    /**
     * @return list<array{regel: int, datum: ?string, platform: ?string, bedrag: ?float, status: string, oud: ?float, reden: ?string, bestaand: ?AdvertentieUitgave}>
     */
    private function analyseer(string $tekst): array
    {
        $tekst = $this->verwijderBom($tekst);
        $regels = [];
        $eersteRegel = true;

        foreach (preg_split('/\r\n|\r|\n/', $tekst) as $index => $ruw) {
            if (trim($ruw) === '' || $this->isCodeHek($ruw)) {
                continue;
            }

            $kolommen = $this->kolommen($ruw);

            if ($this->isKopregel($kolommen, $eersteRegel)) {
                $eersteRegel = false;

                continue;
            }

            $eersteRegel = false;
            $regels[] = $this->leesRegel($index + 1, $kolommen);
        }

        $this->markeerDubbelen($regels);
        $this->vergelijkMetBestaande($regels);

        return $regels;
    }

    /** Plakimport uit sommige bronnen begint met een onzichtbare UTF-8 BOM. */
    private function verwijderBom(string $tekst): string
    {
        return str_starts_with($tekst, "\xEF\xBB\xBF") ? substr($tekst, 3) : $tekst;
    }

    /** Een losse markdown-codehek-regel ("```" of "```text"), overgehouden aan een geplakt codeblok. */
    private function isCodeHek(string $regel): bool
    {
        return preg_match('/^`{3,}[a-z]*$/i', trim($regel)) === 1;
    }

    /** @return list<string> */
    private function kolommen(string $regel): array
    {
        if (str_contains($regel, "\t")) {
            $kolommen = explode("\t", $regel);
        } elseif (str_contains($regel, ';')) {
            $kolommen = explode(';', $regel);
        } else {
            // Komma als scheidingsteken: alles na het platform is het bedrag ("14,20").
            $kolommen = explode(',', $regel, 3);
        }

        // Ook aanhalingstekens per kolom strippen, zodat "2026-09-01";"Google";"14,20" prima parseert.
        $kolommen = array_map(fn (string $kolom) => trim($kolom, " \t\n\r\0\x0B\"'"), $kolommen);

        while (count($kolommen) > 3 && end($kolommen) === '') {
            array_pop($kolommen);
        }

        return $kolommen;
    }

    /**
     * Een kopregel: de eerste kolom heet letterlijk "datum" (ongeacht positie in de tekst —
     * geplakte content kan hem herhalen), of, alleen op de eerste regel, een kolom die met een
     * letter begint en niet als datum leest (bijv. "Datum;Platform;Bedrag").
     *
     * @param  list<string>  $kolommen
     */
    private function isKopregel(array $kolommen, bool $eersteRegel): bool
    {
        $eersteKolom = $kolommen[0] ?? '';

        if (strcasecmp($eersteKolom, 'datum') === 0) {
            return true;
        }

        return $eersteRegel && $this->datum($eersteKolom) === null && preg_match('/^\p{L}/u', $eersteKolom) === 1;
    }

    /** @param  list<string>  $kolommen */
    private function leesRegel(int $nummer, array $kolommen): array
    {
        $regel = ['regel' => $nummer, 'datum' => null, 'platform' => null, 'bedrag' => null, 'status' => 'fout', 'oud' => null, 'reden' => null, 'bestaand' => null];

        if (count($kolommen) < 3) {
            return [...$regel, 'reden' => 'te weinig kolommen (verwacht datum;platform;bedrag)'];
        }

        if (count($kolommen) > 3) {
            return [...$regel, 'reden' => 'te veel kolommen (verwacht datum;platform;bedrag)'];
        }

        [$datumTekst, $platformTekst, $bedragTekst] = $kolommen;

        $regel['datum'] = $this->datum($datumTekst);
        $regel['platform'] = AdvertentiePlatformen::normaliseer($platformTekst) ?: null;
        $ontleed = $this->bedrag($bedragTekst);
        $regel['bedrag'] = $ontleed['bedrag'];

        $reden = match (true) {
            $regel['datum'] === null => "ongeldige datum \"{$datumTekst}\" (gebruik JJJJ-MM-DD of DD-MM-JJJJ)",
            $regel['platform'] === null => 'lege platformnaam',
            mb_strlen($regel['platform']) > 255 => 'platformnaam is te lang',
            $ontleed['onduidelijk'] => self::ONDUIDELIJK_BEDRAG,
            $regel['bedrag'] === null => "ongeldig bedrag \"{$bedragTekst}\"",
            $regel['bedrag'] < 0 => 'negatief bedrag',
            default => null,
        };

        return [...$regel, 'status' => $reden === null ? 'nieuw' : 'fout', 'reden' => $reden];
    }

    private function datum(string $tekst): ?string
    {
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $tekst, $m)) {
            [$jaar, $maand, $dag] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $tekst, $m)) {
            [$dag, $maand, $jaar] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        return checkdate($maand, $dag, $jaar) ? sprintf('%04d-%02d-%02d', $jaar, $maand, $dag) : null;
    }

    /**
     * @return array{bedrag: ?float, onduidelijk: bool} `onduidelijk` als er maar één scheidingsteken
     *     is en dat er meer dan twee cijfers achter staan: dan is niet te zeggen of het een
     *     duizendtal- of decimaalteken is (bijv. "1.234" of "14,205").
     */
    private function bedrag(string $tekst): array
    {
        $waarde = preg_replace('/[\s\x{00A0}€]/u', '', $tekst);
        $negatief = str_starts_with($waarde, '-');
        $waarde = ltrim($waarde, '-');

        $komma = strrpos($waarde, ',');
        $punt = strrpos($waarde, '.');

        if ($komma !== false && $punt !== false) {
            // Het laatste teken is het decimaalteken, het andere scheidt duizendtallen.
            $decimaalPositie = max($komma, $punt);

            if (strlen($waarde) - $decimaalPositie - 1 > 2) {
                return ['bedrag' => null, 'onduidelijk' => true];
            }

            $waarde = $komma > $punt
                ? str_replace(',', '.', str_replace('.', '', $waarde))
                : str_replace(',', '', $waarde);
        } elseif ($komma !== false && substr_count($waarde, ',') === 1) {
            if (strlen($waarde) - $komma - 1 > 2) {
                return ['bedrag' => null, 'onduidelijk' => true];
            }
            $waarde = str_replace(',', '.', $waarde);
        } elseif ($komma !== false) {
            $waarde = str_replace(',', '', $waarde);
        } elseif ($punt !== false && substr_count($waarde, '.') === 1) {
            if (strlen($waarde) - $punt - 1 > 2) {
                return ['bedrag' => null, 'onduidelijk' => true];
            }
        } elseif ($punt !== false) {
            $waarde = str_replace('.', '', $waarde);
        }

        if ($waarde === '' || ! preg_match('/^\d+(\.\d+)?$/', $waarde)) {
            return ['bedrag' => null, 'onduidelijk' => false];
        }

        $bedrag = round((float) $waarde, 2);

        return ['bedrag' => $negatief ? -$bedrag : $bedrag, 'onduidelijk' => false];
    }

    /** Eerdere regels met dezelfde (datum, platform) vallen af; de laatste telt. */
    private function markeerDubbelen(array &$regels): void
    {
        $gezien = [];

        foreach ($regels as $index => $regel) {
            if ($regel['status'] === 'fout') {
                continue;
            }

            $sleutel = $regel['datum'].'|'.$regel['platform'];

            if (isset($gezien[$sleutel])) {
                $regels[$gezien[$sleutel]]['status'] = 'fout';
                $regels[$gezien[$sleutel]]['reden'] = self::DUBBEL;
            }

            $gezien[$sleutel] = $index;
        }
    }

    private function vergelijkMetBestaande(array &$regels): void
    {
        $datums = array_values(array_unique(array_filter(array_column(
            array_filter($regels, fn (array $regel) => $regel['status'] !== 'fout'),
            'datum',
        ))));

        if ($datums === []) {
            return;
        }

        $bestaand = AdvertentieUitgave::query()
            ->whereIn('datum', $datums)
            ->get()
            ->keyBy(fn (AdvertentieUitgave $rij) => $rij->datum->toDateString().'|'.AdvertentiePlatformen::normaliseer($rij->platform));

        foreach ($regels as &$regel) {
            if ($regel['status'] === 'fout') {
                continue;
            }

            $rij = $bestaand->get($regel['datum'].'|'.$regel['platform']);

            if ($rij === null) {
                continue;
            }

            $regel['bestaand'] = $rij;
            $regel['oud'] = round((float) $rij->bedrag, 2);
            $regel['status'] = $regel['oud'] === $regel['bedrag'] ? 'gelijk' : 'bijgewerkt';
        }
        unset($regel);
    }
}
