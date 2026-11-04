<?php

namespace Dashed\DashedEcommerceRendabiliteit\Models;

use Illuminate\Database\Eloquent\Model;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\RendabiliteitCache;

/** Advertentiekosten per dag en platform (excl. btw), handmatig of via import (`bron`). */
class AdvertentieUitgave extends Model
{
    protected $table = 'advertentie_uitgaven';

    protected $fillable = ['datum', 'platform', 'bedrag', 'bron'];

    protected $casts = [
        'datum' => 'date',
        'bedrag' => 'float',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => RendabiliteitCache::wis());
        static::deleted(fn () => RendabiliteitCache::wis());
    }
}
