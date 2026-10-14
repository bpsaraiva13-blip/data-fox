<?php

namespace App\Filament\Resources\Items\Pages;

use App\Filament\Pages\CompareImage;
use App\Filament\Resources\Items\ItemResource;
use App\Services\CuratorAI;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use Throwable;

class ListItems extends ListRecords
{
    protected static string $resource = ItemResource::class;

    /** IDs ordenados pela busca inteligente; null = listagem normal. */
    #[Url(as: 'ia')]
    public ?array $aiIds = null;

    public ?string $aiQuery = null;

    /** Mostrar só as peças de um lote (vem do cadastro em lote). */
    #[Url]
    public ?string $batch = null;

    public function getSubheading(): ?string
    {
        if ($this->aiIds !== null) {
            return 'Busca inteligente: '.count($this->aiIds).' peça(s) para “'.$this->aiQuery.'”';
        }

        return $this->batch ? "Mostrando o lote {$this->batch}. Use “Unir em uma peça” para juntar frente e verso." : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('aiSearch')
                ->label('Busca inteligente')
                ->icon(Heroicon::Sparkles)
                ->color('warning')
                ->visible(fn () => app(CuratorAI::class)->enabled())
                ->modalHeading('Descreva a peça que procura')
                ->modalDescription('Ex.: “xícara azul com borda dourada, século XIX” ou “moedas do Império”.')
                ->modalSubmitActionLabel('Buscar')
                ->schema([Textarea::make('q')->hiddenLabel()->required()->rows(2)->autofocus()])
                ->action(function (array $data) {
                    try {
                        $res = app(CuratorAI::class)->search($data['q']);
                    } catch (Throwable $e) {
                        Notification::make()->title('Busca indisponível')->body($e->getMessage())->danger()->send();

                        return;
                    }
                    $this->aiQuery = $data['q'];
                    $this->aiIds = $res->pluck('id')->map(fn ($i) => (int) $i)->all();
                    $this->resetPage();
                    Notification::make()->title(count($this->aiIds).' peça(s) encontrada(s)')
                        ->body($res->take(3)->map(fn ($r) => '• '.($r['motivo'] ?? ''))->implode("\n"))->success()->send();
                }),

            Action::make('clearAi')
                ->label('Limpar busca')
                ->icon(Heroicon::XMark)
                ->color('gray')
                ->visible(fn () => $this->aiIds !== null)
                ->action(function () {
                    $this->aiIds = null;
                    $this->aiQuery = null;
                    $this->resetPage();
                }),

            Action::make('clearBatch')
                ->label('Ver todo o acervo')
                ->icon(Heroicon::XMark)
                ->color('gray')
                ->visible(fn () => filled($this->batch))
                ->action(function () {
                    $this->batch = null;
                    $this->resetPage();
                }),

            Action::make('batchUpload')
                ->label('Lote')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('gray')
                ->visible(fn () => auth()->user()->canEdit())
                ->url(fn () => \App\Filament\Pages\BatchUpload::getUrl()),

            Action::make('compare')
                ->label('Comparar imagem')
                ->icon(Heroicon::OutlinedSquare2Stack)
                ->color('gray')
                ->url(CompareImage::getUrl()),

            CreateAction::make()->label('Nova peça')->icon(Heroicon::Plus),
        ];
    }
}
