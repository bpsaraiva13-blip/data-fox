<?php

namespace App\Filament\Resources\Members\Pages;

use App\Filament\Resources\Invitations\InvitationResource;
use App\Filament\Resources\Members\MemberResource;
use App\Models\Acervo;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\BoxAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageMembers extends ManageRecords
{
    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addExisting')->label('Adicionar quem já tem conta')->icon(Heroicon::OutlinedUserPlus)->color('gray')
                ->schema([
                    TextInput::make('email')->label('E-mail da pessoa')->email()->required()
                        ->helperText('A pessoa precisa já ter conta no Data Fox. Se não tiver, use “Convidar por e-mail”.'),
                    Select::make('role')->label('Perfil')->options(Acervo::ROLES)->default('editor')->required(),
                    ...BoxAccess::fields(),
                ])
                ->action(function (array $data) {
                    $user = User::where('email', $data['email'])->first();
                    if (! $user) {
                        Notification::make()->title('Ninguém com esse e-mail')->body('Envie um convite por e-mail para a pessoa criar a conta.')->warning()->send();

                        return;
                    }
                    Acervo::current()->members()->syncWithoutDetaching([$user->id => ['role' => $data['role'], 'status' => 'active', 'boxes' => BoxAccess::value($data), 'invited_by' => auth()->id()]]);
                    ActivityLog::record('user', null, "Adicionou {$user->name} como ".Acervo::ROLES[$data['role']]);
                    Notification::make()->title("{$user->name} adicionado")->success()->send();
                }),
            Action::make('invite')->label('Convidar por e-mail')->icon(Heroicon::OutlinedEnvelope)
                ->url(InvitationResource::getUrl('index').'?action=create'),
        ];
    }
}
