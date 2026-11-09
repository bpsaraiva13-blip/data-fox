<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Acervo;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/** Primeiro acesso (conta criada por um administrador) e redefinição pelo link gerado na tela Usuários. */
class WelcomeController extends Controller
{
    private function find(Request $request, string $token): ?User
    {
        $user = User::whereRaw('lower(email) = ?', [Str::lower((string) $request->query('email', $request->input('email')))])->first();

        return $user && $user->active && Password::broker('welcome')->tokenExists($user, $token) ? $user : null;
    }

    public function show(Request $request, string $token)
    {
        $user = $this->find($request, $token);
        if (! $user) {
            return response()->view('auth.welcome', ['state' => 'invalid'], 410);
        }
        $acervos = $user->acervos()->wherePivot('status', 'active')->orderBy('name')->get()
            ->map(fn ($a) => ['name' => $a->name, 'role' => Acervo::ROLES[$a->pivot->role] ?? $a->pivot->role]);

        return view('auth.welcome', ['state' => 'form', 'user' => $user, 'token' => $token, 'acervos' => $acervos]);
    }

    public function store(Request $request, string $token)
    {
        $user = $this->find($request, $token);
        if (! $user) {
            return response()->view('auth.welcome', ['state' => 'invalid'], 410);
        }
        $request->validate(['password' => ['required', 'confirmed', PasswordRule::min(8)]], [
            'password.required' => 'Crie uma senha.', 'password.confirmed' => 'As duas senhas não são iguais.', 'password.min' => 'A senha precisa de pelo menos 8 caracteres.',
        ]);
        $status = Password::broker('welcome')->reset(
            ['email' => $user->email, 'token' => $token, 'password' => $request->input('password')],
            function (User $u, string $password) {
                $u->forceFill(['password' => $password, 'remember_token' => Str::random(60), 'email_verified_at' => $u->email_verified_at ?? now()])->save();
            },
        );
        if ($status !== Password::PASSWORD_RESET) {
            return response()->view('auth.welcome', ['state' => 'invalid'], 410);
        }
        // com duas etapas ligada, entra pela tela normal (que pede o código)
        if (filled($user->getAppAuthenticationSecret())) {
            return redirect()->route('login')->with('status', 'Senha criada. Entre com ela para continuar.');
        }
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect('/');
    }
}
