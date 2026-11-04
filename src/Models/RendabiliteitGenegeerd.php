<?php

namespace Dashed\DashedEcommerceRendabiliteit\Models;

use Illuminate\Database\Eloquent\Model;

/** Een genegeerd aandachtspunt van het rendabiliteitsrapport ("Wat mist"), op een stabiele sleutel. */
class RendabiliteitGenegeerd extends Model
{
    protected $table = 'rendabiliteit_genegeerd';

    protected $fillable = ['sleutel', 'omschrijving', 'user_id'];
}
