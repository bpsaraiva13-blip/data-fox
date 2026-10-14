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
        $exists = User::where('email', $inv->email)->exists();

        return view('public.invitation', [
            'inv' => $inv,
            'state' => $user ? 'accept' : ($exists ? 'login' : 'register'),
            'user' => $user,
            'loginUrl' => Filament::getLoginUrl(),
        ]);
    }

    public function accept(string $token)
    {
        $inv = Invitation::where('token', $token)->firstOrFail();
        abort_unless($inv->isPending(), 410, 'Este convite expirou ou já foi usado.');
        $inv->accept(Auth::user());

        return redirect(Filament::getUrl($inv->acervo));
    }

    public function register(Request $request, string $token)
    {
        $inv = Invitation::where('token', $token)->firstOrFail();
        abort_unless($inv->isPending(), 410, 'Este convite expirou ou já foi usado.');
        abort_if(User::where('email', $inv->email)->exists(), 409, 'Já existe uma conta com este e-mail. Entre para aceitar.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);
        $user = User::create(['name' => $data['name'], 'email' => $inv->email, 'password' => $data['password'], 'role' => 'editor', 'active' => true]);
        $user->forceFill(['email_verified_at' => now()])->save();
        Auth::login($user, remember: true);
        $inv->accept($user);

        return redirect(Filament::getUrl($inv->acervo));
    }
}
