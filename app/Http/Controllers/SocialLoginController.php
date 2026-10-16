<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Entrar com Google ou Apple. Só entra quem já tem conta (mesmo e-mail) ou tem
 * um convite pendente para esse e-mail — o sistema continua fechado para a equipe.
 */
class SocialLoginController extends Controller
{
    public const PROVIDERS = ['google' => 'Google', 'apple' => 'Apple'];

    public static function enabled(string $provider): bool
    {
        $c = config("services.{$provider}");

        return is_array($c) && filled($c['client_id'] ?? null)
            && (filled($c['client_secret'] ?? null) || ($provider === 'apple' && filled($c['private_key'] ?? null)));
    }

    public function redirect(Request $request, string $provider)
    {
        abort_unless(isset(self::PROVIDERS[$provider]) && static::enabled($provider), 404);
        if ($request->filled('convite')) {
            $request->session()->put('invite_token', $request->query('convite'));
        }

        return $this->driver($provider)->redirect();
    }

    public function callback(Request $request, string $provider)
    {
        abort_unless(isset(self::PROVIDERS[$provider]) && static::enabled($provider), 404);
        $fail = fn (string $msg) => redirect(Filament::getLoginUrl())->with('social_error', $msg);

        try {
            $social = $this->driver($provider)->user();
        } catch (Throwable $e) {
            report($e);

            return $fail('Não foi possível entrar com '.self::PROVIDERS[$provider].'. Tente de novo.');
        }

        $email = Str::lower((string) $social->getEmail());
        $col = "{$provider}_id";
        $user = User::where($col, $social->getId())->first()
            ?? ($email ? User::whereRaw('lower(email) = ?', [$email])->first() : null);

        $token = $request->session()->pull('invite_token');
        $invite = $token ? Invitation::where('token', $token)->first() : null;
        $invite ??= $email ? Invitation::whereRaw('lower(email) = ?', [$email])->get()->first(fn (Invitation $i) => $i->isPending()) : null;

        if (! $user) {
            if (! $invite || ! $invite->isPending()) {
                return $fail("Não há conta nem convite para {$email}. Peça um convite ao administrador do acervo.");
            }
            $user = User::create([
                'name' => $social->getName() ?: Str::before($email, '@'),
                'email' => $email ?: $invite->email,
                'password' => Str::password(32),     // entra só pelo provedor até definir uma senha no perfil
                'role' => 'editor',
                'active' => true,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        if (! $user->active) {
            return $fail('Seu acesso está desativado. Fale com o administrador.');
        }

        $user->forceFill([$col => $social->getId(), 'last_login_at' => now(), 'last_login_via' => $provider])->save();
        Auth::login($user, remember: false);
        $request->session()->regenerate();

        if ($invite && $invite->isPending()) {
            $invite->accept($user);

            return redirect(Filament::getUrl($invite->acervo));
        }

        return redirect()->intended(Filament::getUrl());
    }

    private function driver(string $provider)
    {
        $d = Socialite::driver($provider);
        if ($provider === 'apple') {
            // a Apple devolve por POST de outro site: sem sessão no retorno; o nonce vai num cookie criptografado
            $d = $d->stateless();
            if (method_exists($d, 'cookieNonce')) {
                $d = $d->cookieNonce();
            }
        }

        return $d;
    }
}
