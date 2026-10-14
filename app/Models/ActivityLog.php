<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAcervo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    use BelongsToAcervo;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    public static function record(string $type, ?int $itemId, string $label, ?array $changes = null, ?int $acervoId = null): self
    {
        return static::create([
            'acervo_id' => $acervoId ?? Acervo::current()?->id ?? ($itemId ? Item::withoutGlobalScopes()->whereKey($itemId)->value('acervo_id') : null),
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
