<?php

namespace Dashed\DashedEcommerceRendabiliteit\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Kostprijs per stuk van een orderregel. Bron `bij_verkoop` is vastgelegd
 * toen de order binnenkwam en wordt nooit overschreven; `aangevuld` is
 * achteraf berekend met de kostprijs van dat moment. `opbouw` is de
 * uitsplitsing van de kostprijsbron (onderdeel → bedrag, excl. btw).
 */
class OrderregelKostprijs extends Model
{
    public const BRON_BIJ_VERKOOP = 'bij_verkoop';

    public const BRON_AANGEVULD = 'aangevuld';

    protected $table = 'orderregel_kostprijzen';

    protected $fillable = [
        'order_product_id',
        'order_id',
        'product_id',
        'kostprijs',
        'opbouw',
        'bron',
        'berekend_op',
    ];

    protected $casts = [
        'kostprijs' => 'float',
        'opbouw' => 'array',
        'berekend_op' => 'datetime',
    ];

    /** Eén onderdeel uit de opbouw (bijv. 'filament'), 0 als het ontbreekt. */
    public function onderdeel(string $naam): float
    {
        return (float) ($this->opbouw[$naam] ?? 0);
    }
}
