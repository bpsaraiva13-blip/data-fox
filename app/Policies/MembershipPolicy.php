<?php

namespace App\Policies;

use App\Models\Membership;
use App\Models\User;

/** Só administradores do acervo gerenciam a equipe; o dono não pode ser alterado nem removido. */
class MembershipPolicy
{
    public function viewAny(User $user): bool { return $user->isAcervoAdmin(); }

    public function view(User $user, Membership $m): bool { return $user->isAcervoAdmin(); }

    public function create(User $user): bool { return $user->isAcervoAdmin(); }

    public function update(User $user, Membership $m): bool { return $user->isAcervoAdmin() && $m->acervo->owner_id !== $m->user_id; }

    public function delete(User $user, Membership $m): bool { return $user->isAcervoAdmin() && $m->acervo->owner_id !== $m->user_id; }

    public function deleteAny(User $user): bool { return false; }
}
