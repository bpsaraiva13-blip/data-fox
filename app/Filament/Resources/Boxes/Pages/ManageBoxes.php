<?php

namespace App\Filament\Resources\Boxes\Pages;

use App\Filament\Pages\BatchUpload;
use App\Filament\Resources\Boxes\BoxResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

class ManageBoxes extends ManageRecords
{
    protected static string $resource = BoxResource::class;

    /** Visão em cartões ou em lista (fica na URL). */
    #[Url]
    public string $layout = 'grid';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('toggleLayout')
                ->label(fn () => $this->layout === 'grid' ? 'Ver em lista' : 'Ver em cartões')
                ->icon(fn () => $this->layout === 'grid' ? Heroicon::OutlinedListBullet : Heroicon::OutlinedSquares2x2)
                ->color('gray')
                ->url(fn () => static::getUrl(['layout' => $this->layout === 'grid' ? 'list' : 'grid'])),
            Action::make('batch')->label('Enviar caixa em lote')->icon(Heroicon::OutlinedArrowUpTray)->color('gray')
                ->visible(fn () => auth()->user()->canEdit())
                ->url(BatchUpload::getUrl()),
            CreateAction::make()->label('Nova caixa'),
        ];
    }
}
