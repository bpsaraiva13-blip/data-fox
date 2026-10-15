<?php

namespace App\Support;

use App\Models\Collector;
use App\Models\Item;
use App\Models\Movement;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

/** Campos comuns das movimentações (na ficha e em lote). */
class MovementForm
{
    /** Nomes já usados: colecionadores cadastrados + responsáveis de movimentações anteriores. */
    public static function people(): array
    {
        return Collector::query()->orderBy('name')->pluck('name')
            ->merge(Movement::query()->select('person')->distinct()->limit(200)->pluck('person'))
            ->filter()->unique()->sort()->values()->all();
    }

    public static function fields(string $personLabel = 'Responsável', ?string $defaultPerson = null): array
    {
        return [
            DatePicker::make('date')->label('Data')->required()->native(false)->displayFormat('d/m/Y')->default(now()->toDateString()),
            TextInput::make('person')->label($personLabel)->required()->maxLength(120)->default($defaultPerson)
                ->datalist(fn () => static::people())
                ->helperText('Escolha um colecionador cadastrado ou digite um nome.'),
            TextInput::make('notes')->label('Observação (opcional)')->maxLength(250),
        ];
    }

    /** Seleção de várias peças, filtrada pela situação que o tipo exige. */
    public static function itemsField(): Select
    {
        return Select::make('items')->label('Peças')->multiple()->required()->searchable()
            ->getSearchResultsUsing(function (string $search, \Filament\Schemas\Components\Utilities\Get $get) {
                $kind = $get('kind') ?? 'loan';

                return ItemAccess::restrict(Item::query())
                    ->where('status', $kind === 'return' ? 'loaned' : ($kind === 'undo' ? 'sold' : 'available'))
                    ->where(fn ($q) => $q->where('title', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%"))
                    ->orderBy('code')->limit(50)->get()
                    ->mapWithKeys(fn (Item $i) => [$i->id => "{$i->code} · {$i->title}"])->all();
            })
            ->getOptionLabelsUsing(fn (array $values) => Item::whereIn('id', $values)->get()->mapWithKeys(fn (Item $i) => [$i->id => "{$i->code} · {$i->title}"])->all())
            ->helperText('Digite parte do nome ou do tombo. Empréstimo e venda mostram peças no acervo; devolução mostra as emprestadas.');
    }
}
