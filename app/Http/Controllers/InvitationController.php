<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

/** Aceitar convite: quem já tem conta entra e aceita; quem não tem cria a senha na hora. */
class InvitationController extends Controller
{
    public function show(string $token)
    {
        $inv = Invitation::with('acervo', 'inviter')->where('token', $token)->first();
        if (! $inv || ! $inv->isPending()) {
            return view('public.invitation', ['inv' => $inv, 'state' => 'invalid']);
        }
        $user = Auth::user();
        $exists = $inv->email && User::whereRaw('lower(email) = ?', [mb_strtolower($inv->email)])->exists();
        // quem entrar ou criar a conta volta para este convite
        session()->put('url.intended', route('invitations.show', $inv->token));

        return view('public.invitation', [
            'inv' => $inv,
            'state' => $user ? 'accept' : ($exists ? 'login' : 'register'),
            'user' => $user,
            'loginUrl' => route('login'),
        ]);
    }

    public function accept(string $token)
    {
        $inv = Invitation::where('token', $token)->firstOrFail();
        abort_unless($inv->isPending(), 410, 'Este convite expirou ou já foi usado.');
        $inv->accept(Auth::user());

        return redirect('/?acervo='.$inv->acervo_id);
    }

    public function register(Request $request, string $token)
    {
        $inv = Invitation::where('token', $token)->firstOrFail();
        abort_unless($inv->isPending(), 410, 'Este convite expirou ou já foi usado.');
        abort_if($inv->email && User::whereRaw('lower(email) = ?', [mb_strtolower($inv->email)])->exists(), 409, 'Já existe uma conta com este e-mail. Entre para aceitar.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            // convite por link (sem e-mail): a pessoa informa o dela
            'email' => $inv->email ? ['nullable'] : ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ], ['email.unique' => 'Já existe uma conta com este e-mail. Use "Já tenho conta" para entrar e aceitar.']);
        $email = $inv->email ?: mb_strtolower($data['email']);
        $user = User::create(['name' => $data['name'], 'email' => $email, 'password' => $data['password'], 'role' => 'editor', 'active' => true]);
        // e-mail do convite = confirmado; e-mail digitado num convite por link não é
        $user->forceFill(['email_verified_at' => $inv->email ? now() : null])->save();
        Auth::login($user, remember: true);
        $request->session()->regenerate();
        $inv->accept($user);

        return redirect('/?acervo='.$inv->acervo_id);
    }
}
