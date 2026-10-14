<?php

namespace App\Policies;

use App\Models\AccessLog;
use App\Models\User;

/** Registro de acesso: só administradores do acervo veem; ninguém altera. */
class AccessLogPolicy
{
    public function viewAny(User $user): bool { return $user->isAcervoAdmin(); }

    public function view(User $user, AccessLog $record): bool { return $user->isAcervoAdmin(); }

    public function create(User $user): bool { return false; }

    public function update(User $user, AccessLog $record): bool { return false; }

    public function delete(User $user, AccessLog $record): bool { return false; }

    public function deleteAny(User $user): bool { return false; }
}
