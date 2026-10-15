<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ItemPhoto extends Model
{
    use Prunable, SoftDeletes;

    protected $guarded = ['id'];

    /** Foto removida da ficha fica 30 dias na lixeira; depois o registro e os arquivos são apagados. */
    public function prunable(): Builder
    {
        return static::onlyTrashed()->where('deleted_at', '<', now()->subDays(30));
    }

    protected function pruning(): void
    {
        Storage::disk(config('acervo.disk'))->delete(array_filter([$this->path, $this->thumb_path]));
    }

    /** Volta para a ficha, no fim da ordem das fotos. */
    public function restoreToItem(): void
    {
        $this->position = (int) static::where('item_id', $this->item_id)->max('position') + 1;
        $this->restore();
    }

    protected function casts(): array
    {
        return ['color' => 'array'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }

    public function url(): string
    {
        return $this->temporaryOrPublicUrl($this->path);
    }

    public function thumbUrl(): string
    {
        return $this->temporaryOrPublicUrl($this->thumb_path ?: $this->path);
    }

    protected function temporaryOrPublicUrl(string $path): string
    {
        $disk = Storage::disk(config('acervo.disk'));

        try {
            return $disk->temporaryUrl($path, now()->addMinutes(30));
        } catch (\Throwable) {
            return $disk->url($path); // discos locais (desenvolvimento)
        }
    }
}
