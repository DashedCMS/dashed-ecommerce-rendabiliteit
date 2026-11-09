<?php

use Illuminate\Support\Facades\Route;
use Dashed\DashedEcommerceRendabiliteit\Http\Controllers\GoogleKoppelingController;

// "Koppel met Google" op Rendabiliteit → Advertentiekoppelingen (OAuth voor de Google Ads-API).
Route::middleware(['web'])->prefix('rendabiliteit/google')->group(function () {
    Route::get('/koppelen', [GoogleKoppelingController::class, 'koppelen'])->name('rendabiliteit.google.koppelen');
    Route::get('/terug', [GoogleKoppelingController::class, 'terugVanGoogle'])->name('rendabiliteit.google.terug');
});
