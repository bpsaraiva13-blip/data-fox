<?php

namespace App\Support;

use App\Models\Box;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Rotas fora do painel (PDF, imagem, anexo) checam o acesso aqui. */
class ItemAccess
{
    public static function canSee(?User $user, Item $item): bool
    {
        if (static::seesIn($user, $item->acervo, $item->box_id)) {
            return true;
        }
        // peça de uma caixa vinculada a outro acervo do qual a pessoa participa
        if ($item->box_id) {
            foreach (\Illuminate\Support\Facades\DB::table('box_links')->where('box_id', $item->box_id)->pluck('acervo_id') as $aid) {
                if (static::seesIn($user, \App\Models\Acervo::find($aid), $item->box_id)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function seesIn(?User $user, ?\App\Models\Acervo $acervo, ?int $boxId): bool
    {
        $m = $acervo ? $user?->membership($acervo) : null;
        if (! $m || ! $m->isActive() || $m->role === 'showcase') {
            return false;
        }
        $boxes = $m->allowedBoxes();

        return $boxes === null || in_array($boxId ? (string) $boxId : 'none', $boxes, true);
    }

    public static function canSeeBox(?User $user, Box $box): bool
    {
        $acervos = collect([$box->acervo_id])->merge(\Illuminate\Support\Facades\DB::table('box_links')->where('box_id', $box->id)->pluck('acervo_id'));

        return $acervos->contains(fn ($aid) => static::seesIn($user, \App\Models\Acervo::find($aid), $box->id));
    }

    /** Restringe uma consulta de peças às caixas que a pessoa pode ver no acervo aberto. */
    public static function restrict(Builder $query, string $column = 'items.box_id', ?User $user = null): Builder
    {
        $boxes = ($user ?? auth()->user())?->membership()?->allowedBoxes();
        if ($boxes !== null) {
            $ids = array_values(array_filter($boxes, 'is_numeric'));
            $query->where(fn (Builder $w) => $w->whereIn($column, $ids ?: [0])
                ->when(in_array('none', $boxes, true), fn ($x) => $x->orWhereNull($column)));
        }

        return $query;
    }

    /** Restringe uma consulta de caixas às permitidas. */
    public static function restrictBoxes(Builder $query, ?User $user = null): Builder
    {
        $boxes = ($user ?? auth()->user())?->membership()?->allowedBoxes();

        return $boxes === null ? $query : $query->whereIn('boxes.id', array_values(array_filter($boxes, 'is_numeric')) ?: [0]);
    }
}
