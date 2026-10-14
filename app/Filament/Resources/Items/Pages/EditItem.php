<?php

namespace App\Filament\Resources\Items\Pages;

use App\Filament\Resources\Items\ItemResource;
use App\Models\ActivityLog;
use App\Services\PhotoManager;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditItem extends EditRecord
{
    protected static string $resource = ItemResource::class;

    protected array $photoPaths = [];

    protected array $tagNames = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['photo_paths'] = $this->record->photos()->pluck('path')->all();
        $data['tag_names'] = $this->record->tags()->pluck('name')->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->photoPaths = $data['photo_paths'] ?? [];
        $this->tagNames = $data['tag_names'] ?? [];
        unset($data['photo_paths'], $data['tag_names']);
        if (filled($data['ai_keywords'] ?? null) && $data['ai_keywords'] !== $this->record->ai_keywords) {
            $data['ai_analyzed_at'] = now();
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $before = $this->record->tags()->pluck('name')->sort()->values()->all();
        $this->record->syncTagNames($this->tagNames);
        $after = $this->record->tags()->pluck('name')->sort()->values()->all();
        if ($before !== $after) {
            ActivityLog::record('tag', $this->record->id, "{$this->record->title} — tags: ".(implode(', ', array_map(fn ($t) => "#{$t}", $after)) ?: 'nenhuma'));
        }

        app(PhotoManager::class)->sync($this->record, $this->photoPaths);
    }

    protected function getHeaderActions(): array
    {
        return [ViewAction::make(), DeleteAction::make(), RestoreAction::make(), ForceDeleteAction::make()];
    }
}
