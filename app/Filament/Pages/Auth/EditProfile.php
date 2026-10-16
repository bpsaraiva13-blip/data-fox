<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/** Meu perfil: nome, e-mail, foto, WhatsApp e senha. */
class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            FileUpload::make('avatar_path')->label('Foto')->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'image/heif'])->avatar()->imageEditor()
                ->disk(config('acervo.disk'))->directory('acervo/avatars')->visibility('private')->maxSize(5 * 1024),
            $this->getNameFormComponent(),
            $this->getEmailFormComponent(),
            TextInput::make('phone')->label('WhatsApp (opcional, aparece para a equipe)')->tel()->maxLength(30),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),
        ]);
    }
}
