<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Administração';

    protected static ?string $modelLabel = 'usuário';

    protected static ?string $pluralModelLabel = 'usuários';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('Nome')->required(),
            TextInput::make('email')->label('E-mail')->email()->required()->unique(ignoreRecord: true),
            Select::make('role')->label('Perfil')->options(User::ROLES)->required()->default('editor')
                ->helperText('Editor cadastra e altera peças; Somente leitura apenas consulta; Administrador também gerencia usuários.'),
            TextInput::make('phone')->label('WhatsApp')->tel()->maxLength(30),
            Toggle::make('active')->label('Acesso ativo')->default(true)->inline(false)
                ->helperText('Desativado, a pessoa não entra em nenhum acervo.')
                ->disabled(fn (?User $record) => $record?->is(auth()->user())),
            TextInput::make('password')->label('Senha')->password()->revealable()
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn ($state) => filled($state))
                ->minLength(8)
                ->helperText(fn (string $operation) => $operation === 'edit' ? 'Deixe em branco para manter a senha atual.' : null),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            ImageColumn::make('avatar')->label('')->circular()->state(fn (User $r) => $r->getFilamentAvatarUrl())->imageSize(36),
            TextColumn::make('name')->label('Nome')->searchable()->description(fn (User $r) => $r->phone),
            TextColumn::make('email')->label('E-mail')->searchable(),
            TextColumn::make('acervos')->label('Acervos')->state(fn (User $r) => $r->acervos->map(fn ($a) => $a->name.' ('.(\App\Models\Acervo::ROLES[$a->pivot->role] ?? $a->pivot->role).($a->pivot->status === 'blocked' ? ', bloqueado' : '').')')->implode(' · '))
                ->wrap()->color('gray'),
            TextColumn::make('role')->label('Perfil')->badge()->formatStateUsing(fn ($state) => User::ROLES[$state] ?? $state),
            IconColumn::make('active')->label('Ativo')->boolean(),
            TextColumn::make('created_at')->label('Criado')->since(),
        ])->modifyQueryUsing(fn ($q) => $q->with('acervos'))
            ->recordActions([EditAction::make(), DeleteAction::make()->hidden(fn (User $r) => $r->is(auth()->user()))]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageUsers::route('/')];
    }
}
