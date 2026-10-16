<?php

namespace App\Filament\Resources\Collections;

use App\Filament\Resources\Collections\Pages\ManageCollections;
use App\Filament\Resources\Collections\Pages\ViewCollection;
use App\Filament\Resources\Collections\RelationManagers\ItemsRelationManager;
use App\Models\Item;
use App\Models\ItemCollection;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use UnitEnum;

class CollectionResource extends Resource
{
    protected static ?string $model = ItemCollection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static string|UnitEnum|null $navigationGroup = 'Acervo';

    protected static ?string $modelLabel = 'coleção';

    protected static ?string $pluralModelLabel = 'coleções';

    protected static ?string $slug = 'colecoes';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextInput::make('name')->label('Nome')->required()->maxLength(120)->placeholder('ex.: Belle Époque'),
            TextInput::make('description')->label('Descrição (opcional)')->maxLength(250),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('items'))
            ->defaultSort('name')
            ->contentGrid(['md' => 2, 'xl' => 3])
            ->recordUrl(fn (ItemCollection $r) => static::getUrl('view', ['record' => $r]))
            ->columns([
                Stack::make([
                    ViewColumn::make('cover')->view('filament.collection-mosaic'),
                    TextColumn::make('name')->weight('bold')->size('lg')->searchable(),
                    TextColumn::make('items_count')->color('gray')
                        ->formatStateUsing(fn ($state, ItemCollection $r) => $state.' peça'.($state == 1 ? '' : 's').($r->description ? ' · '.$r->description : '')),
                ])->space(2),
            ])
            ->recordActions([
                Action::make('catalog')->label('Catálogo')->icon(Heroicon::OutlinedDocumentText)->color('gray')
                    ->url(fn (ItemCollection $r) => route('pdf.catalog', ['ids' => $r->items()->pluck('items.id')->take(150)->implode(','), 'titulo' => $r->name]), true)
                    ->visible(fn (ItemCollection $r) => $r->items_count > 0),
                EditAction::make()->iconButton(),
                DeleteAction::make()->iconButton()->modalDescription('As peças continuam no acervo.'),
            ]);
    }

    public static function getRelations(): array
    {
        return [ItemsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ManageCollections::route('/'), 'view' => ViewCollection::route('/{record}')];
    }
}
