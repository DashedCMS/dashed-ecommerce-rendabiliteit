<?php

use Dashed\DashedEcommerceRendabiliteit\Rendabiliteit;

if (! function_exists('rendabiliteit')) {
    function rendabiliteit(): Rendabiliteit
    {
        return app(Rendabiliteit::class);
    }
}
