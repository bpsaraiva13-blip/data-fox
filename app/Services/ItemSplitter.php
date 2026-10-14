<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Item;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Separa algumas fotos de uma peça em uma peça nova (o caminho inverso de "unir"). */
class ItemSplitter
{
    /** @param  int[]  $photoIds */
    public function split(Item $item, array $photoIds, string $title): Item
    {
        $moving = $item->photos()->whereIn('id', $photoIds)->get();
        if ($moving->isEmpty()) {
            throw new InvalidArgumentException('Escolha ao menos uma foto.');
        }
        if ($moving->count() >= $item->photos()->count()) {
            throw new InvalidArgumentException('Deixe ao menos uma foto na peça original.');
        }

        return DB::transaction(function () use ($item, $moving, $title) {
            $new = Item::create([
                'acervo_id' => $item->acervo_id,
                'title' => $title ?: $item->title.' (parte)',
                'category' => $item->category, 'era' => $item->era, 'origin' => $item->origin, 'material' => $item->material,
                'condition' => $item->condition, 'provenance' => $item->provenance, 'box_id' => $item->box_id,
                'split_from' => $item->code,
            ]);
            $new->tags()->sync($item->tags()->pluck('tags.id'));
            foreach ($moving->values() as $i => $p) {
                $p->update(['item_id' => $new->id, 'position' => $i]);
            }
            foreach ($item->photos()->get()->values() as $i => $p) {
                $p->update(['position' => $i]);
            }
            ActivityLog::record('update', $item->id, "{$item->code} — {$moving->count()} foto(s) separadas para {$new->code}");

            return $new;
        });
    }
}
