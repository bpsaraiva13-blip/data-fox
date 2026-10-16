<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Tela de entrar com o visual do Data Fox (e-mail e senha, Google, Apple).
 * Com a verificação em duas etapas ligada, pede o código do app antes de entrar
 * (mesma tela usada pelo login com Google/Apple).
 */
class LoginController extends Controller
{
    public function show(Request $request)
    {
        if (Auth::check()) {
            return redirect()->intended(url('/'));
        }

        return view('auth.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'max:200'],
        ], ['email.required' => 'Digite o seu e-mail.', 'email.email' => 'Esse e-mail não parece válido.', 'password.required' => 'Digite a senha.']);

        $email = Str::lower(trim($data['email']));
        $key = 'login:'.sha1($email.'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['email' => 'Muitas tentativas. Tente de novo em '.RateLimiter::availableIn($key).' segundos.'])->onlyInput('email');
        }

        $user = User::whereRaw('lower(email) = ?', [$email])->first();
        // compara mesmo sem usuário, para o tempo de resposta não revelar quais e-mails existem
        $ok = Hash::check($data['password'], $user?->password ?: Hash::make(Str::random(16)));
        if (! $user || ! $ok) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['email' => 'E-mail ou senha incorretos.'])->onlyInput('email');
        }
        if (! $user->active) {
            return back()->withErrors(['email' => 'Seu acesso está desativado. Fale com o administrador.'])->onlyInput('email');
        }
        RateLimiter::clear($key);
        $remember = $request->boolean('remember');

        if (filled($user->getAppAuthenticationSecret())) {
            $request->session()->put('social_2fa', ['user' => $user->id, 'via' => 'password', 'invite' => null, 'remember' => $remember, 'at' => now()->timestamp]);

            return redirect()->route('social.2fa');
        }

        Auth::login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended(url('/'));
    }
}
