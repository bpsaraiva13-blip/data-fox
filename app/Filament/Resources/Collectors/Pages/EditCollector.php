<?php

namespace App\Filament\Resources\Collectors\Pages;

use App\Filament\Resources\Collectors\CollectorResource;
use App\Models\ActivityLog;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditCollector extends EditRecord
{
    protected static string $resource = CollectorResource::class;

    public function getSubheading(): ?string
    {
        return $this->record->address() ?: null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('whatsapp')->label('WhatsApp')->icon(Heroicon::OutlinedChatBubbleLeftRight)->color('success')
                ->url(fn () => $this->record->whatsappUrl(), true)->visible(fn () => (bool) $this->record->phone),
            Action::make('map')->label('Mapa')->icon(Heroicon::OutlinedMapPin)->color('gray')
                ->url(fn () => $this->record->mapsUrl(), true)->visible(fn () => (bool) $this->record->mapsUrl()),
            DeleteAction::make()->modalDescription('O cadastro fica 30 dias na lixeira. As movimentações já registradas continuam.'),
        ];
    }

    protected function afterSave(): void
    {
        ActivityLog::record('user', null, "Atualizou o colecionador {$this->record->name}");
    }
}
