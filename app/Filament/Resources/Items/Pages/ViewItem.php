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

    public function mount(int|string $record): void
    {
        parent::mount($record);
        \App\Models\AccessLog::log('view', $this->record);

        // Leu a etiqueta QR durante uma conferência? Marca a peça.
        if ($inv = \App\Models\Inventory::openFor(auth()->user())) {
            $here = $inv->scan($this->record);
            Notification::make()
                ->title($here ? "{$this->record->code} encontrada na conferência" : "{$this->record->code} pertence a outra caixa")
                ->body($inv->box?->name)
                ->{$here ? 'success' : 'warning'}()->send();
        }
    }

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
        $canEdit = fn () => auth()->user()->canEdit();
        $st = fn () => $this->record->status ?? 'available';

        return [
            $this->shareAction(),
            EditAction::make()->icon(Heroicon::OutlinedPencilSquare),
            ActionGroup::make([
                $this->movementAction('loan', 'Registrar empréstimo', 'Emprestado para (responsável)')->visible(fn () => $canEdit() && $st() === 'available'),
                $this->movementAction('sale', 'Registrar venda', 'Vendido para (responsável)')->visible(fn () => $canEdit() && $st() === 'available'),
                $this->movementAction('return', 'Registrar devolução', 'Recebido por (responsável)')->visible(fn () => $canEdit() && $st() === 'loaned'),
                $this->movementAction('undo', 'Voltar ao acervo', 'Responsável')->visible(fn () => $canEdit() && $st() === 'sold'),
            ])->label('Situação')->icon(Heroicon::OutlinedArrowsRightLeft)->button()->color('gray')->visible($canEdit),
            ActionGroup::make([
                \App\Support\TransferForm::itemAction(),
                Action::make('pdf')->label('Ficha em PDF')->icon(Heroicon::OutlinedDocumentText)->url(fn () => route('pdf.ficha', $this->record), true),
                Action::make('label')->label('Etiqueta QR')->icon(Heroicon::OutlinedQrCode)->url(fn () => route('pdf.labels', ['ids' => $this->record->id]), true),
                Action::make('showcase')->label(fn () => $this->record->showcase ? 'Tirar da vitrine' : 'Mostrar na vitrine')->icon(Heroicon::OutlinedEye)
                    ->visible($canEdit)
                    ->action(function () {
                        $this->record->update(['showcase' => ! $this->record->showcase]);
                        Notification::make()->title($this->record->showcase ? 'Peça na vitrine' : 'Peça removida da vitrine')->success()->send();
                    }),
                Action::make('collections')->label('Coleções')->icon(Heroicon::OutlinedStar)->visible($canEdit)
                    ->fillForm(fn () => ['collections' => $this->record->collections()->pluck('collections.id')->all()])
                    ->schema([
                        \Filament\Forms\Components\CheckboxList::make('collections')->label('Coleções desta peça')->columns(2)
                            ->options(fn () => \App\Models\ItemCollection::orderBy('name')->pluck('name', 'id')),
                        TextInput::make('new')->label('Ou crie uma nova coleção'),
                    ])
                    ->action(function (array $data) {
                        $ids = $data['collections'] ?? [];
                        if (filled($data['new'] ?? null)) {
                            $ids[] = \App\Models\ItemCollection::firstOrCreate(['name' => trim($data['new'])])->id;
                        }
                        $this->record->collections()->sync($ids);
                        Notification::make()->title('Coleções atualizadas')->success()->send();
                    }),
                $this->splitAction(),
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

    protected function movementAction(string $kind, string $label, string $personLabel): Action
    {
        return Action::make('mv_'.$kind)->label($label)
            ->modalHeading($label)
            ->modalDescription(fn () => "{$this->record->code} · {$this->record->title}")
            ->fillForm(fn () => ['date' => now()->toDateString(), 'person' => $kind === 'return' ? $this->record->status_person : null])
            ->schema([
                \Filament\Forms\Components\DatePicker::make('date')->label('Data')->required()->native(false)->displayFormat('d/m/Y'),
                TextInput::make('person')->label($personLabel)->required()->maxLength(120)
                    ->datalist(fn () => \App\Models\Movement::query()->distinct()->orderBy('person')->limit(100)->pluck('person')->all()),
                TextInput::make('notes')->label('Observação (opcional)')->maxLength(250),
            ])
            ->action(function (array $data) use ($kind) {
                \App\Models\Movement::register($this->record, $kind, $data['date'], $data['person'], $data['notes'] ?? null);
                Notification::make()->title(['loan' => 'Empréstimo registrado', 'sale' => 'Venda registrada', 'return' => 'Devolução registrada', 'undo' => 'Peça de volta ao acervo'][$kind])->success()->send();
                $this->record->refresh();
            });
    }

    protected function splitAction(): Action
    {
        return Action::make('split')->label('Separar fotos em nova peça')->icon(Heroicon::OutlinedScissors)
            ->visible(fn () => auth()->user()->canEdit() && $this->record->photos()->count() > 1)
            ->modalDescription('Marque as fotos que vão para a nova peça. Ela recebe um tombo novo e copia caixa, categoria, época e tags.')
            ->fillForm(fn () => ['title' => $this->record->title.' (parte)'])
            ->schema([
                \Filament\Forms\Components\CheckboxList::make('photos')->label('Fotos')->required()->columns(4)->allowHtml()
                    ->options(fn () => $this->record->photos->mapWithKeys(fn ($p, $i) => [$p->id => new \Illuminate\Support\HtmlString('<img src="'.e($p->thumbUrl()).'" alt="" style="width:88px;height:88px;object-fit:cover;border-radius:10px"> Foto '.($i + 1))])),
                TextInput::make('title')->label('Título da nova peça')->required(),
            ])
            ->action(function (array $data) {
                try {
                    $new = app(\App\Services\ItemSplitter::class)->split($this->record, array_map('intval', $data['photos']), $data['title']);
                } catch (\InvalidArgumentException $e) {
                    Notification::make()->title($e->getMessage())->warning()->send();

                    return;
                }
                Notification::make()->title("Nova peça {$new->code} criada")->success()->send();
                $this->redirect(ItemResource::getUrl('view', ['record' => $new]));
            });
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
