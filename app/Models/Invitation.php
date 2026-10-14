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

    /** Adiciona a pessoa ao acervo com o perfil e as caixas do convite. */
    public function accept(User $user): void
    {
        $this->acervo->members()->syncWithoutDetaching([$user->id => [
            'role' => $this->role, 'status' => 'active', 'boxes' => $this->boxes, 'invited_by' => $this->invited_by,
        ]]);
        $this->update(['accepted_at' => now(), 'accepted_by' => $user->id]);
        ActivityLog::forceCreate([
            'acervo_id' => $this->acervo_id, 'type' => 'user', 'label' => "{$user->name} entrou pelo convite (".(Acervo::ROLES[$this->role] ?? $this->role).')',
            'user_id' => $user->id, 'user_name' => $user->name, 'created_at' => now(),
        ]);
    }
}
