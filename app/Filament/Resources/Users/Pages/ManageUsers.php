<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageUsers extends ManageRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        // criado pelo administrador do sistema: o e-mail é considerado confirmado
        return [CreateAction::make()->label('Novo usuário')
            ->using(function (array $data) {
                $rows = $data['acervos_init'] ?? [];
                unset($data['acervos_init']);
                $user = \App\Models\User::create($data);
                $user->forceFill(['email_verified_at' => now()])->saveQuietly();
                UserResource::syncAcervos($user, $rows, false);

                return $user;
            })];
    }
}
