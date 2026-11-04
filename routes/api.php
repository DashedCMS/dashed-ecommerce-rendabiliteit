<?php

use Illuminate\Support\Facades\Route;
use Dashed\DashedEcommerceRendabiliteit\Http\Middleware\AdvertentieApiToken;
use Dashed\DashedEcommerceRendabiliteit\Http\Controllers\AdvertentieUitgavenController;

// Advertentie-uitgaven per dag (Meta, Google, Bol), voor de dagelijkse automatische import.
Route::prefix('api')->middleware(['api', AdvertentieApiToken::class, 'throttle:30,1'])->group(function () {
    Route::post('/advertentie-uitgaven', [AdvertentieUitgavenController::class, 'store']);
    Route::get('/advertentie-uitgaven', [AdvertentieUitgavenController::class, 'index']);
});
