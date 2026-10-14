<?php

use App\Http\Controllers\ShareImageController;
use Illuminate\Support\Facades\Route;

// O painel Filament (Data Fox) responde na raiz do site.
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/pecas/{item}/compartilhar.jpg', ShareImageController::class)->name('items.share-image');
});
