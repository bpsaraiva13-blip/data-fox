<?php

namespace App\Policies;

use App\Models\Invitation;
use App\Models\User;

class InvitationPolicy
{
    public function viewAny(User $user): bool { return $user->isAcervoAdmin(); }

    public function view(User $user, Invitation $i): bool { return $user->isAcervoAdmin(); }

    public function create(User $user): bool { return $user->isAcervoAdmin(); }

    public function update(User $user, Invitation $i): bool { return $user->isAcervoAdmin(); }

    public function delete(User $user, Invitation $i): bool { return $user->isAcervoAdmin(); }

    public function deleteAny(User $user): bool { return $user->isAcervoAdmin(); }
}
