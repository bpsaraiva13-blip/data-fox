<?php

namespace App\Filament\Resources\Movements\Pages;

use App\Filament\Resources\Movements\MovementResource;
use App\Models\Item;
use App\Models\Movement;
use App\Support\ItemAccess;
use App\Support\MovementForm;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListMovements extends ListRecords
{
    protected static string $resource = MovementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Registrar várias peças de uma vez (ex.: empréstimo de uma caixa para uma exposição)
            Action::make('batch')->label('Registrar movimentação')->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => auth()->user()->canEdit())
                ->modalWidth('3xl')
                ->schema([
                    Select::make('kind')->label('Tipo')->options(['loan' => 'Empréstimo', 'sale' => 'Venda', 'return' => 'Devolução', 'undo' => 'Volta ao acervo (vendida)'])
                        ->default('loan')->required()->live()->afterStateUpdated(fn ($set) => $set('items', [])),
                    MovementForm::itemsField(),
                    ...MovementForm::fields(),
                ])
                ->action(function (array $data) {
                    $items = ItemAccess::restrict(Item::query())->whereIn('id', $data['items'] ?? [])->get();
                    foreach ($items as $item) {
                        Movement::register($item, $data['kind'], $data['date'], $data['person'], $data['notes'] ?? null);
                    }
                    Notification::make()->success()->title(Movement::KINDS[$data['kind']].' registrado para '.$items->count().' peça(s)')->send();
                }),
        ];
    }
}
