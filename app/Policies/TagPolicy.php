<?php

namespace App\Policies;

use App\Models\Tag;
use App\Models\User;

/** Leitura para todos os usuários ativos; alterações só para administradores e editores. */
class TagPolicy
{
    public function viewAny(User $user): bool { return true; }

    public function view(User $user, Tag $record): bool { return true; }

    public function create(User $user): bool { return $user->canEdit(); }

    public function update(User $user, Tag $record): bool { return $user->canEdit(); }

    public function delete(User $user, Tag $record): bool { return $user->canEdit(); }

    public function deleteAny(User $user): bool { return $user->canEdit(); }

    public function restore(User $user, Tag $record): bool { return $user->canEdit(); }

    public function restoreAny(User $user): bool { return $user->canEdit(); }

    public function forceDelete(User $user, Tag $record): bool { return $user->isAdmin(); }

    public function forceDeleteAny(User $user): bool { return $user->isAdmin(); }
}
