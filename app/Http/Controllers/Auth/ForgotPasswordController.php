<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\App\UsersController;
use App\Http\Controllers\Controller;
use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/** "Esqueci a senha" com o visual do Data Fox: link de 60 minutos por e-mail (ou orientação para pedir ao administrador). */
class ForgotPasswordController extends Controller
{
    public function show()
    {
        return view('auth.forgot', ['mail' => UsersController::mailReady()]);
    }

    public function send(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:190']], ['email.required' => 'Digite o seu e-mail.', 'email.email' => 'Esse e-mail não parece válido.']);
        if (! UsersController::mailReady()) {
            return back();
        }
        $email = Str::lower(trim($data['email']));
        $key = 'forgot:'.sha1($email.'|'.$request->ip());
        if (! RateLimiter::tooManyAttempts($key, 3)) {
            RateLimiter::hit($key, 600);
            $user = User::whereRaw('lower(email) = ?', [$email])->where('active', true)->first();
            if ($user) {
                $token = Password::broker('users')->createToken($user);
                $link = route('welcome', ['token' => $token, 'email' => $user->email, 'r' => 1]);
                rescue(fn () => Mail::to($user->email)->send(new PasswordResetMail($user, $link)));
            }
        }

        // a mesma resposta exista ou não a conta
        return back()->with('status', 'Se houver uma conta com este e-mail, enviamos um link para criar uma nova senha. Ele vale 60 minutos.');
    }
}
