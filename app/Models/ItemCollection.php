<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAcervo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Coleção temática (cruza caixas). */
class ItemCollection extends Model
{
    use BelongsToAcervo;

    protected $table = 'collections';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn (ItemCollection $c) => $c->created_by ??= auth()->id());
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'collection_item', 'collection_id', 'item_id');
    }
}
