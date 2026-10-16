<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Support\Facades\RateLimiter;
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
        // o Google informa se o e-mail foi confirmado; sem isso não dá para confiar nele
        if ($provider === 'google' && ($social->user['email_verified'] ?? $social->user['verified_email'] ?? true) === false) {
            return $fail('Confirme o seu e-mail na conta Google antes de entrar.');
        }
        $col = "{$provider}_id";
        $user = User::where($col, $social->getId())->first();
        if (! $user && $email) {
            $user = User::whereRaw('lower(email) = ?', [$email])->first();
            // só liga o Google/Apple a uma conta existente se o e-mail dela já foi confirmado
            // (evita que alguém troque o e-mail do próprio perfil para o de outra pessoa e "herde" o login dela)
            if ($user && ! $user->email_verified_at) {
                return $fail('Esta conta ainda não confirmou o e-mail. Entre com a senha ou peça ao administrador para liberar o login com '.self::PROVIDERS[$provider].'.');
            }
        }

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

        $user->forceFill([$col => $social->getId()])->save();

        // com verificação em duas etapas ligada, o Google/Apple não dispensam o código do app
        if (filled($user->getAppAuthenticationSecret())) {
            $request->session()->put('social_2fa', [
                'user' => $user->id, 'via' => $provider, 'invite' => $invite?->isPending() ? $invite->id : null, 'at' => now()->timestamp,
            ]);

            return redirect()->route('social.2fa');
        }

        return $this->finish($request, $user, $provider, $invite);
    }

    /** Pede o código do app autenticador (ou um código de recuperação). */
    public function challenge(Request $request)
    {
        $pending = $this->pending($request);
        if (! $pending) {
            return redirect(Filament::getLoginUrl());
        }

        return view('public.two-factor', ['via' => self::PROVIDERS[$pending['via']] ?? '']);
    }

    public function verify(Request $request)
    {
        $pending = $this->pending($request);
        if (! $pending) {
            return redirect(Filament::getLoginUrl());
        }
        $data = $request->validate(['code' => ['required', 'string', 'max:64']]);
        $user = User::find($pending['user']);
        if (! $user || ! $user->active) {
            $request->session()->forget('social_2fa');

            return redirect(Filament::getLoginUrl());
        }

        $key = 'social-2fa:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['code' => 'Muitas tentativas. Aguarde '.RateLimiter::availableIn($key).' segundos.']);
        }

        $code = preg_replace('/\s+/', '', $data['code']);
        $mfa = AppAuthentication::make();
        $ok = (ctype_digit($code) && strlen($code) === 6)
            ? $mfa->verifyCode($code, $user->getAppAuthenticationSecret(), shouldPreventCodeReuse: true)
            : $mfa->verifyRecoveryCode($data['code'], $user);
        if (! $ok) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['code' => 'Código inválido. Confira o app autenticador.']);
        }
        RateLimiter::clear($key);
        $request->session()->forget('social_2fa');
        $invite = $pending['invite'] ? Invitation::find($pending['invite']) : null;

        return $this->finish($request, $user, $pending['via'], $invite);
    }

    /** Pendência de 2 etapas guardada na sessão (vale por 10 minutos). */
    private function pending(Request $request): ?array
    {
        $p = $request->session()->get('social_2fa');
        if (! is_array($p) || now()->timestamp - ($p['at'] ?? 0) > 600) {
            $request->session()->forget('social_2fa');

            return null;
        }

        return $p;
    }

    private function finish(Request $request, User $user, string $provider, ?Invitation $invite)
    {
        $user->forceFill(['last_login_at' => now(), 'last_login_via' => $provider])->save();
        Auth::login($user, remember: false);
        $request->session()->regenerate();

        if ($invite && $invite->isPending()) {
            $invite->accept($user);

            return redirect('/?acervo='.$invite->acervo_id);
        }

        return redirect()->intended(url('/'));
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
