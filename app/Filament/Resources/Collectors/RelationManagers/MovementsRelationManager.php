<?php

namespace App\Filament\Resources\Collectors\RelationManagers;

use App\Filament\Resources\Items\ItemResource;
use App\Models\Movement;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Histórico de empréstimos, vendas e devoluções do colecionador. */
class MovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'movements';

    protected static ?string $title = 'Histórico';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->modifyQueryUsing(fn ($q) => $q->with('item.cover'))
            ->recordUrl(fn (Movement $r) => $r->item && ! $r->item->trashed() ? ItemResource::getUrl('view', ['record' => $r->item]) : null)
            ->columns([
                ImageColumn::make('photo')->label('')->state(fn (Movement $r) => $r->item?->cover?->thumbUrl())->square()->imageSize(40),
                TextColumn::make('date')->label('Data')->date('d/m/Y'),
                TextColumn::make('kind')->label('Tipo')->badge()->formatStateUsing(fn ($s) => Movement::KINDS[$s] ?? $s)
                    ->color(fn ($s) => ['loan' => 'warning', 'sale' => 'danger'][$s] ?? 'success'),
                TextColumn::make('item.code')->label('Tombo')->fontFamily('mono'),
                TextColumn::make('item.title')->label('Peça')->limit(50),
                TextColumn::make('notes')->label('Observação')->placeholder('—'),
            ])
            ->emptyStateHeading('Nenhuma movimentação com este colecionador');
    }
}
