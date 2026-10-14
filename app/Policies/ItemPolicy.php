<?php

namespace App\Policies;

use App\Models\Item;
use App\Models\User;

/** Leitura para quem participa do acervo aberto; alterações para administradores e editores dele. */
class ItemPolicy
{
    public function viewAny(User $user): bool { return $user->canBrowse(); }

    public function view(User $user, Item $record): bool { return $user->canBrowse(); }

    public function create(User $user): bool { return $user->canEdit(); }

    public function update(User $user, Item $record): bool { return $user->canEdit(); }

    public function delete(User $user, Item $record): bool { return $user->canEdit(); }

    public function deleteAny(User $user): bool { return $user->canEdit(); }

    public function restore(User $user, Item $record): bool { return $user->canEdit(); }

    public function restoreAny(User $user): bool { return $user->canEdit(); }

    public function forceDelete(User $user, Item $record): bool { return $user->isAcervoAdmin(); }

    public function forceDeleteAny(User $user): bool { return $user->isAcervoAdmin(); }
}
