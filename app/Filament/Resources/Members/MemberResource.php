<?php

namespace App\Filament\Resources\Members;

use App\Filament\Resources\Members\Pages\ManageMembers;
use App\Models\Acervo;
use App\Models\ActivityLog;
use App\Models\Membership;
use App\Support\BoxAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Equipe do acervo aberto: perfis, caixas permitidas, bloqueio. */
class MemberResource extends Resource
{
    protected static ?string $model = Membership::class;

    protected static ?string $tenantOwnershipRelationshipName = 'acervo';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Administração';

    protected static ?string $modelLabel = 'membro';

    protected static ?string $pluralModelLabel = 'equipe';

    protected static ?string $navigationLabel = 'Equipe';

    protected static ?string $slug = 'equipe';

    protected static ?int $navigationSort = 1;

    public static function table(Table $table): Table
    {
        $isOwner = fn (Membership $r) => $r->acervo->owner_id === $r->user_id;

        return $table
            ->modifyQueryUsing(fn ($q) => $q->with(['user', 'acervo']))
            ->columns([
                ImageColumn::make('user.avatar')->label('')->circular()->imageSize(36)
                    ->state(fn (Membership $r) => $r->user?->getFilamentAvatarUrl())
                    ->defaultImageUrl(fn (Membership $r) => 'https://ui-avatars.com/api/?background=DCEAD6&color=1F4D36&name='.urlencode($r->user?->name ?? '?')),
                TextColumn::make('user.name')->label('Nome')->searchable()->weight('semibold')
                    ->description(fn (Membership $r) => $r->user?->email.($isOwner($r) ? ' · dono do acervo' : '')),
                SelectColumn::make('role')->label('Perfil')->options(Acervo::ROLES)->selectablePlaceholder(false)
                    ->disabled(fn (Membership $r) => $isOwner($r))
                    ->afterStateUpdated(fn (Membership $r, $state) => ActivityLog::record('user', null, "Mudou o perfil de {$r->user->name} para ".Acervo::ROLES[$state])),
                TextColumn::make('boxes')->label('Caixas')->state(fn (Membership $r) => BoxAccess::label($r->allowedBoxes()))->wrap()->color('gray'),
                TextColumn::make('status')->label('Situação')->badge()
                    ->formatStateUsing(fn ($state) => $state === 'blocked' ? 'Bloqueado' : 'Ativo')
                    ->color(fn ($state) => $state === 'blocked' ? 'danger' : 'success'),
                TextColumn::make('created_at')->label('Desde')->date('d/m/Y')->toggleable(),
            ])
            ->recordActions([
                Action::make('boxes')->label('Caixas')->icon(Heroicon::OutlinedCube)->color('gray')
                    ->visible(fn (Membership $r) => ! $isOwner($r))
                    ->fillForm(fn (Membership $r) => BoxAccess::fill($r->allowedBoxes()))
                    ->schema(BoxAccess::fields())
                    ->action(function (Membership $r, array $data) {
                        $r->update(['boxes' => BoxAccess::value($data)]);
                        ActivityLog::record('user', null, "Mudou as caixas de {$r->user->name}");
                        Notification::make()->title('Acesso às caixas atualizado')->success()->send();
                    }),
                Action::make('block')
                    ->label(fn (Membership $r) => $r->status === 'blocked' ? 'Desbloquear' : 'Bloquear')
                    ->icon(fn (Membership $r) => $r->status === 'blocked' ? Heroicon::OutlinedLockOpen : Heroicon::OutlinedLockClosed)
                    ->color(fn (Membership $r) => $r->status === 'blocked' ? 'success' : 'warning')
                    ->visible(fn (Membership $r) => ! $isOwner($r) && $r->user_id !== auth()->id())
                    ->requiresConfirmation()
                    ->modalDescription('A pessoa deixa de acessar este acervo, mas o histórico dela continua registrado.')
                    ->action(function (Membership $r) {
                        $blocked = $r->status !== 'blocked';
                        $r->update(['status' => $blocked ? 'blocked' : 'active']);
                        ActivityLog::record('user', null, ($blocked ? 'Bloqueou ' : 'Desbloqueou ').$r->user->name);
                    }),
                DeleteAction::make()->label('Remover')
                    ->visible(fn (Membership $r) => ! $isOwner($r))
                    ->modalDescription('A pessoa sai deste acervo. Os cadastros feitos por ela continuam com o nome dela.')
                    ->after(fn (Membership $r) => ActivityLog::record('user', null, "Removeu {$r->user->name} do acervo")),
            ]);
    }

    /** Só a equipe do acervo aberto (o escopo automático do Filament está desligado no painel). */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->where('acervo_user.acervo_id', Acervo::current()?->id ?? 0);
    }

    public static function getPages(): array
    {
        return ['index' => ManageMembers::route('/')];
    }
}
