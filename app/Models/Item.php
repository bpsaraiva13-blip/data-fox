<?php

namespace App\Models;

use App\Observers\ItemObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

#[ObservedBy(ItemObserver::class)]
class Item extends Model
{
    use SoftDeletes;

    protected $guarded = ['id', 'code', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'acquired_at' => 'date',
            'ai_keywords' => 'array',
            'ai_analyzed_at' => 'datetime',
            'merged_from' => 'array',
        ];
    }

    public function box(): BelongsTo
    {
        return $this->belongsTo(Box::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ItemPhoto::class)->orderBy('position');
    }

    public function cover(): HasOne
    {
        return $this->hasOne(ItemPhoto::class)->ofMany('position', 'min');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ActivityLog::class)->latest('created_at');
    }

    /** Próximo Nº de tombo do ano: ACV-2026-0001 (com lock para evitar colisão). */
    public static function nextCode(): string
    {
        $prefix = config('acervo.code_prefix').'-'.now()->year.'-';

        return DB::transaction(function () use ($prefix) {
            $last = static::withTrashed()
                ->where('code', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc('code')
                ->value('code');

            $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

            return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Busca textual por palavra (todas precisam aparecer), sem acento no PostgreSQL.
     * Procura em título, tombo, campos da ficha, palavras-chave da IA, tags e caixa.
     */
    public function scopeSearch(Builder $query, ?string $term, bool $any = false): Builder
    {
        $words = array_values(array_filter(preg_split('/\s+/', trim((string) $term)), fn ($w) => mb_strlen($w) > 1));
        if (! $words) {
            return $query;
        }

        $pg = DB::getDriverName() === 'pgsql';
        $col = fn (string $c) => $pg ? "unaccent(coalesce({$c}::text,''))" : "coalesce({$c},'')";

        $method = $any ? 'orWhere' : 'where';
        $query->where(function (Builder $outer) use ($words, $col, $pg, $method) {
            foreach ($words as $word) {
                $outer->{$method}(function (Builder $q) use ($word, $col, $pg) {
                    $like = '%'.$word.'%';
                    $expr = fn (string $c) => $pg ? "{$col($c)} ilike unaccent(?)" : "{$col($c)} like ?";
                    foreach (['title', 'code', 'category', 'era', 'origin', 'material', 'description', 'provenance', 'ai_keywords'] as $c) {
                        $q->orWhereRaw($expr($c), [$like]);
                    }
                    $q->orWhereHas('tags', fn (Builder $t) => $t->whereRaw($expr('name'), [$like]))
                        ->orWhereHas('box', fn (Builder $b) => $b->whereRaw($expr('name'), [$like]));
                });
            }
        });

        return $query;
    }

    /** Substitui as tags a partir de nomes livres, normalizando em slug. */
    public function syncTagNames(array $names): void
    {
        $ids = collect($names)
            ->map(fn ($n) => Tag::normalize($n))
            ->filter()
            ->unique()
            ->map(fn ($n) => Tag::firstOrCreate(['name' => $n])->id);

        $this->tags()->sync($ids);
    }
}
