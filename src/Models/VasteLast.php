<?php

namespace Dashed\DashedEcommerceRendabiliteit\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\RendabiliteitCache;

/** Vaste maandlast (huur, abonnementen, ...) met een geldigheidsperiode. */
class VasteLast extends Model
{
    protected $table = 'vaste_lasten';

    protected $fillable = ['omschrijving', 'bedrag_per_maand', 'vanaf', 'tot'];

    protected $casts = [
        'bedrag_per_maand' => 'float',
        'vanaf' => 'date',
        'tot' => 'date',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => RendabiliteitCache::wis());
        static::deleted(fn () => RendabiliteitCache::wis());
    }

    public function scopeLopendIn($query, CarbonInterface $maand)
    {
        $eerste = $maand->copy()->startOfMonth()->toDateString();

        return $query->where('vanaf', '<=', $eerste)
            ->where(fn ($q) => $q->whereNull('tot')->orWhere('tot', '>=', $eerste));
    }
}
