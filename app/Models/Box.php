<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Box extends Model
{
    protected $guarded = ['id', 'created_by'];

    protected static function booted(): void
    {
        static::creating(function (Box $box) {
            $box->created_by ??= auth()->id();
            $box->color ??= collect(config('acervo.box_colors'))->random();
        });
        static::created(function (Box $box) {
            if (! $box->import_ref) {
                ActivityLog::record('box', null, "Criou a caixa {$box->name}");
            }
        });
        static::updated(function (Box $box) {
            if ($box->wasChanged('name')) {
                ActivityLog::record('box', null, "Renomeou a caixa {$box->getOriginal('name')} para {$box->name}");
            }
        });
        static::deleted(fn (Box $box) => ActivityLog::record('box', null, "Excluiu a caixa {$box->name}"));
    }

    /** Número na frente do nome ("01 Prataria", "Caixa 10", "12. Bolas"), ou null. */
    public static function leadingNumber(?string $name): ?int
    {
        return preg_match('/^\s*(?:caixa|cx\.?)?\s*n?[º°o.]?\s*(\d+)/iu', (string) $name, $m) ? (int) $m[1] : null;
    }

    /** Ordena caixas numeradas primeiro (1, 2, 10…), depois as demais em ordem alfabética. */
    public function scopeNaturalOrder(Builder $query): Builder
    {
        if (DB::getDriverName() === 'pgsql') {
            return $query
                ->orderByRaw("nullif(substring(lower(name) from '^\\s*(?:caixa|cx\\.?)?\\s*n?[º°o.]?\\s*([0-9]+)'), '')::int asc nulls last")
                ->orderByRaw('lower(unaccent(name)) asc');
        }

        return $query->orderBy('name');
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
