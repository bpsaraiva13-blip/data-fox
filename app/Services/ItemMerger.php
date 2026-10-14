<?php

namespace App\Services;

use App\Jobs\AnalyzeItem;
use App\Models\ActivityLog;
use App\Models\Item;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Une várias peças em uma só ficha (ex.: frente e verso cadastrados separados no lote).
 * A ficha principal mantém o tombo; campos vazios são completados; fotos e tags são somadas.
 */
class ItemMerger
{
    private const FILL = ['category', 'era', 'origin', 'material', 'dimensions', 'condition', 'value', 'description', 'provenance', 'acquired_at', 'notes', 'box_id'];

    /** @param  Collection<int, Item>  $items */
    public function merge(Item $main, Collection $items, bool $reanalyze = false): Item
    {
        $others = $items->reject(fn (Item $i) => $i->is($main))->values();
        if ($others->isEmpty()) {
            throw new InvalidArgumentException('Selecione ao menos duas peças.');
        }

        DB::transaction(function () use ($main, $others) {
            foreach (self::FILL as $field) {
                if (blank($main->{$field})) {
                    $main->{$field} = $others->pluck($field)->first(fn ($v) => filled($v));
                }
            }

            $main->merged_from = array_merge($main->merged_from ?? [], $others->map(fn (Item $o) => [
                'code' => $o->code, 'title' => $o->title, 'at' => now()->toIso8601String(), 'by' => auth()->user()?->name,
            ])->all());
            $main->save();

            $pos = (int) $main->photos()->max('position');
            foreach ($others as $o) {
                foreach ($o->photos as $p) {
                    $p->update(['item_id' => $main->id, 'position' => ++$pos]);
                }
                $main->tags()->syncWithoutDetaching($o->tags()->pluck('tags.id'));
            }

            ActivityLog::record('update', $main->id, "{$main->code} · {$main->title} — unida com ".$others->pluck('code')->implode(', '));
            foreach ($others as $o) {
                $o->delete();
                ActivityLog::record('delete', $o->id, "{$o->code} unida em {$main->code}");
            }
        });

        if ($reanalyze && app(CuratorAI::class)->enabled()) {
            $main->update(['ai_status' => 'pending']);
            AnalyzeItem::dispatch($main->id, true, 'As fotos mostram lados diferentes (frente, verso, partes) da mesma peça.');
        }

        return $main->refresh();
    }

    /**
     * Peças com mais chance de serem a outra parte desta: tombo vizinho, mesmo lote,
     * cadastradas no mesmo momento ou visualmente parecidas.
     *
     * @return Collection<int, array{item: Item, why: string}>
     */
    public function suggestions(Item $item, int $limit = 10): Collection
    {
        $scores = collect();
        $add = function (Item $i, float $s, string $why) use ($scores) {
            $cur = $scores->get($i->id, ['item' => $i, 'score' => 0, 'why' => $why]);
            $cur['score'] += $s;
            $scores->put($i->id, $cur);
        };

        if (preg_match('/^(.*-)(\d+)$/', (string) $item->code, $m)) {
            $n = (int) $m[2];
            $codes = collect([-2, -1, 1, 2])->map(fn ($d) => $m[1].str_pad((string) ($n + $d), strlen($m[2]), '0', STR_PAD_LEFT));
            Item::whereIn('code', $codes)->get()->each(fn (Item $i) => $add($i, 3 - abs(((int) substr($i->code, -strlen($m[2]))) - $n) * .5, 'tombo vizinho'));
        }
        if ($item->batch) {
            Item::where('batch', $item->batch)->whereKeyNot($item->id)->limit(30)->get()->each(fn (Item $i) => $add($i, 1.5, 'mesmo lote'));
        }
        Item::whereKeyNot($item->id)->whereBetween('created_at', [$item->created_at->copy()->subMinutes(15), $item->created_at->copy()->addMinutes(15)])
            ->limit(30)->get()->each(fn (Item $i) => $add($i, .8, 'cadastrada junto'));

        if ($cover = $item->cover) {
            app(PhotoManager::class)->similar(['hash' => $cover->hash, 'color' => $cover->color ?? [0, 0, 0]], $item->id, 5)
                ->where('score', '>=', .6)
                ->each(fn ($r) => $add($r['item'], $r['score'] * 2, round($r['score'] * 100).'% parecida'));
        }

        return $scores->sortByDesc('score')->take($limit)->values();
    }
}
