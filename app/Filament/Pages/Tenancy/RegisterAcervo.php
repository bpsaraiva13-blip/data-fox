<?php

namespace App\Filament\Pages\Tenancy;

use App\Models\Acervo;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/** Qualquer usuário pode criar o próprio acervo; quem cria vira administrador dele. */
class RegisterAcervo extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Criar acervo';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nome do acervo')->required()->maxLength(120)
                ->placeholder('ex.: Coleção da família Silva')
                ->helperText('Você será o administrador. Depois, convide quem quiser compartilhar este acervo.'),
        ]);
    }

    protected function handleRegistration(array $data): Model
    {
        return Acervo::create(['name' => $data['name'], 'owner_id' => auth()->id()]);
    }
}
