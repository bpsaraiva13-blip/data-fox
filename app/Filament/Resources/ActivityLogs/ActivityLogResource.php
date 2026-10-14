<?php

namespace App\Filament\Resources\ActivityLogs;

use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Resources\Items\ItemResource;
use App\Models\ActivityLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Forms\Components\DatePicker;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ActivityLogResource extends Resource
{
    protected static ?string $model = ActivityLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Acervo';

    protected static ?string $modelLabel = 'atividade';

    protected static ?string $pluralModelLabel = 'atividade';

    protected static ?string $navigationLabel = 'Atividade';

    protected static ?int $navigationSort = 6;

    private const TYPES = ['create' => 'Cadastro', 'update' => 'Alteração', 'move' => 'Movimentação', 'delete' => 'Exclusão', 'tag' => 'Tags', 'box' => 'Caixas'];

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($q) => $q->with('user'))
            ->recordUrl(fn (ActivityLog $r) => $r->item_id && $r->item()->exists() && ! $r->item?->trashed() ? ItemResource::getUrl('view', ['record' => $r->item_id]) : null)
            ->columns([
                TextColumn::make('created_at')->label('Quando')->dateTime('d/m/Y H:i')->description(fn (ActivityLog $r) => $r->created_at->diffForHumans())->sortable(),
                TextColumn::make('type')->label('Tipo')->badge()
                    ->formatStateUsing(fn (string $state) => self::TYPES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) { 'create' => 'success', 'delete' => 'danger', 'box' => 'warning', default => 'primary' }),
                TextColumn::make('actor')->label('Quem'),
                TextColumn::make('label')->label('O que')->wrap()->searchable(),
            ])
            ->filters([
                SelectFilter::make('type')->label('Tipo')->options(self::TYPES),
                SelectFilter::make('user_id')->label('Pessoa')->relationship('user', 'name'),
                Filter::make('period')->schema([
                    DatePicker::make('from')->label('De'),
                    DatePicker::make('until')->label('Até'),
                ])->query(fn (Builder $q, array $d) => $q
                    ->when($d['from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
                    ->when($d['until'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListActivityLogs::route('/')];
    }
}
