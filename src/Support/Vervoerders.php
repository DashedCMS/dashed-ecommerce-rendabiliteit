<?php

namespace Dashed\DashedEcommerceRendabiliteit\Support;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;

/** De vervoerders uit config('dashed-ecommerce-rendabiliteit.vervoerders') waarvan de labeltabel bestaat. */
class Vervoerders
{
    /** @var array<string, array{naam: string, tabel: string, relatie: string}>|null */
    private static ?array $actief = null;

    /** @return array<string, array{naam: string, tabel: string, relatie: string}> naam → vervoerder, in config-volgorde */
    public static function actief(): array
    {
        if (self::$actief !== null) {
            return self::$actief;
        }

        $actief = [];

        foreach ((array) config('dashed-ecommerce-rendabiliteit.vervoerders', []) as $sleutel => $vervoerder) {
            $naam = (string) ($vervoerder['naam'] ?? $sleutel);
            $tabel = (string) ($vervoerder['tabel'] ?? '');

            if ($tabel === '' || ! Schema::hasTable($tabel)) {
                continue;
            }

            $actief[$naam] = [
                'naam' => $naam,
                'tabel' => $tabel,
                'relatie' => (string) ($vervoerder['relatie'] ?? Str::camel($sleutel).'Orders'),
            ];
        }

        return self::$actief = $actief;
    }

    /** @return array<string, string> naam → tabel */
    public static function tabellen(): array
    {
        return array_map(fn (array $v) => $v['tabel'], self::actief());
    }

    /** @return array<string, string> naam → relatie op de order */
    public static function relaties(): array
    {
        return array_map(fn (array $v) => $v['relatie'], self::actief());
    }

    /** @return array<string, string> naam → naam, voor een keuzelijst */
    public static function opties(): array
    {
        $namen = array_keys(self::actief());

        return array_combine($namen, $namen);
    }

    /** Herkent een vervoerder op naam of config-sleutel (hoofdletters, spaties en streepjes tellen niet). */
    public static function herken(?string $waarde): ?string
    {
        if (! is_string($waarde)) {
            return null;
        }

        $zoek = Str::lower(str_replace([' ', '-', '_'], '', $waarde));

        foreach ((array) config('dashed-ecommerce-rendabiliteit.vervoerders', []) as $sleutel => $vervoerder) {
            $naam = (string) ($vervoerder['naam'] ?? $sleutel);

            if (in_array($zoek, [Str::lower(str_replace([' ', '-', '_'], '', $naam)), Str::lower((string) $sleutel)], true)) {
                return $naam;
            }
        }

        return null;
    }

    public static function reset(): void
    {
        self::$actief = null;
    }
}
