<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\App\AiController;
use App\Http\Controllers\Controller;
use App\Support\PageStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * A interface do Data Fox (a mesma da versão web), servida pelo Laravel.
 * O arquivo resources/app/data-fox.html é a página; aqui entra o runtime que liga
 * a página ao servidor (public/js/datafox-runtime.js) e os dados de quem está logado.
 */
class AppController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        if (! $user->active) {
            Auth::logout();

            return redirect()->route('filament.admin.auth.login');
        }
        $html = file_get_contents(resource_path('app/data-fox.html'));
        $boot = [
            'user' => ['id' => PageStore::uid($user), 'name' => ($user->profile['name'] ?? '') ?: $user->name, 'email' => $user->email, 'avatarUrl' => '', 'isAdmin' => $user->isAdmin()],
            'brand' => \App\Support\Site::public(),
            'ai' => AiController::enabled() ? ['maxCount' => AiController::MAX_IMAGES] : null,
            'csrf' => csrf_token(),
            'urls' => [
                'account' => url('/admin/profile'), 'logout' => url('/admin/logout'), 'admin' => $user->isAdmin() ? url('/admin') : null,
            ],
        ];
        $v = @filemtime(public_path('js/datafox-runtime.js')) ?: time();
        $inject = '<meta name="csrf-token" content="'.e(csrf_token()).'">'
            .'<script>window.DATAFOX_SERVER='.json_encode($boot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP).';</script>'
            .'<script src="'.asset('js/datafox-runtime.js').'?v='.$v.'"></script>';
        // a página é o mesmo arquivo da versão web (sem <head>, como os artefatos): aqui ganha o esqueleto
        $html = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
            .'<meta name="robots" content="noindex"><link rel="icon" href="'.e(\App\Support\Site::iconUrl()).'">'
            .$inject.'</head><body>'.$html.'</body></html>';

        return response($html)->header('Content-Type', 'text/html; charset=utf-8')->header('Cache-Control', 'no-store');
    }
}
