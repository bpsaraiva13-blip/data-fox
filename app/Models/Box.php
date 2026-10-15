<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAcervo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Box extends Model
{
    use BelongsToAcervo, Prunable, SoftDeletes;

    protected $guarded = ['id', 'created_by'];

    protected function casts(): array
    {
        return ['trashed_item_ids' => 'array'];
    }

    public function prunable(): Builder
    {
        return static::withoutGlobalScope('acervo')->onlyTrashed()->where('deleted_at', '<', now()->subDays(30));
    }

    /** Restaura a caixa e devolve para ela as peças que ainda estão sem caixa. */
    public function restoreWithItems(): int
    {
        $this->restore();
        $ids = $this->trashed_item_ids ?? [];
        $n = $ids ? Item::withoutGlobalScope('acervo')->whereIn('id', $ids)->whereNull('box_id')->update(['box_id' => $this->id]) : 0;
        $this->forceFill(['trashed_item_ids' => null])->saveQuietly();

        return $n;
    }

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
        // na exclusão (lixeira) as peças ficam "Sem caixa", mas guardamos quais eram para restaurar
        static::deleting(function (Box $box) {
            if ($box->isForceDeleting()) {
                return;
            }
            $ids = Item::withoutGlobalScope('acervo')->where('box_id', $box->id)->pluck('id')->all();
            $box->forceFill(['trashed_item_ids' => $ids])->saveQuietly();
            Item::withoutGlobalScope('acervo')->whereIn('id', $ids)->update(['box_id' => null]);
        });
        static::deleted(fn (Box $box) => $box->isForceDeleting() ? null : ActivityLog::record('box', null, "Excluiu a caixa {$box->name} (lixeira)"));
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

    /** Caixas do acervo e caixas de outros acervos vinculadas a ele. */
    public function scopeToAcervo(Builder $q, int $acervoId): void
    {
        $q->where(fn (Builder $w) => $w->where('boxes.acervo_id', $acervoId)
            ->orWhereIn('boxes.id', DB::table('box_links')->select('box_id')->where('acervo_id', $acervoId)));
    }

    /** Acervos (além do dono) onde esta caixa também aparece. */
    public function linkedAcervos(): BelongsToMany
    {
        return $this->belongsToMany(Acervo::class, 'box_links')->withPivot('created_by')->withTimestamps();
    }

    /** A caixa veio de outro acervo (vínculo)? */
    public function isLinkedHere(?Acervo $acervo = null): bool
    {
        $acervo ??= Acervo::current();

        return $acervo && (int) $this->acervo_id !== (int) $acervo->id;
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
