<?php

namespace App\Filament\Resources\Invitations;

use App\Filament\Resources\Invitations\Pages\ManageInvitations;
use App\Models\Acervo;
use App\Models\Invitation;
use App\Support\BoxAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class InvitationResource extends Resource
{
    protected static ?string $model = Invitation::class;

    protected static ?string $tenantOwnershipRelationshipName = 'acervo';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'Administração';

    protected static ?string $modelLabel = 'convite';

    protected static ?string $pluralModelLabel = 'convites';

    protected static ?string $slug = 'convites';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextInput::make('email')->label('E-mail')->email()->required(),
            TextInput::make('name')->label('Nome (opcional)'),
            Select::make('role')->label('Perfil')->options(Acervo::ROLES)->default('editor')->required(),
            ...BoxAccess::fields(),
            Select::make('days')->label('Validade')->options([2 => '2 dias', 7 => '7 dias', 30 => '30 dias'])->default(7),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($q) => $q->with('inviter'))
            ->columns([
                TextColumn::make('email')->label('E-mail')->description(fn (Invitation $r) => $r->name)->searchable(),
                TextColumn::make('role')->label('Perfil')->badge()->formatStateUsing(fn ($s) => Acervo::ROLES[$s] ?? $s),
                TextColumn::make('boxes')->label('Caixas')->state(fn (Invitation $r) => BoxAccess::label($r->boxes))->color('gray')->wrap(),
                TextColumn::make('situacao')->label('Situação')->badge()
                    ->state(fn (Invitation $r) => $r->accepted_at ? 'Aceito' : ($r->revoked_at ? 'Revogado' : ($r->expires_at->isPast() ? 'Expirado' : 'Pendente')))
                    ->color(fn ($state) => ['Aceito' => 'success', 'Pendente' => 'warning'][$state] ?? 'gray'),
                TextColumn::make('expires_at')->label('Vence')->dateTime('d/m/Y H:i'),
                TextColumn::make('inviter.name')->label('Convidado por')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('link')->label('Link')->icon(Heroicon::OutlinedLink)->color('gray')
                    ->visible(fn (Invitation $r) => $r->isPending())
                    ->modalHeading('Link do convite')->modalSubmitAction(false)->modalCancelActionLabel('Fechar')
                    ->schema(fn (Invitation $r) => [
                        TextInput::make('url')->label('Copie e envie como preferir')->default($r->url())->readOnly()->copyable(),
                    ]),
                Action::make('whatsapp')->label('WhatsApp')->icon(Heroicon::OutlinedChatBubbleLeftRight)->color('success')
                    ->visible(fn (Invitation $r) => $r->isPending())
                    ->url(fn (Invitation $r) => 'https://wa.me/?text='.rawurlencode("Olá! Você foi convidado para o acervo *{$r->acervo->name}* no Data Fox. Para aceitar, abra: {$r->url()}"), true),
                Action::make('resend')->label('Reenviar')->icon(Heroicon::OutlinedPaperAirplane)->color('gray')
                    ->visible(fn (Invitation $r) => $r->isPending())
                    ->action(fn (Invitation $r) => ManageInvitations::send($r)),
                Action::make('revoke')->label('Revogar')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                    ->visible(fn (Invitation $r) => $r->isPending())->requiresConfirmation()
                    ->action(function (Invitation $r) {
                        $r->update(['revoked_at' => now()]);
                        Notification::make()->title('Convite revogado')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageInvitations::route('/')];
    }
}
