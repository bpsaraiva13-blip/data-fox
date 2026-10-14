<?php

namespace App\Policies;

use App\Models\ActivityLog;
use App\Models\User;

/** Histórico é somente leitura. */
class ActivityLogPolicy
{
    public function viewAny(User $user): bool { return true; }

    public function view(User $user, ActivityLog $record): bool { return true; }

    public function create(User $user): bool { return false; }

    public function update(User $user, ActivityLog $record): bool { return false; }

    public function delete(User $user, ActivityLog $record): bool { return false; }

    public function deleteAny(User $user): bool { return false; }
}
