<?php

namespace App\Observers;

use App\Models\ActivityLog;
use App\Models\Box;
use App\Models\Item;

class ItemObserver
{
    private const TRACKED = ['title', 'category', 'era', 'origin', 'material', 'dimensions', 'condition', 'value', 'description', 'provenance', 'acquired_at', 'notes', 'box_id'];

    public function creating(Item $item): void
    {
        $item->code ??= Item::nextCode();
        $item->created_by ??= auth()->id();
        $item->updated_by = auth()->id();
    }

    public function created(Item $item): void
    {
        if ($item->import_ref) {
            return;
        }
        ActivityLog::record('create', $item->id, "Cadastrou {$item->code} · {$item->title}");
    }

    public function updating(Item $item): void
    {
        $item->updated_by = auth()->id();
    }

    public function updated(Item $item): void
    {
        $changed = array_intersect(array_keys($item->getChanges()), self::TRACKED);
        if (! $changed) {
            return;
        }

        $diff = collect($changed)->mapWithKeys(fn ($f) => [$f => ['de' => $item->getOriginal($f), 'para' => $item->getAttribute($f)]])->all();

        if ($item->wasChanged('box_id')) {
            $box = $item->box_id ? Box::find($item->box_id)?->name : 'Sem caixa';
            ActivityLog::record('move', $item->id, "{$item->title} — moveu para {$box}", $diff);

            return;
        }

        $label = $item->wasChanged('title')
            ? "{$item->title} — renomeou de “{$item->getOriginal('title')}”"
            : "{$item->title} — ficha atualizada (".implode(', ', $changed).')';

        ActivityLog::record('update', $item->id, $label, $diff);
    }

    public function deleted(Item $item): void
    {
        ActivityLog::record('delete', $item->id, "Excluiu {$item->code} · {$item->title}");
    }
}
