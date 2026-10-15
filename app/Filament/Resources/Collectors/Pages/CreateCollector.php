<?php

namespace App\Filament\Resources\Collectors\Pages;

use App\Filament\Resources\Collectors\CollectorResource;
use App\Models\ActivityLog;
use Filament\Resources\Pages\CreateRecord;

class CreateCollector extends CreateRecord
{
    protected static string $resource = CollectorResource::class;

    protected function afterCreate(): void
    {
        ActivityLog::record('user', null, "Cadastrou o colecionador {$this->record->name}");
    }
}
