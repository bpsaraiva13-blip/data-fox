<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    public static function record(string $type, ?int $itemId, string $label, ?array $changes = null): self
    {
        return static::create([
            'type' => $type,
            'item_id' => $itemId,
            'label' => $label,
            'changes' => $changes,
            'user_id' => auth()->id(),
            'user_name' => auth()->user()?->name,
        ]);
    }

    /** Nome de quem fez a ação (usuário atual ou nome histórico importado). */
    public function getActorAttribute(): string
    {
        return $this->user?->name ?? $this->user_name ?? 'Sistema';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }
}
