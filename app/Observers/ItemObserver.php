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
        // numa caixa vinculada, a peça pertence ao acervo dono da caixa
        if ($item->box_id && ($home = Box::withoutGlobalScope('acervo')->whereKey($item->box_id)->value('acervo_id'))) {
            $item->acervo_id = $home;
        }
        $item->acervo_id ??= \App\Models\Acervo::current()?->id;
        $item->code ??= Item::nextCode($item->acervo_id);
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

        // mudou para uma caixa de outro acervo (vinculada) ou saiu dela: a peça passa a ser desse acervo
        if ($item->isDirty('box_id') && ! $item->isDirty('acervo_id')) {
            $to = $item->box_id
                ? Box::withoutGlobalScope('acervo')->whereKey($item->box_id)->value('acervo_id')
                : (\App\Models\Acervo::current()?->id ?? $item->acervo_id);
            if ($to && (int) $to !== (int) $item->acervo_id) {
                $item->acervo_id = $to;
                if (Item::withTrashed()->withoutGlobalScope('acervo')->where('acervo_id', $to)->where('code', $item->code)->whereKeyNot($item->id)->exists()) {
                    $item->code = Item::nextCode($to);
                }
                // tags são de cada acervo: recriadas no destino depois de salvar
                self::$retag[$item->id] = $item->tags()->pluck('name')->all();
            }
        }
    }

    /** @var array<int, string[]> */
    private static array $retag = [];

    public function updated(Item $item): void
    {
        if (array_key_exists($item->id, self::$retag)) {
            $item->syncTagNames(self::$retag[$item->id]);
            unset(self::$retag[$item->id]);
        }

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
