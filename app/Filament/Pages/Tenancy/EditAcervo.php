<?php

namespace App\Filament\Pages\Tenancy;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EditAcervo extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Configurações do acervo';
    }

    public static function canView(\Illuminate\Database\Eloquent\Model $tenant): bool
    {
        return auth()->user()?->isAcervoAdmin($tenant) ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Acervo')->schema([
                TextInput::make('name')->label('Nome')->required()->maxLength(120),
            ]),
            Section::make('Vitrine')
                ->description('Página com as peças marcadas “Mostrar na vitrine”, sem caixa, local, valor ou observações internas.')
                ->schema([
                    Toggle::make('showcase_public')->label('Vitrine pública (abre sem login)')
                        ->helperText(fn () => 'Endereço: '.route('showcase', $this->tenant->slug)),
                    Textarea::make('showcase_intro')->label('Texto de apresentação')->rows(3),
                ]),
        ]);
    }
}
