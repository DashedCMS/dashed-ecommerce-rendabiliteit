<?php

namespace Dashed\DashedEcommerceRendabiliteit\Models;

use Illuminate\Database\Eloquent\Model;

/** Onthoudt bij welk product de artikelcode van een leverancier hoort. */
class LeverancierArtikel extends Model
{
    protected $table = 'leverancier_artikelen';

    protected $fillable = ['leverancier', 'artikelcode', 'product_id'];

    protected $casts = ['product_id' => 'integer'];
}
