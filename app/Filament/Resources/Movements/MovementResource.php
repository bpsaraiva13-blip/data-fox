<?php

namespace App\Filament\Resources\Movements;

use App\Filament\Resources\Items\ItemResource;
use App\Filament\Resources\Movements\Pages\ListMovements;
use App\Models\Item;
use App\Models\Movement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class MovementResource extends Resource
{
    protected static ?string $model = Movement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Acervo';

    protected static ?string $modelLabel = 'movimentação';

    protected static ?string $pluralModelLabel = 'movimentações';

    protected static ?string $slug = 'movimentacoes';

    protected static ?int $navigationSort = 5;

    public static function getNavigationBadge(): ?string
    {
        $n = Item::where('status', 'loaned')->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Peças emprestadas agora';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->modifyQueryUsing(fn ($q) => $q->with(['item.cover', 'user']))
            ->recordUrl(fn (Movement $r) => $r->item && ! $r->item->trashed() ? ItemResource::getUrl('view', ['record' => $r->item]) : null)
            ->columns([
                TextColumn::make('date')->label('Data')->date('d/m/Y')->sortable(),
                TextColumn::make('kind')->label('Tipo')->badge()->formatStateUsing(fn ($s) => Movement::KINDS[$s] ?? $s)
                    ->color(fn ($s) => ['loan' => 'warning', 'sale' => 'danger'][$s] ?? 'success'),
                TextColumn::make('item.code')->label('Tombo')->fontFamily('mono')->searchable(),
                TextColumn::make('item.title')->label('Peça')->searchable()->limit(50),
                TextColumn::make('person')->label('Responsável')->searchable(),
                TextColumn::make('notes')->label('Observação')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('user.name')->label('Registrado por'),
            ])
            ->filters([
                SelectFilter::make('kind')->label('Tipo')->options(Movement::KINDS),
                SelectFilter::make('loaned_now')->label('Situação atual')->options(['loaned' => 'Emprestadas agora', 'sold' => 'Vendidas'])
                    ->query(fn ($q, array $d) => $d['value'] ? $q->whereHas('item', fn ($i) => $i->where('status', $d['value'])) : $q),
            ])
            ->emptyStateHeading('Nenhuma movimentação')
            ->emptyStateDescription('Abra uma peça e use Situação → Registrar empréstimo ou venda.');
    }

    public static function getPages(): array
    {
        return ['index' => ListMovements::route('/')];
    }
}
