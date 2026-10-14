<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAcervo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/** Quem abriu fichas, gerou PDFs, etiquetas, exportou ou compartilhou. */
class AccessLog extends Model
{
    use BelongsToAcervo;

    public const UPDATED_AT = null;

    public const TYPES = ['view' => 'Abriu a ficha', 'pdf' => 'Gerou PDF', 'labels' => 'Gerou etiquetas', 'share' => 'Compartilhou', 'export' => 'Exportou', 'backup' => 'Backup', 'catalog' => 'Gerou catálogo'];

    protected $guarded = ['id'];

    /** Registra o acesso. Aberturas repetidas da mesma ficha em 10 minutos contam uma vez. */
    public static function log(string $type, ?Item $item = null, ?string $label = null, ?int $acervoId = null): void
    {
        $uid = auth()->id();
        if ($type === 'view' && $item && ! Cache::add("acc:{$uid}:{$item->id}", 1, 600)) {
            return;
        }
        static::create([
            'acervo_id' => $item?->acervo_id ?? $acervoId ?? Acervo::current()?->id,
            'user_id' => $uid, 'type' => $type, 'item_id' => $item?->id,
            'label' => $label ?? ($item ? "{$item->code} · {$item->title}" : null),
            'ip' => request()?->ip(),
        ]);
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
