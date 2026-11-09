<?php

namespace App\Support;

use App\Models\Acervo;
use App\Models\Membership;
use App\Models\User;

/** Vínculos pessoa ↔ acervo feitos pela administração do sistema (tela Usuários e /admin), com registro na Atividade. */
class Memberships
{
    /** Cria ou atualiza o vínculo. O dono do acervo é sempre administrador ativo, com todas as caixas. */
    public static function set(User $user, Acervo $acervo, array $m, User $by): void
    {
        $owner = $acervo->owner_id === $user->id;
        $role = $owner ? 'admin' : (array_key_exists($m['role'] ?? '', Acervo::ROLES) ? $m['role'] : 'viewer');
        $status = $owner ? 'active' : (($m['status'] ?? 'active') === 'blocked' ? 'blocked' : 'active');
        $boxes = $owner || ! isset($m['boxes']) || ! is_array($m['boxes']) ? null : array_values(array_unique(array_map('strval', $m['boxes'])));
        $row = Membership::where('acervo_id', $acervo->id)->where('user_id', $user->id)->first();
        $label = Acervo::ROLES[$role] ?? $role;
        if (! $row) {
            $acervo->members()->attach($user->id, ['role' => $role, 'status' => $status, 'boxes' => $boxes, 'invited_by' => $by->id]);
            PageStore::logActivity($acervo->id, $by, "Adicionou {$user->name} como {$label}");

            return;
        }
        $before = [$row->role, $row->status ?: 'active', $row->allowedBoxes()];
        $row->update(['role' => $role, 'status' => $status, 'boxes' => $boxes]);
        if ($before[0] !== $role) {
            PageStore::logActivity($acervo->id, $by, "Mudou o perfil de {$user->name} para {$label}");
        }
        if ($before[1] !== $status) {
            PageStore::logActivity($acervo->id, $by, ($status === 'blocked' ? 'Bloqueou ' : 'Desbloqueou ').$user->name);
        }
        if ($before[2] !== $boxes) {
            PageStore::logActivity($acervo->id, $by, "Mudou as caixas de {$user->name}");
        }
    }

    /** Tira a pessoa do acervo (nunca o dono). Devolve se havia vínculo. */
    public static function remove(User $user, Acervo $acervo, User $by): bool
    {
        if ($acervo->owner_id === $user->id) {
            return false;
        }
        $deleted = Membership::where('acervo_id', $acervo->id)->where('user_id', $user->id)->delete() > 0;
        if ($deleted) {
            PageStore::logActivity($acervo->id, $by, "Removeu {$user->name} do acervo");
        }

        return $deleted;
    }
}
