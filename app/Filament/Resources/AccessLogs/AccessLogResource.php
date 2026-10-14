<?php

namespace App\Filament\Resources\AccessLogs;

use App\Filament\Resources\AccessLogs\Pages\ListAccessLogs;
use App\Models\AccessLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Quem abriu fichas, gerou PDFs e etiquetas, exportou ou compartilhou. */
class AccessLogResource extends Resource
{
    protected static ?string $model = AccessLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Administração';

    protected static ?string $modelLabel = 'acesso';

    protected static ?string $pluralModelLabel = 'registro de acesso';

    protected static ?string $navigationLabel = 'Registro de acesso';

    protected static ?string $slug = 'acessos';

    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($q) => $q->with('user'))
            ->columns([
                TextColumn::make('created_at')->label('Quando')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('user.name')->label('Quem')->searchable(),
                TextColumn::make('type')->label('O que')->badge()->formatStateUsing(fn ($s) => AccessLog::TYPES[$s] ?? $s)->color('gray'),
                TextColumn::make('label')->label('Detalhe')->wrap()->searchable(),
                TextColumn::make('ip')->label('IP')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')->label('Tipo')->options(AccessLog::TYPES),
                SelectFilter::make('user_id')->label('Pessoa')->relationship('user', 'name'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAccessLogs::route('/')];
    }
}
