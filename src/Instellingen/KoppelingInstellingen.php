<?php

namespace Dashed\DashedEcommerceRendabiliteit\Instellingen;

use Illuminate\Support\Facades\Crypt;
use Dashed\DashedCore\Models\Customsetting;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * Inloggegevens en laatste stand van de advertentiekoppelingen. Geheimen staan
 * versleuteld in Customsetting en worden nooit teruggetoond; leeg opslaan laat
 * een bestaand geheim staan.
 */
class KoppelingInstellingen
{
    public const META = 'meta';

    public const GOOGLE = 'google';

    public const BOL = 'bol';

    public static function aan(string $koppeling): bool
    {
        return (bool) (int) self::lees("{$koppeling}_aan", '0');
    }

    public static function zetAan(string $koppeling, bool $aan): void
    {
        Customsetting::set(self::sleutel("{$koppeling}_aan"), $aan ? '1' : '0');
    }

    public static function waarde(string $veld): ?string
    {
        $waarde = trim((string) self::lees($veld));

        return $waarde === '' ? null : $waarde;
    }

    public static function zetWaarde(string $veld, ?string $waarde): void
    {
        Customsetting::set(self::sleutel($veld), trim((string) $waarde));
    }

    public static function geheim(string $veld): ?string
    {
        $opgeslagen = self::waarde($veld);

        if ($opgeslagen === null) {
            return null;
        }

        try {
            return Crypt::decryptString($opgeslagen) ?: null;
        } catch (DecryptException) {
            return null;
        }
    }

    public static function zetGeheim(string $veld, ?string $waarde): void
    {
        $waarde = trim((string) $waarde);

        if ($waarde !== '') {
            Customsetting::set(self::sleutel($veld), Crypt::encryptString($waarde));
        }
    }

    /** @return array{at: string, gelukt: bool, fout: ?string, dagen: int, totaal: float}|null */
    public static function stand(string $koppeling): ?array
    {
        $stand = json_decode((string) self::lees("{$koppeling}_stand"), true);

        return is_array($stand) ? $stand : null;
    }

    public static function zetStand(string $koppeling, array $stand): void
    {
        Customsetting::set(self::sleutel("{$koppeling}_stand"), json_encode($stand));
    }

    private static function lees(string $veld, ?string $standaard = null): mixed
    {
        return Customsetting::get(self::sleutel($veld), null, $standaard, null, 'default', true);
    }

    private static function sleutel(string $veld): string
    {
        return "rendabiliteit_koppeling_{$veld}";
    }
}
