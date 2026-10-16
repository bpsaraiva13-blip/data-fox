<?php

namespace App\Http\Responses;

use Filament\Auth\Http\Responses\Contracts\LoginResponse as Contract;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/** Depois de entrar, todo mundo vai para o Data Fox (/); a administração fica em /admin. */
class LoginResponse implements Contract
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        return redirect()->intended(url('/'));
    }
}
