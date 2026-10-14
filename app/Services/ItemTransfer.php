<?php

namespace App\Services;

use App\Models\Acervo;
use App\Models\ActivityLog;
use App\Models\Box;
use App\Models\DupIgnore;
use App\Models\Item;
use App\Models\ItemAttachment;
use App\Models\Movement;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Envia peças (ou uma caixa inteira) para outro acervo em que a pessoa também
 * é administradora ou editora. "move" tira do acervo de origem; "copy" duplica,
 * inclusive os arquivos das fotos e dos documentos.
 */
class ItemTransfer
{
    public const SAME_BOX = 'same';

    /** Acervos para onde a pessoa pode enviar a partir do acervo aberto. */
    public static function targets(?User $user, ?Acervo $from = null): Collection
    {
        $from ??= Acervo::current();
        if (! $user || ! $from?->exists || ! $user->canEdit($from)) {
            return collect();
        }

        return $user->acervos()->wherePivot('status', 'active')->wherePivotIn('role', ['admin', 'editor'])
            ->whereKeyNot($from->id)->orderBy('name')->get();
    }

    /**
     * @param  Collection<int, Item>  $items
     * @param  int|string|null  $box  id de caixa no destino, self::SAME_BOX ou null (sem caixa)
     * @return array{count:int, renumbered:int}
     */
    public function send(Collection $items, Acervo $target, string $mode = 'move', int|string|null $box = self::SAME_BOX, ?Box $sourceBox = null): array
    {
        $user = auth()->user();
        abort_unless($user && $user->canEdit($target), 403, 'Sem permissão de edição no acervo de destino.');
        if (! in_array($mode, ['move', 'copy'], true)) {
            throw new InvalidArgumentException('Modo inválido.');
        }
        $from = $sourceBox?->acervo ?? $items->first()?->acervo;
        if (! $from || $from->is($target)) {
            throw new InvalidArgumentException('Escolha um acervo diferente do atual.');
        }
        abort_unless($user->canEdit($from), 403);

        $boxMap = [];
        $renumbered = 0;
        $mapBox = function (?Box $src) use (&$boxMap, $target): ?int {
            if (! $src) {
                return null;
            }
            if (! array_key_exists($src->id, $boxMap)) {
                $found = Box::withoutGlobalScope('acervo')->where('acervo_id', $target->id)
                    ->whereRaw('lower(name) = ?', [mb_strtolower($src->name)])->first();
                if (! $found) {
                    $found = new Box(['name' => $src->name, 'location' => $src->location, 'color' => $src->color]);
                    $found->forceFill(['acervo_id' => $target->id, 'created_by' => auth()->id()])->save();
                }
                $boxMap[$src->id] = $found->id;
            }

            return $boxMap[$src->id];
        };
        $targetBox = is_numeric($box)
            ? Box::withoutGlobalScope('acervo')->where('acervo_id', $target->id)->findOrFail((int) $box)->id
            : null;

        DB::transaction(function () use ($items, $target, $from, $mode, $box, $targetBox, $mapBox, $sourceBox, &$renumbered) {
            foreach ($items as $item) {
                $item->loadMissing(['box', 'tags', 'photos', 'attachments']);
                $boxId = $box === self::SAME_BOX ? $mapBox($item->box) : $targetBox;
                $code = $item->code;
                if (Item::withTrashed()->withoutGlobalScope('acervo')->where('acervo_id', $target->id)->where('code', $code)->exists()) {
                    $code = Item::nextCode($target->id);
                    $renumbered++;
                }
                $origin = ['acervo' => $from->name, 'acervo_id' => $from->id, 'code' => $item->code, 'at' => now()->toIso8601String(), 'by' => auth()->user()->name];
                $tags = $item->tags->pluck('name')->all();

                if ($mode === 'move') {
                    $dest = $item;
                    $item->collections()->detach();
                    DupIgnore::withoutGlobalScope('acervo')->where('item_a', $item->id)->orWhere('item_b', $item->id)->delete();
                    $item->forceFill(['acervo_id' => $target->id, 'code' => $code, 'box_id' => $boxId, 'showcase' => false, 'transferred_from' => $origin])->saveQuietly();
                    Movement::withoutGlobalScope('acervo')->where('item_id', $item->id)->update(['acervo_id' => $target->id]);
                    ActivityLog::record('delete', null, "Enviou {$item->code} · {$item->title} para o acervo {$target->name}", acervoId: $from->id);
                } else {
                    $dest = $item->replicate(['acervo_id', 'code', 'box_id', 'showcase', 'import_ref', 'split_from']);
                    $dest->forceFill(['acervo_id' => $target->id, 'code' => $code, 'box_id' => $boxId, 'showcase' => false, 'transferred_from' => $origin,
                        'created_by' => auth()->id(), 'updated_by' => auth()->id()])->saveQuietly();
                    $this->copyFiles($item, $dest);
                    foreach (Movement::withoutGlobalScope('acervo')->where('item_id', $item->id)->get() as $mv) {
                        $mv->replicate()->forceFill(['acervo_id' => $target->id, 'item_id' => $dest->id])->saveQuietly();
                    }
                    ActivityLog::record('update', $item->id, "Copiou {$item->code} · {$item->title} para o acervo {$target->name}", acervoId: $from->id);
                }

                $dest->syncTagNames($tags);   // as tags são de cada acervo
                ActivityLog::record('create', $dest->id, "Recebeu {$code} · {$item->title} do acervo {$from->name}".($code !== $item->code ? " (antes {$item->code})" : ''), acervoId: $target->id);
            }

            if ($sourceBox) {
                $mapBox($sourceBox);
                if ($mode === 'move') {
                    $sourceBox->delete();
                }
                $verb = $mode === 'move' ? 'Enviou' : 'Copiou';
                ActivityLog::record('box', null, "{$verb} a caixa {$sourceBox->name} ({$items->count()} peças) para o acervo {$target->name}", acervoId: $from->id);
                ActivityLog::record('box', null, "Recebeu a caixa {$sourceBox->name} ({$items->count()} peças) do acervo {$from->name}", acervoId: $target->id);
            }
        });

        return ['count' => $items->count(), 'renumbered' => $renumbered];
    }

    /** Na cópia, cada acervo fica com os próprios arquivos (excluir num não apaga no outro). */
    private function copyFiles(Item $from, Item $to): void
    {
        $disk = Storage::disk(config('acervo.disk'));
        $dup = function (?string $path) use ($disk): ?string {
            if (! $path || ! $disk->exists($path)) {
                return null;
            }
            $new = dirname($path).'/'.Str::uuid().'.'.pathinfo($path, PATHINFO_EXTENSION);
            $disk->copy($path, $new);

            return $new;
        };

        foreach ($from->photos as $p) {
            if (! $path = $dup($p->path)) {
                continue;
            }
            $to->photos()->create([
                'path' => $path, 'thumb_path' => $dup($p->thumb_path), 'hash' => $p->hash, 'color' => $p->color,
                'width' => $p->width, 'height' => $p->height, 'position' => $p->position,
            ]);
        }
        foreach ($from->attachments as $a) {
            if ($path = $dup($a->path)) {
                ItemAttachment::create(['item_id' => $to->id, 'kind' => $a->kind, 'name' => $a->name, 'path' => $path, 'mime' => $a->mime, 'size' => $a->size, 'user_id' => auth()->id()]);
            }
        }
    }
}
