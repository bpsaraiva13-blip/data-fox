<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Invitation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['boxes' => 'array', 'expires_at' => 'datetime', 'accepted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (Invitation $i) {
            $i->token ??= Str::random(48);
            $i->expires_at ??= now()->addDays(7);
            $i->invited_by ??= auth()->id();
        });
    }

    public function acervo(): BelongsTo
    {
        return $this->belongsTo(Acervo::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isPending(): bool
    {
        return ! $this->accepted_at && ! $this->revoked_at && $this->expires_at->isFuture();
    }

    public function url(): string
    {
        return route('invitations.show', $this->token);
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    /** Ordem dos perfis, do maior para o menor. */
    private const RANK = ['admin' => 4, 'editor' => 3, 'viewer' => 2, 'showcase' => 1];

    /**
     * Adiciona a pessoa ao acervo com o perfil e as caixas do convite.
     * Quem já participa nunca perde perfil nem caixas por aceitar um convite menor,
     * e quem foi bloqueado continua bloqueado.
     */
    public function accept(User $user): void
    {
        $current = $this->acervo->membershipFor($user);
        if ($current && $current->status === 'blocked') {
            abort(403, 'Seu acesso a este acervo foi bloqueado pelo administrador.');
        }
        if ($current) {
            $keepRole = (self::RANK[$current->role] ?? 0) >= (self::RANK[$this->role] ?? 0);
            $boxes = $current->allowedBoxes() === null || $this->boxes === null
                ? null
                : array_values(array_unique(array_merge($current->allowedBoxes(), $this->boxes)));
            $current->update(['role' => $keepRole ? $current->role : $this->role, 'boxes' => $boxes]);
        } else {
            $this->acervo->members()->attach($user->id, [
                'role' => $this->role, 'status' => 'active', 'boxes' => $this->boxes, 'invited_by' => $this->invited_by,
            ]);
        }
        $this->update(['accepted_at' => now(), 'accepted_by' => $user->id]);
        ActivityLog::forceCreate([
            'acervo_id' => $this->acervo_id, 'type' => 'user', 'label' => "{$user->name} entrou pelo convite (".(Acervo::ROLES[$this->role] ?? $this->role).')',
            'user_id' => $user->id, 'user_name' => $user->name, 'created_at' => now(),
        ]);
    }
}
