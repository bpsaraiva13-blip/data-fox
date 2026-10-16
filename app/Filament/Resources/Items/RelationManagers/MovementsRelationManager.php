<?php

namespace App\Filament\Resources\Items\RelationManagers;

use App\Models\Movement;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Histórico de empréstimos e vendas (registre pelos botões no topo da ficha). */
class MovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'movements';

    protected static ?string $title = 'Movimentações';

    protected static string|\BackedEnum|null $icon = Heroicon::OutlinedArrowsRightLeft;

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('date')->label('Data')->date('d/m/Y'),
            TextColumn::make('kind')->label('Tipo')->badge()->formatStateUsing(fn ($state) => Movement::KINDS[$state] ?? $state)
                ->color(fn ($state) => ['loan' => 'warning', 'sale' => 'danger'][$state] ?? 'success'),
            TextColumn::make('person')->label('Responsável'),
            TextColumn::make('notes')->label('Observação')->placeholder('—')->wrap(),
            TextColumn::make('user.name')->label('Registrado por'),
        ])->emptyStateHeading('Nenhuma movimentação')->emptyStateDescription('Use “Registrar empréstimo” ou “Registrar venda” no topo da ficha.');
    }
}
