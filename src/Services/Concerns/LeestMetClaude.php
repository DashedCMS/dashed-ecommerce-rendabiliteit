<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Concerns;

use Illuminate\Support\Str;
use Dashed\DashedAi\AiManager;
use Illuminate\Support\Carbon;
use Dashed\DashedAi\AiProvider;

/**
 * Gedeeld door de factuurlezers: de Claude-provider ophalen, een antwoord van
 * de Messages API omzetten naar JSON en losse waarden (datums, bedragen)
 * voorzichtig uitlezen.
 */
trait LeestMetClaude
{
    /** Relatief pad binnen $map/, zonder '..', backslashes, schema of nulbyte. */
    protected static function padBinnen(string $pad, string $map): bool
    {
        return str_starts_with($pad, $map.'/')
            && ! str_contains($pad, '..')
            && ! str_contains($pad, '\\')
            && ! str_contains($pad, ':')
            && ! str_contains($pad, "\0");
    }

    /** De verbonden provider "claude", of null als die er niet is. */
    protected function claudeProvider(): ?AiProvider
    {
        $provider = app(AiManager::class)->provider('claude');

        return $provider && $provider->isConnected() ? $provider : null;
    }

    /**
     * Tekst uit een Messages-antwoord, of een foutmelding bij een weigering of
     * een afgekapt antwoord.
     *
     * @return array{0: ?string, 1: ?string} [tekst, fout]
     */
    protected function tekstUitAntwoord(array $antwoord, string $wat): array
    {
        $stop = $antwoord['stop_reason'] ?? null;
        if ($stop === 'refusal') {
            return [null, "Claude weigerde {$wat} uit te lezen."];
        }
        if ($stop === 'max_tokens') {
            return [null, 'Het antwoord van Claude was afgekapt; probeer het opnieuw.'];
        }

        return [collect($antwoord['content'] ?? [])->where('type', 'text')->pluck('text')->implode("\n"), null];
    }

    /** JSON-object uit het antwoord, ook tussen ```json-hekken of met tekst eromheen. */
    protected function haalJson(string $tekst): ?array
    {
        $kandidaten = [trim($tekst)];

        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $tekst, $hek)) {
            $kandidaten[] = trim($hek[1]);
        }

        $begin = strpos($tekst, '{');
        $eind = strrpos($tekst, '}');
        if ($begin !== false && $eind !== false && $eind > $begin) {
            $kandidaten[] = substr($tekst, $begin, $eind - $begin + 1);
        }

        foreach ($kandidaten as $kandidaat) {
            $data = json_decode($kandidaat, true);
            if (is_array($data) && ! array_is_list($data)) {
                return $data;
            }
        }

        return null;
    }

    /** Geldige datum JJJJ-MM-DD of null (ongeldig → twijfel, ontbrekend → stil null). */
    protected function datum(mixed $waarde, string $label, array &$twijfel): ?string
    {
        if ($waarde === null || $waarde === '') {
            return null;
        }

        if (is_string($waarde) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $waarde)) {
            [$j, $m, $d] = array_map('intval', explode('-', $waarde));
            if (checkdate($m, $d, $j) && $j >= 2000 && $j <= 2100) {
                return Carbon::createFromDate($j, $m, $d)->toDateString();
            }
        }

        $twijfel[] = $label.' ongeldig ("'.Str::limit(is_scalar($waarde) ? (string) $waarde : json_encode($waarde), 30).'").';

        return null;
    }

    /**
     * Getal uit een JSON-getal of een tekst met punt of komma als decimaalteken.
     * Een komma met meer dan $decimalen cijfers erachter kan een duizendtalteken
     * zijn en wordt niet gelezen.
     */
    protected function getal(mixed $waarde, int $decimalen = 2): ?float
    {
        if (is_int($waarde) || is_float($waarde)) {
            return (float) $waarde;
        }

        if (! is_string($waarde)) {
            return null;
        }

        $tekst = str_replace(['€', ' '], '', trim($waarde));
        if (preg_match('/^-?\d+,\d{1,'.$decimalen.'}$/', $tekst)) {
            $tekst = str_replace(',', '.', $tekst);
        }

        return is_numeric($tekst) ? (float) $tekst : null;
    }

    /** Korte tekst uit een scalar, of null als hij leeg is. */
    protected function tekst(mixed $waarde, int $max = 255): ?string
    {
        return filled($waarde) && is_scalar($waarde) ? Str::limit(trim((string) $waarde), $max, '') : null;
    }
}
