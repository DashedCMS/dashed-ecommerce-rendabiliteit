<?php

namespace Dashed\DashedEcommerceRendabiliteit\Models;

use Illuminate\Database\Eloquent\Model;
use Dashed\DashedEcommerceCore\Models\Product;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Eén regel van een inkoopfactuur: een product (prijs per stuk) of kosten (verzending, toeslag). */
class InkoopfactuurRegel extends Model
{
    public const SOORT_PRODUCT = 'product';

    public const SOORT_KOSTEN = 'kosten';

    public const KOPPELING_EAN = 'ean';

    public const KOPPELING_SKU = 'sku';

    public const KOPPELING_ONTHOUDEN = 'onthouden';

    public const KOPPELING_HANDMATIG = 'handmatig';

    protected $table = 'inkoopfactuur_regels';

    protected $fillable = [
        'inkoopfactuur_id',
        'artikelcode',
        'ean',
        'omschrijving',
        'aantal',
        'stuksprijs',
        'korting',
        'soort',
        'product_id',
        'koppeling',
        'toegepast_at',
    ];

    protected $attributes = [
        'soort' => self::SOORT_PRODUCT,
        'aantal' => 1,
        'korting' => 0,
    ];

    protected $casts = [
        'aantal' => 'float',
        'stuksprijs' => 'float',
        'korting' => 'float',
        'product_id' => 'integer',
        'toegepast_at' => 'datetime',
    ];

    public function factuur(): BelongsTo
    {
        return $this->belongsTo(Inkoopfactuur::class, 'inkoopfactuur_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isKosten(): bool
    {
        return $this->soort === self::SOORT_KOSTEN;
    }
}
