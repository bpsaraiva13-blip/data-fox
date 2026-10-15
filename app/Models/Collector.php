<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAcervo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Colecionador, comprador, vendedor ou antiquário: contato ligado às movimentações. */
class Collector extends Model
{
    use BelongsToAcervo, Prunable, SoftDeletes;

    public const KINDS = [
        'collector' => 'Colecionador',
        'buyer' => 'Comprador',
        'seller' => 'Vendedor / fornecedor',
        'dealer' => 'Antiquário / loja',
        'other' => 'Outro',
    ];

    public const UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['interests' => 'array', 'birth' => 'date'];
    }

    protected static function booted(): void
    {
        static::creating(fn (Collector $c) => $c->created_by ??= auth()->id());
        static::saving(function (Collector $c) {
            $c->phone = static::formatPhone($c->phone);
            $c->phone2 = static::formatPhone($c->phone2);
            $c->cep = $c->cep ? preg_replace('/\D/', '', $c->cep) : null;
        });
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Lixeira: apagado de vez 30 dias depois de excluído. */
    public function prunable(): Builder
    {
        return static::withoutGlobalScope('acervo')->onlyTrashed()->where('deleted_at', '<', now()->subDays(30));
    }

    public static function formatPhone(?string $v): ?string
    {
        $d = preg_replace('/\D/', '', (string) $v);
        if ($d === '') {
            return null;
        }
        if (strlen($d) > 11 && str_starts_with($d, '55')) {
            $d = substr($d, 2);
        }

        return match (strlen($d)) {
            11 => sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7)),
            10 => sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6)),
            default => $v,
        };
    }

    public function whatsappUrl(): ?string
    {
        $d = preg_replace('/\D/', '', (string) $this->phone);
        if ($d === '') {
            return null;
        }

        return 'https://wa.me/'.(strlen($d) <= 11 ? '55'.$d : $d);
    }

    public function address(): string
    {
        return collect([
            $this->street ? $this->street.($this->number ? ', '.$this->number : '').($this->complement ? ' — '.$this->complement : '') : null,
            $this->district,
            collect([$this->city, $this->uf])->filter()->implode('/'),
            $this->cep ? 'CEP '.substr($this->cep, 0, 5).'-'.substr($this->cep, 5) : null,
        ])->filter()->implode(' · ');
    }

    public function mapsUrl(): ?string
    {
        $q = collect([$this->street, $this->number, $this->district, $this->city, $this->uf, $this->cep, 'Brasil'])->filter()->implode(', ');

        return $this->street || $this->city ? 'https://www.google.com/maps/search/?api=1&query='.urlencode($q) : null;
    }

    /** Procura pelo nome ou apelido (sem acento, sem diferenciar maiúsculas). */
    public static function findByName(?string $name): ?self
    {
        $n = mb_strtolower(trim((string) $name));

        return $n === '' ? null : static::query()->get()->first(
            fn (Collector $c) => \Illuminate\Support\Str::ascii(mb_strtolower($c->name)) === \Illuminate\Support\Str::ascii($n)
                || ($c->nick && \Illuminate\Support\Str::ascii(mb_strtolower($c->nick)) === \Illuminate\Support\Str::ascii($n))
        );
    }
}
