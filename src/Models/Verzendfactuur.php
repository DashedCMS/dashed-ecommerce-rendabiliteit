<?php

namespace Dashed\DashedEcommerceRendabiliteit\Models;

use Throwable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\RendabiliteitCache;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\VerzendfactuurLezer;

/**
 * Verzendfactuur van een vervoerder; corrigeert de geschatte verzendkosten in
 * het rendabiliteitsrapport (zie VerzendfactuurCorrectie). Uniek op
 * (vervoerder, factuurnummer) wanneer factuurnummer gevuld is — bewaakt in
 * het formulier, niet in de database.
 */
class Verzendfactuur extends Model
{
    public const VELOYD = 'Veloyd';

    public const MYPARCEL = 'MyParcel';

    public const SENDY = 'Sendy';

    public const BRON_AI = 'ai';

    public const BRON_HANDMATIG = 'handmatig';

    protected $table = 'verzendfacturen';

    protected $fillable = [
        'vervoerder',
        'factuurnummer',
        'factuurdatum',
        'periode_van',
        'periode_tot',
        'bedrag',
        'btw_bedrag',
        'aantal_zendingen',
        'bestand',
        'bron',
        'notitie',
    ];

    protected $casts = [
        'factuurdatum' => 'date',
        'periode_van' => 'date',
        'periode_tot' => 'date',
        'bedrag' => 'float',
        'btw_bedrag' => 'float',
        'aantal_zendingen' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => RendabiliteitCache::wis());
        static::deleted(fn () => RendabiliteitCache::wis());

        // Geen verweesde PDF's op de private local-disk.
        static::deleted(fn (self $factuur) => self::verwijderBestand($factuur->bestand));
        static::updated(function (self $factuur) {
            if ($factuur->wasChanged('bestand')) {
                self::verwijderBestand($factuur->getOriginal('bestand'));
            }
        });
    }

    /** Verwijdert een PDF uit verzendfacturen/ op de local-disk; een fout breekt het opslaan nooit. */
    public static function verwijderBestand(?string $pad): void
    {
        if (blank($pad) || ! VerzendfactuurLezer::isVertrouwdPad($pad)) {
            return;
        }

        try {
            Storage::disk('local')->delete($pad);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
