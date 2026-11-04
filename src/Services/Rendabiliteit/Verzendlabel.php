<?php

namespace Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit;

use Throwable;
use Illuminate\Support\Carbon;

/**
 * Gedeelde regels voor verzendlabels (Veloyd en MyParcel), gebruikt door
 * OrderKostenBerekenaar en VerzendfactuurCorrectie zodat beide hetzelfde
 * label en dezelfde tariefsleutel zien.
 */
final class Verzendlabel
{
    /**
     * Alleen een rij met track & trace is een echt label; concept- of nooit
     * geprinte rijen tellen niet. $ruw is de ruwe kolomwaarde (JSON-string).
     */
    public static function heeftTrackAndTrace(mixed $ruw): bool
    {
        return ! in_array(is_string($ruw) ? trim($ruw) : $ruw, [null, '', '[]', '{}', 'null', '""'], true);
    }

    /**
     * De track & trace-code van een label. Veloyd en MyParcel bewaren een JSON-lijst van
     * {code: url}-objecten (`[{"3SIJVT…": "https://…"}]`); een los object, een lijst met
     * codes of een kale code-string worden ook herkend. Leeg → null.
     */
    public static function code(mixed $trackAndTrace): ?string
    {
        if (is_string($trackAndTrace)) {
            $ruw = trim($trackAndTrace);

            if ($ruw === '') {
                return null;
            }

            $gedecodeerd = json_decode($ruw, true);
            $trackAndTrace = json_last_error() === JSON_ERROR_NONE ? $gedecodeerd : $ruw;
        }

        while (is_array($trackAndTrace) && $trackAndTrace !== []) {
            if (! array_is_list($trackAndTrace)) {
                $trackAndTrace = (string) array_key_first($trackAndTrace);

                break;
            }

            $trackAndTrace = $trackAndTrace[0];
        }

        if (! is_string($trackAndTrace) && ! is_int($trackAndTrace)) {
            return null;
        }

        $code = trim((string) $trackAndTrace);

        return $code === '' ? null : $code;
    }

    /**
     * Verzenddatum van een label: het aanmaakmoment van de track & trace-rij met dezelfde code,
     * anders de vroegste track & trace-rij van de order die niet vóór het label is aangemaakt (een
     * zending gaat niet de deur uit voordat haar label bestond; bijv. een retourlabel valt zo niet
     * terug op de heenzending), anders de aanmaakdatum van het label (null als ook die ontbreekt). $trackAndTraces zijn de rijen van de order (code, created_at).
     *
     * @param  iterable<object{code: ?string, created_at: mixed}>  $trackAndTraces
     */
    public static function verzenddatum(mixed $trackAndTrace, mixed $labelAangemaakt, iterable $trackAndTraces): ?Carbon
    {
        $code = self::code($trackAndTrace);
        $labelDatum = self::datum($labelAangemaakt);
        $vroegste = null;

        foreach ($trackAndTraces as $rij) {
            $datum = self::datum($rij->created_at ?? null);

            if ($datum === null) {
                continue;
            }

            if ($code !== null && strcasecmp(trim((string) ($rij->code ?? '')), $code) === 0) {
                return $datum;
            }

            if ($labelDatum !== null && $datum->lt($labelDatum)) {
                continue;
            }

            if ($vroegste === null || $datum->lt($vroegste)) {
                $vroegste = $datum;
            }
        }

        return $vroegste ?? $labelDatum;
    }

    private static function datum(mixed $waarde): ?Carbon
    {
        if ($waarde === null || $waarde === '') {
            return null;
        }

        try {
            return Carbon::parse($waarde);
        } catch (Throwable) {
            return null;
        }
    }

    /** 'brievenbus|<land>' voor package_type 2 en 3, anders 'pakket|<land>'. */
    public static function tariefSleutel(int|string|null $packageType, ?string $land): string
    {
        return (in_array((int) $packageType, [2, 3], true) ? 'brievenbus' : 'pakket').'|'.Landen::normaliseer($land);
    }
}
