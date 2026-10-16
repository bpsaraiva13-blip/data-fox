<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * O painel /admin é a área de administração do sistema. Quem não é administrador usa só
 * as telas da própria conta (senha, duas etapas, e-mail) e volta para o Data Fox (/).
 */
class AdminAreaOnly
{
    private const OPEN = ['filament.admin.auth.*', 'filament.admin.pages.auth.*', 'filament.admin.auth.profile', 'filament.admin.auth.logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->isAdmin() && ! $request->routeIs(...self::OPEN) && ! str_contains((string) $request->route()?->getName(), 'email-change-verification')
            && ! $request->is('livewire/*')) {
            return redirect('/');
        }

        return $next($request);
    }
}
