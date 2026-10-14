<?php

namespace App\Models\Concerns;

use App\Models\Acervo;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro que pertence a um acervo. Dentro do painel, as consultas ficam
 * restritas ao acervo aberto e novos registros recebem o acervo automaticamente.
 * Em comandos e filas (sem acervo aberto) nada é filtrado.
 */
trait BelongsToAcervo
{
    public static function bootBelongsToAcervo(): void
    {
        static::addGlobalScope('acervo', function (Builder $q) {
            if ($tenant = Acervo::current()) {
                $model = $q->getModel();
                if (method_exists($model, 'scopeToAcervo')) {
                    $model->scopeToAcervo($q, (int) $tenant->getKey());   // caixas vinculadas
                } else {
                    $q->where($model->getTable().'.acervo_id', $tenant->getKey());
                }
            }
        });

        static::creating(function ($model) {
            $model->acervo_id ??= Acervo::current()?->getKey();
        });
    }

    public function acervo(): BelongsTo
    {
        return $this->belongsTo(Acervo::class);
    }
}
