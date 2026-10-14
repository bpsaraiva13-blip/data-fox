<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAcervo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Conferência física de uma caixa. */
class Inventory extends Model
{
    use BelongsToAcervo;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['found' => 'array', 'extra' => 'array', 'summary' => 'array', 'finished_at' => 'datetime'];
    }

    public function box(): BelongsTo
    {
        return $this->belongsTo(Box::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Marca a peça como encontrada (ou como de outra caixa). Retorna true se é desta caixa. */
    public function scan(Item $item): bool
    {
        if ((int) $item->box_id === (int) $this->box_id) {
            $this->found = array_values(array_unique([...($this->found ?? []), $item->id]));
            $this->save();

            return true;
        }
        $this->extra = array_values(array_unique([...($this->extra ?? []), $item->id]));
        $this->save();

        return false;
    }

    /** Conferência aberta da pessoa no acervo atual. */
    public static function openFor(?User $user): ?self
    {
        return $user ? static::where('status', 'open')->where('user_id', $user->id)->latest()->first() : null;
    }
}
