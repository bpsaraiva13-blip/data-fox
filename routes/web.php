<?php

use App\Http\Controllers\InvitationController;
use App\Http\Controllers\ShareImageController;
use App\Http\Controllers\ShowcaseController;
use Illuminate\Support\Facades\Route;

// O painel Filament (Data Fox) responde na raiz do site.

Route::middleware('web')->group(function () {
    Route::get('/convite/{token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('/convite/{token}/aceitar', [InvitationController::class, 'accept'])->middleware('auth')->name('invitations.accept');
    Route::post('/convite/{token}/registrar', [InvitationController::class, 'register'])->middleware('throttle:10,1')->name('invitations.register');

    Route::get('/auth/{provider}/redirect', [\App\Http\Controllers\SocialLoginController::class, 'redirect'])->whereIn('provider', ['google', 'apple'])->name('social.redirect');
    Route::match(['get', 'post'], '/auth/{provider}/callback', [\App\Http\Controllers\SocialLoginController::class, 'callback'])->whereIn('provider', ['google', 'apple'])->name('social.callback');

    Route::get('/vitrine/{slug}', [ShowcaseController::class, 'index'])->name('showcase');
    Route::get('/vitrine/{slug}/{item}', [ShowcaseController::class, 'show'])->whereNumber('item')->name('showcase.item');
});

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/pecas/{item}/compartilhar.jpg', ShareImageController::class)->name('items.share-image');
    Route::get('/pdf/ficha/{item}', [\App\Http\Controllers\PdfController::class, 'ficha'])->name('pdf.ficha');
    Route::get('/pdf/etiquetas', [\App\Http\Controllers\PdfController::class, 'labels'])->name('pdf.labels');
    Route::get('/pdf/caixa/{box}', [\App\Http\Controllers\PdfController::class, 'boxLabel'])->name('pdf.box');
    Route::get('/pdf/catalogo', [\App\Http\Controllers\PdfController::class, 'catalog'])->name('pdf.catalog');
    Route::get('/anexo/{attachment}', [\App\Http\Controllers\PdfController::class, 'attachment'])->name('attachments.download');
});
