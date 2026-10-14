<?php

namespace App\Filament\Resources\AccessLogs\Pages;

use App\Filament\Resources\AccessLogs\AccessLogResource;
use Filament\Resources\Pages\ListRecords;

class ListAccessLogs extends ListRecords
{
    protected static string $resource = AccessLogResource::class;
}
