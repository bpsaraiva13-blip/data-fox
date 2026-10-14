<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ItemPhoto extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['color' => 'array'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
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
