<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** Nota fiscal, certificado, laudo… ligado a uma peça. */
class ItemAttachment extends Model
{
    public const KINDS = ['Nota fiscal', 'Certificado', 'Laudo de autenticidade', 'Contrato', 'Outro'];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn (ItemAttachment $a) => $a->user_id ??= auth()->id());
        static::deleted(fn (ItemAttachment $a) => Storage::disk(config('acervo.disk'))->delete($a->path));
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
