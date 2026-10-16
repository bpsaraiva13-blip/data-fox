<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A tela de login do painel (/admin/login) vira a do Data Fox (/entrar). */
class UseDataFoxLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && $request->routeIs('filament.admin.auth.login')) {
            return redirect()->route('login');
        }

        return $next($request);
    }
}
