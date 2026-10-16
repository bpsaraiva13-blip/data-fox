<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/** Cria (ou promove) o primeiro administrador. */
class CreateAdmin extends Command
{
    protected $signature = 'datafox:admin {--name=} {--email=} {--password=}';

    protected $description = 'Cria um usuário administrador do Data Fox';

    public function handle(): int
    {
        $name = $this->option('name') ?: text('Nome', required: true);
        $email = $this->option('email') ?: text('E-mail', required: true, validate: fn ($v) => filter_var($v, FILTER_VALIDATE_EMAIL) ? null : 'E-mail inválido');
        $pass = $this->option('password') ?: password('Senha (mín. 8 caracteres)', required: true, validate: fn ($v) => strlen($v) >= 8 ? null : 'Use ao menos 8 caracteres');

        $user = User::updateOrCreate(['email' => $email], ['name' => $name, 'password' => $pass, 'role' => 'admin', 'active' => true]);
        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();
        $this->info("Administrador pronto: {$user->email}");

        return self::SUCCESS;
    }
}
