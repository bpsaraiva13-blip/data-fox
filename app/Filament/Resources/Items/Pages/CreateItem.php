<?php

namespace App\Filament\Resources\Items\Pages;

use App\Filament\Resources\Items\ItemResource;
use App\Services\PhotoManager;
use Filament\Resources\Pages\CreateRecord;

class CreateItem extends CreateRecord
{
    protected static string $resource = ItemResource::class;

    protected array $photoPaths = [];

    protected array $tagNames = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->photoPaths = $data['photo_paths'] ?? [];
        $this->tagNames = $data['tag_names'] ?? [];
        unset($data['photo_paths'], $data['tag_names']);
        if (filled($data['ai_keywords'] ?? null)) {
            $data['ai_analyzed_at'] = now();
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->syncTagNames($this->tagNames);
        app(PhotoManager::class)->sync($this->record, $this->photoPaths);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Peça cadastrada: '.$this->record->code;
    }
}
