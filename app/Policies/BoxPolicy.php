<?php

namespace App\Policies;

use App\Models\Box;
use App\Models\User;

/** Leitura para todos os usuários ativos; alterações só para administradores e editores. */
class BoxPolicy
{
    public function viewAny(User $user): bool { return true; }

    public function view(User $user, Box $record): bool { return true; }

    public function create(User $user): bool { return $user->canEdit(); }

    public function update(User $user, Box $record): bool { return $user->canEdit(); }

    public function delete(User $user, Box $record): bool { return $user->canEdit(); }

    public function deleteAny(User $user): bool { return $user->canEdit(); }

    public function restore(User $user, Box $record): bool { return $user->canEdit(); }

    public function restoreAny(User $user): bool { return $user->canEdit(); }

    public function forceDelete(User $user, Box $record): bool { return $user->isAdmin(); }

    public function forceDeleteAny(User $user): bool { return $user->isAdmin(); }
}
