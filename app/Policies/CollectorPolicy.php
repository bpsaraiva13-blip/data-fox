<?php

namespace App\Policies;

use App\Models\Collector;
use App\Models\User;

/** Leitura para quem participa do acervo aberto; alterações para administradores e editores dele. */
class CollectorPolicy
{
    public function viewAny(User $user): bool { return $user->canBrowse(); }

    public function view(User $user, Collector $record): bool { return $user->canBrowse(); }

    public function create(User $user): bool { return $user->canEdit(); }

    public function update(User $user, Collector $record): bool { return $user->canEdit(); }

    public function delete(User $user, Collector $record): bool { return $user->canEdit(); }

    public function deleteAny(User $user): bool { return $user->canEdit(); }

    public function restore(User $user, Collector $record): bool { return $user->canEdit(); }

    public function restoreAny(User $user): bool { return $user->canEdit(); }

    public function forceDelete(User $user, Collector $record): bool { return $user->isAcervoAdmin(); }

    public function forceDeleteAny(User $user): bool { return $user->isAcervoAdmin(); }
}
