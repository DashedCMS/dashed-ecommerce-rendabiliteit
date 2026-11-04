<?php

namespace Dashed\DashedEcommerceRendabiliteit\Models;

use Throwable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Dashed\DashedEcommerceRendabiliteit\Services\Inkoop\InkoopfactuurLezer;
use Dashed\DashedEcommerceRendabiliteit\Services\Inkoop\DubbeleInkoopfactuur;

/**
 * Inkoopfactuur van een leverancier. Uniek op (leverancier, factuurnummer):
 * een tweede keer dezelfde factuur wordt geweigerd, in het formulier en hier.
 */
class Inkoopfactuur extends Model
{
    public const STATUS_CONCEPT = 'concept';

    public const STATUS_VERWERKT = 'verwerkt';

    public const BRON_AI = 'ai';

    public const BRON_HANDMATIG = 'handmatig';

    protected $table = 'inkoopfacturen';

    protected $fillable = [
        'leverancier',
        'factuurnummer',
        'factuurdatum',
        'bestand',
        'bron',
        'prijzen_incl_btw',
        'kosten_verdelen',
        'status',
        'notitie',
    ];

    protected $attributes = [
        'bron' => self::BRON_HANDMATIG,
        'prijzen_incl_btw' => false,
        'kosten_verdelen' => true,
        'status' => self::STATUS_CONCEPT,
    ];

    protected $casts = [
        'factuurdatum' => 'date',
        'prijzen_incl_btw' => 'boolean',
        'kosten_verdelen' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $factuur) {
            $factuur->leverancier = trim((string) $factuur->leverancier);
            $factuur->factuurnummer = trim((string) $factuur->factuurnummer);

            if (self::bestaatAl($factuur->leverancier, $factuur->factuurnummer, $factuur->getKey())) {
                throw new DubbeleInkoopfactuur("Factuur {$factuur->factuurnummer} van {$factuur->leverancier} staat er al.");
            }
        });

        // Geen verweesde bestanden op de private local-disk.
        static::deleted(fn (self $factuur) => self::verwijderBestand($factuur->bestand));
        static::updated(function (self $factuur) {
            if ($factuur->wasChanged('bestand')) {
                self::verwijderBestand($factuur->getOriginal('bestand'));
            }
        });
    }

    public static function bestaatAl(string $leverancier, string $factuurnummer, ?int $behalveId = null): bool
    {
        return self::query()
            ->where('leverancier', trim($leverancier))
            ->where('factuurnummer', trim($factuurnummer))
            ->when($behalveId, fn ($q) => $q->whereKeyNot($behalveId))
            ->exists();
    }

    /** Al (deels) toegepast: de regels liggen dan vast en worden niet opnieuw uitgelezen. */
    public function isToegepast(): bool
    {
        return $this->status === self::STATUS_VERWERKT
            || ($this->exists && $this->regels()->whereNotNull('toegepast_at')->exists());
    }

    public function regels(): HasMany
    {
        return $this->hasMany(InkoopfactuurRegel::class, 'inkoopfactuur_id');
    }

    /** Verwijdert een bestand uit inkoopfacturen/ op de local-disk; een fout breekt het opslaan nooit. */
    public static function verwijderBestand(?string $pad): void
    {
        if (blank($pad) || ! InkoopfactuurLezer::isVertrouwdPad($pad)) {
            return;
        }

        try {
            Storage::disk('local')->delete($pad);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
