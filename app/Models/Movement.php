<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAcervo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Empréstimo, venda ou devolução de uma peça, com data e responsável. */
class Movement extends Model
{
    use BelongsToAcervo;

    public const KINDS = ['loan' => 'Empréstimo', 'sale' => 'Venda', 'return' => 'Devolução', 'undo' => 'Volta ao acervo'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(Collector::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Registra a movimentação e atualiza a situação da peça. */
    public static function register(Item $item, string $kind, string $date, string $person, ?string $notes = null, ?int $collectorId = null): self
    {
        $collectorId ??= Collector::findByName($person)?->id;   // liga ao cadastro de colecionadores pelo nome
        $m = static::create(['acervo_id' => $item->acervo_id, 'item_id' => $item->id, 'kind' => $kind, 'date' => $date, 'person' => $person, 'collector_id' => $collectorId, 'notes' => $notes, 'user_id' => auth()->id()]);
        $status = match ($kind) { 'loan' => 'loaned', 'sale' => 'sold', default => 'available' };
        $item->forceFill(['status' => $status, 'status_person' => $status === 'available' ? null : $person, 'status_date' => $date])->saveQuietly();
        ActivityLog::record('move', $item->id, "{$item->code} · ".self::KINDS[$kind]." — {$person}");

        return $m;
    }
}
