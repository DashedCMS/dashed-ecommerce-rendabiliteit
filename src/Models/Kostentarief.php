<?php

namespace Dashed\DashedEcommerceRendabiliteit\Models;

use Illuminate\Database\Eloquent\Model;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\RendabiliteitCache;

/** Tarief met ingangsdatum; een wijziging is een nieuwe rij met latere geldig_vanaf. */
class Kostentarief extends Model
{
    public const SOORTEN = ['verzending', 'verpakking', 'betaalmethode', 'etsy_commissie', 'advertentie_dag'];

    protected $table = 'kostentarieven';

    protected $fillable = ['soort', 'sleutel', 'vast_bedrag', 'percentage', 'geldig_vanaf'];

    protected $casts = [
        'vast_bedrag' => 'float',
        'percentage' => 'float',
        'geldig_vanaf' => 'date',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => RendabiliteitCache::wis());
        static::deleted(fn () => RendabiliteitCache::wis());
    }
}
