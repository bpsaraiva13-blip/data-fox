<?php

namespace App\Filament\Resources\Items\Pages;

use App\Filament\Pages\CompareImage;
use App\Filament\Resources\Items\ItemResource;
use App\Jobs\AnalyzeItem;
use App\Models\Item;
use App\Services\CuratorAI;
use App\Services\ItemMerger;
use App\Services\ShareCard;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;

class ViewItem extends ViewRecord
{
    protected static string $resource = ItemResource::class;

    public function getTitle(): string
    {
        return $this->record->title;
    }

    public function getSubheading(): ?string
    {
        return "Nº de tombo {$this->record->code}";
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->shareAction(),
            EditAction::make()->icon(Heroicon::OutlinedPencilSquare),
            ActionGroup::make([
                $this->mergeAction(),
                Action::make('reanalyze')->label('Analisar de novo com IA')->icon(Heroicon::Sparkles)
                    ->visible(fn () => auth()->user()->canEdit() && app(CuratorAI::class)->enabled() && $this->record->photos()->exists())
                    ->requiresConfirmation()
                    ->modalDescription('A IA olha todas as fotos e atualiza título, época, origem, material, estado e descrição.')
                    ->action(function () {
                        $this->record->update(['ai_status' => 'pending']);
                        AnalyzeItem::dispatch($this->record->id, true);
                        Notification::make()->title('Peça na fila da IA')->body('A ficha atualiza em alguns segundos.')->success()->send();
                    }),
                Action::make('similar')->label('Peças parecidas')->icon(Heroicon::OutlinedSquare2Stack)
                    ->visible(fn () => $this->record->photos()->exists())
                    ->url(fn () => CompareImage::getUrl(['item' => $this->record->id])),
            ])->icon(Heroicon::EllipsisVertical)->color('gray'),
        ];
    }

    protected function shareAction(): Action
    {
        $defaults = ['code', 'desc', 'tags'];

        return Action::make('share')
            ->label('Compartilhar')
            ->icon(Heroicon::OutlinedShare)
            ->color('success')
            ->modalHeading('Compartilhar no WhatsApp')
            ->modalWidth('5xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->fillForm(fn () => [
                'include' => $defaults,
                'photo' => 0,
                'text' => ShareCard::text($this->record->load('tags', 'box'), array_fill_keys($defaults, true) + ['box' => false, 'value' => false]),
            ])
            ->schema([
                CheckboxList::make('include')->label('O que incluir')->columns(5)->live()
                    ->options(['code' => 'Nº de tombo', 'desc' => 'Descrição', 'tags' => 'Tags', 'box' => 'Caixa e local', 'value' => 'Valor estimado'])
                    ->afterStateUpdated(function ($state, Set $set) {
                        $o = collect(['code', 'desc', 'tags', 'box', 'value'])->mapWithKeys(fn ($k) => [$k => in_array($k, (array) $state, true)])->all();
                        $set('text', ShareCard::text($this->record->load('tags', 'box'), $o));
                    }),
                Select::make('photo')->label('Foto do cartão')->live()
                    ->options(fn () => $this->record->photos->values()->mapWithKeys(fn ($p, $i) => [$i => 'Foto '.($i + 1).($i === 0 ? ' (capa)' : '')]))
                    ->visible(fn () => $this->record->photos()->count() > 1),
                \Filament\Schemas\Components\View::make('filament.share-card')
                    ->viewData(fn (Get $get) => [
                        'imageUrl' => route('items.share-image', [
                            'item' => $this->record,
                            'foto' => (int) $get('photo'),
                            'tombo' => in_array('code', (array) $get('include'), true) ? 1 : 0,
                            'tags' => in_array('tags', (array) $get('include'), true) ? 1 : 0,
                        ]),
                        'code' => $this->record->code,
                    ]),
                Textarea::make('text')->label('Mensagem (pode editar antes de enviar)')->rows(10)
                    ->extraInputAttributes(['id' => 'df-share-text', 'class' => 'font-mono']),
                TextInput::make('phone')->label('Enviar para um número (opcional)')->tel()->placeholder('DDD + número, ex.: 51 99999-0000')
                    ->extraInputAttributes(['id' => 'df-share-phone']),
            ]);
    }

    protected function mergeAction(): Action
    {
        return Action::make('merge')
            ->label('Unir com outra peça')
            ->icon(Heroicon::OutlinedLink)
            ->visible(fn () => auth()->user()->canEdit())
            ->modalHeading('Unir com outra peça')
            ->modalDescription('Escolha a outra parte desta peça (verso, tampa, par). Esta ficha mantém o tombo; as fotos e tags da outra passam para cá e ela deixa de existir.')
            ->modalSubmitActionLabel('Unir')
            ->schema([
                Select::make('others')->label('Peças para unir a esta')->multiple()->required()->searchable()
                    ->options(fn () => app(ItemMerger::class)->suggestions($this->record)
                        ->mapWithKeys(fn ($s) => [$s['item']->id => "{$s['item']->code} · {$s['item']->title} — {$s['why']}"]))
                    ->getSearchResultsUsing(fn (string $search) => Item::query()->whereKeyNot($this->record->id)->search($search)->limit(30)->get()
                        ->mapWithKeys(fn (Item $i) => [$i->id => "{$i->code} · {$i->title}"]))
                    ->getOptionLabelsUsing(fn (array $values) => Item::whereIn('id', $values)->get()->mapWithKeys(fn (Item $i) => [$i->id => "{$i->code} · {$i->title}"]))
                    ->helperText('As sugestões consideram tombo vizinho, mesmo lote, horário de cadastro e semelhança das fotos. Digite para buscar outras.'),
                Toggle::make('reanalyze')->label('Refazer a análise da IA com todas as fotos')
                    ->default(fn () => app(CuratorAI::class)->enabled())->disabled(fn () => ! app(CuratorAI::class)->enabled()),
            ])
            ->action(function (array $data) {
                $items = Item::whereIn('id', $data['others'])->get()->push($this->record);
                $main = app(ItemMerger::class)->merge($this->record, $items, (bool) ($data['reanalyze'] ?? false));
                Notification::make()->title(($items->count())." peças unidas em {$main->code}")->success()->send();
                $this->redirect(ItemResource::getUrl('view', ['record' => $main]));
            });
    }
}
