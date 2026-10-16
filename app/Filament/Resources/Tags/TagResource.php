<?php

namespace App\Filament\Resources\Tags;

use App\Filament\Resources\Items\ItemResource;
use App\Filament\Resources\Tags\Pages\ManageTags;
use App\Models\ActivityLog;
use App\Models\Tag;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class TagResource extends Resource
{
    protected static ?string $model = Tag::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Acervo';

    protected static ?string $modelLabel = 'tag';

    protected static ?string $pluralModelLabel = 'tags';

    protected static ?int $navigationSort = 4;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('items'))
            ->defaultSort('items_count', 'desc')
            ->recordUrl(fn (Tag $r) => ItemResource::getUrl('index', ['filters' => ['tags' => ['values' => [$r->id]]]]))
            ->columns([
                TextColumn::make('name')->label('Tag')->badge()->prefix('#')->searchable()->sortable(),
                TextColumn::make('items_count')->label('Peças')->numeric()->sortable(),
                TextColumn::make('created_at')->label('Criada')->since()->sortable(),
            ])
            ->recordActions([
                Action::make('rename')->label('Renomear')->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn () => auth()->user()->canEdit())
                    ->modalDescription('Se já existir uma tag com o novo nome, as duas são mescladas. Todas as peças são atualizadas.')
                    ->fillForm(fn (Tag $r) => ['name' => $r->name])
                    ->schema([TextInput::make('name')->label('Novo nome')->required()->maxLength(40)])
                    ->action(function (Tag $r, array $data) {
                        $n = $r->renameOrMerge($data['name']);
                        Notification::make()->title("Tag atualizada em {$n} peça(s)")->success()->send();
                    }),
                DeleteAction::make()->label('Remover')
                    ->modalDescription(fn (Tag $r) => "Remover #{$r->name} de {$r->items_count} peça(s)?")
                    ->after(fn (Tag $r) => ActivityLog::record('tag', null, "Removeu #{$r->name}")),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTags::route('/')];
    }

    /** Só as tags do próprio acervo (as de caixas vinculadas são geridas no acervo de origem). */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->where('tags.acervo_id', \App\Models\Acervo::current()?->id);
    }
}
