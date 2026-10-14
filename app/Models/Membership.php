<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** Participação de uma pessoa num acervo: perfil, situação e caixas permitidas. */
class Membership extends Pivot
{
    protected $table = 'acervo_user';

    public $incrementing = true;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['boxes' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function acervo(): BelongsTo
    {
        return $this->belongsTo(Acervo::class);
    }

    public function isActive(): bool
    {
        return $this->status !== 'blocked';
    }

    public function canEdit(): bool
    {
        return $this->isActive() && in_array($this->role, ['admin', 'editor'], true);
    }

    /** Null = todas as caixas. Caso contrário, ids permitidos (e "none" para peças sem caixa). */
    public function allowedBoxes(): ?array
    {
        return is_array($this->boxes) ? array_values($this->boxes) : null;
    }
}
