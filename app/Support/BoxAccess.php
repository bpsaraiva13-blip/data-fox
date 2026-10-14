<?php

namespace App\Support;

use App\Models\Box;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;

/** Campos de formulário para escolher as caixas que uma pessoa vê e edita. */
class BoxAccess
{
    public static function fields(): array
    {
        return [
            Toggle::make('all_boxes')->label('Todas as caixas (inclusive as que forem criadas)')->default(true)->live(),
            CheckboxList::make('boxes')->label('Caixas permitidas')->columns(3)->searchable()->bulkToggleable()
                ->options(fn () => ['none' => 'Sem caixa'] + Box::naturalOrder()->pluck('name', 'id')->mapWithKeys(fn ($n, $id) => [(string) $id => $n])->all())
                ->visible(fn (Get $get) => ! $get('all_boxes'))
                ->required(fn (Get $get) => ! $get('all_boxes')),
        ];
    }

    /** Converte o estado do formulário para o valor salvo (null = todas). */
    public static function value(array $data): ?array
    {
        return ($data['all_boxes'] ?? true) ? null : array_values(array_map('strval', $data['boxes'] ?? []));
    }

    public static function fill(?array $boxes): array
    {
        return ['all_boxes' => $boxes === null, 'boxes' => $boxes ?? []];
    }

    public static function label(?array $boxes): string
    {
        if ($boxes === null) {
            return 'Todas as caixas';
        }
        $names = Box::whereIn('id', array_filter($boxes, 'is_numeric'))->pluck('name')->all();
        if (in_array('none', $boxes, true)) {
            $names[] = 'Sem caixa';
        }

        return $names ? implode(', ', $names) : 'Nenhuma caixa';
    }
}
