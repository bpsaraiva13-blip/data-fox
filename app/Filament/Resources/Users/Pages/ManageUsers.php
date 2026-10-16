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
            ->after(fn (\App\Models\User $record) => $record->forceFill(['email_verified_at' => now()])->saveQuietly())];
    }
}
