<?php

namespace App\Services;

use App\Models\Item;
use App\Models\ItemPhoto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class PhotoManager
{
    /**
     * Sincroniza as fotos de uma peça a partir da lista ordenada de caminhos
     * vinda do FileUpload do Filament. Fotos novas ganham miniatura e assinatura.
     */
    public function sync(Item $item, array $paths, bool $log = true): void
    {
        $paths = array_values(array_filter($paths));
        $disk = Storage::disk(config('acervo.disk'));
        $existing = $item->photos()->get()->keyBy('path');

        foreach ($existing as $path => $photo) {
            if (! in_array($path, $paths, true)) {
                $disk->delete(array_filter([$photo->path, $photo->thumb_path]));
                $photo->delete();
            }
        }

        $added = 0;
        foreach ($paths as $i => $path) {
            if ($photo = $existing->get($path)) {
                $photo->update(['position' => $i]);

                continue;
            }

            $bytes = $disk->get($path);
            $sig = ImageSignature::fromBinary($bytes);
            $thumbPath = config('acervo.thumbs_dir').'/'.pathinfo($path, PATHINFO_FILENAME).'.jpg';
            $disk->put($thumbPath, ImageSignature::thumbnail($bytes, config('acervo.thumb_size')));

            $item->photos()->create([
                'path' => $path,
                'thumb_path' => $thumbPath,
                'hash' => $sig['hash'],
                'color' => $sig['color'],
                'width' => $sig['width'],
                'height' => $sig['height'],
                'position' => $i,
            ]);
            $added++;
        }

        if ($added && $log && ! $item->wasRecentlyCreated) {
            \App\Models\ActivityLog::record('update', $item->id, "{$item->title} — {$added} foto(s) adicionada(s)");
        }
    }

    /**
     * Peças mais parecidas com uma assinatura, uma entrada por peça (melhor foto).
     *
     * @return Collection<int, array{item:Item, photo:ItemPhoto, score:float}>
     */
    public function similar(array $sig, ?int $exceptItemId = null, int $limit = 8): Collection
    {
        return ItemPhoto::query()
            ->whereNotNull('hash')
            ->when($exceptItemId, fn ($q) => $q->where('item_id', '!=', $exceptItemId))
            ->whereHas('item', fn ($q) => \App\Support\ItemAccess::restrict($q))
            ->with('item.box')
            ->get()
            ->map(fn (ItemPhoto $p) => ['item' => $p->item, 'photo' => $p, 'score' => ImageSignature::similarity($sig, $p->hash, $p->color)])
            ->sortByDesc('score')
            ->unique(fn ($r) => $r['item']->id)
            ->take($limit)
            ->values();
    }
}
