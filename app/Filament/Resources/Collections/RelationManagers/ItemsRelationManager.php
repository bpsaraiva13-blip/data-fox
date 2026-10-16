<?php

namespace App\Filament\Resources\Collections\RelationManagers;

use App\Filament\Resources\Items\ItemResource;
use App\Models\Item;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Peças da coleção';

    public function isReadOnly(): bool
    {
        return ! auth()->user()->canEdit();
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (Item $r) => "{$r->code} · {$r->title}")
            ->modifyQueryUsing(fn ($query) => $query->with('cover'))
            ->recordUrl(fn (Item $r) => ItemResource::getUrl('view', ['record' => $r]))
            ->columns([
                ImageColumn::make('cover.thumb_path')->label('')->disk(config('acervo.disk'))->visibility('private')->imageSize(48)->square(),
                TextColumn::make('code')->label('Tombo')->fontFamily('mono')->searchable(),
                TextColumn::make('title')->label('Título')->searchable(),
                TextColumn::make('era')->label('Época')->placeholder('—'),
            ])
            ->headerActions([
                AttachAction::make()->label('Adicionar peças')->multiple()->preloadRecordSelect()
                    ->recordSelectSearchColumns(['code', 'title']),
            ])
            ->recordActions([DetachAction::make()->label('Tirar')])
            ->toolbarActions([DetachBulkAction::make()->label('Tirar da coleção')]);
    }
}
