<?php

use App\Http\Controllers\InvitationController;
use App\Http\Controllers\ShareImageController;
use App\Http\Controllers\ShowcaseController;
use Illuminate\Support\Facades\Route;

// A interface do Data Fox (a mesma da versão web) responde em "/"; a administração (Filament) em /admin.
use App\Http\Controllers\App as UI;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/', UI\AppController::class)->name('app');
    Route::post('/api/db/sync', [UI\DbController::class, 'sync'])->middleware('throttle:300,1')->name('api.db.sync');
    Route::post('/api/db/write', [UI\DbController::class, 'write'])->middleware('throttle:900,1')->name('api.db.write');
    Route::post('/api/assets', [UI\AssetController::class, 'upload'])->middleware('throttle:600,1')->name('api.assets.upload');
    Route::delete('/api/assets/{id}', [UI\AssetController::class, 'destroy'])->where('id', '[A-Za-z0-9_.\-]+')->name('api.assets.delete');
    Route::get('/_blob/{id}', [UI\AssetController::class, 'show'])->where('id', '[A-Za-z0-9_.\-]+')->name('blob');
    Route::post('/api/people/profiles', [UI\PeopleController::class, 'profiles'])->name('api.people.profiles');
    Route::get('/api/people/search', [UI\PeopleController::class, 'search'])->middleware('throttle:60,1')->name('api.people.search');
    Route::post('/api/site', [UI\SiteController::class, 'update'])->middleware('throttle:30,1')->name('api.site');
    Route::post('/api/ai/json', [UI\AiController::class, 'json'])->middleware('throttle:60,1')->name('api.ai');
});


Route::middleware('web')->group(function () {
    Route::get('/brand/icon', [UI\SiteController::class, 'icon'])->name('brand.icon');
    Route::get('/entrar', [\App\Http\Controllers\Auth\LoginController::class, 'show'])->name('login');
    Route::post('/entrar', [\App\Http\Controllers\Auth\LoginController::class, 'login'])->middleware('throttle:20,1');
    Route::get('/convite/{token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('/convite/{token}/aceitar', [InvitationController::class, 'accept'])->middleware('auth')->name('invitations.accept');
    Route::post('/convite/{token}/registrar', [InvitationController::class, 'register'])->middleware('throttle:10,1')->name('invitations.register');

    Route::get('/auth/{provider}/redirect', [\App\Http\Controllers\SocialLoginController::class, 'redirect'])->whereIn('provider', ['google', 'apple'])->name('social.redirect');
    Route::get('/auth/verificacao', [\App\Http\Controllers\SocialLoginController::class, 'challenge'])->name('social.2fa');
    Route::post('/auth/verificacao', [\App\Http\Controllers\SocialLoginController::class, 'verify'])->middleware('throttle:10,1')->name('social.2fa.verify');
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
