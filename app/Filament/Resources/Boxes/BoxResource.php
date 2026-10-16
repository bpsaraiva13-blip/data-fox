<?php

namespace App\Filament\Resources\Boxes;

use App\Filament\Pages\BatchUpload;
use App\Filament\Resources\Boxes\Pages\ManageBoxes;
use App\Filament\Resources\Items\ItemResource;
use App\Models\Box;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use App\Support\ItemAccess;
use Illuminate\Database\Eloquent\Builder;
use Filament\Actions\ActionGroup;
use Illuminate\Support\HtmlString;
use UnitEnum;

class BoxResource extends Resource
{
    protected static ?string $model = Box::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = 'Acervo';

    protected static ?string $modelLabel = 'caixa';

    protected static ?string $pluralModelLabel = 'caixas';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components(ItemResource::boxFields())->columns(1);
    }

    /** URL do acervo filtrado por esta caixa. */
    public static function itemsUrl(Box $box, ?\Illuminate\Database\Eloquent\Model $tenant = null): string
    {
        return ItemResource::getUrl('index', ['filters' => ['box_id' => ['value' => $box->id]]], tenant: $tenant);
    }

    /** Selo com o número da caixa (ou a inicial), na cor da caixa. */
    public static function badge(Box $box, int $size = 48): HtmlString
    {
        $n = Box::leadingNumber($box->name);
        $label = $n !== null ? (string) $n : mb_strtoupper(mb_substr(preg_replace('/^\s*(caixa|cx\.?)\s+/iu', '', $box->name) ?: '?', 0, 1));

        return new HtmlString(sprintf(
            '<span class="df-box-badge" style="--c:%s;width:%dpx;height:%dpx">%s</span>',
            e($box->color ?: '#1F4D36'), $size, $size, e($label)
        ));
    }

    public static function table(Table $table): Table
    {
        $grid = ($table->getLivewire()->boxLayout ?? 'grid') === 'grid';

        $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['items', 'linkedAcervos'])->with(['creator', 'acervo'])->reorder()->naturalOrder())
            ->recordUrl(fn (Box $r) => static::itemsUrl($r))
            ->paginated([24, 48, 96, 'all'])
            ->recordActions([
                ActionGroup::make([
                    Action::make('label')->label('Etiqueta QR da caixa')->icon(Heroicon::OutlinedQrCode)
                        ->url(fn (Box $r) => route('pdf.box', $r->id), true),
                    Action::make('catalog')->label('Catálogo em PDF')->icon(Heroicon::OutlinedDocumentText)
                        ->visible(fn (Box $r) => $r->items_count > 0 && $r->items_count <= 150)
                        ->url(fn (Box $r) => route('pdf.catalog', ['ids' => $r->items()->pluck('id')->implode(','), 'titulo' => $r->name]), true),
                    Action::make('inventory')->label('Conferir caixa')->icon(Heroicon::OutlinedClipboardDocumentCheck)
                        ->visible(fn () => auth()->user()->canEdit())
                        ->url(fn (Box $r) => \App\Filament\Pages\Inventory::getUrl(['box' => $r->id])),
                    \App\Support\TransferForm::boxAction()->hidden(fn (Box $r) => $r->isLinkedHere()),
                    Action::make('link')->label('Vincular a outro acervo')->icon(Heroicon::OutlinedLink)
                        ->visible(fn (Box $r) => ! $r->isLinkedHere() && \App\Support\TransferForm::visible())
                        ->modalHeading(fn (Box $r) => "Vincular {$r->name}")
                        ->modalDescription('A caixa e as peças passam a aparecer também nos acervos marcados, sempre iguais: o que for cadastrado ou editado em um aparece no outro. As peças continuam pertencendo a este acervo; coleções, movimentações e conferências ficam em cada acervo.')
                        ->fillForm(fn (Box $r) => ['acervos' => $r->linkedAcervos()->pluck('acervos.id')->all()])
                        ->schema([
                            \Filament\Forms\Components\CheckboxList::make('acervos')->label('Mostrar também em')
                                ->options(fn (Box $r) => \App\Services\ItemTransfer::targets(auth()->user())->pluck('name', 'id')
                                    ->union($r->linkedAcervos()->pluck('acervos.name', 'acervos.id'))->all()),
                        ])
                        ->action(function (Box $r, array $data) {
                            $before = $r->linkedAcervos()->pluck('acervos.id')->all();
                            $allowed = \App\Services\ItemTransfer::targets(auth()->user())->pluck('id')->all();
                            // só adiciona onde a pessoa é editora; vínculos já existentes podem ser removidos
                            $sel = array_values(array_filter(array_map('intval', $data['acervos'] ?? []), fn ($id) => in_array($id, $allowed, true) || in_array($id, $before, true)));
                            $r->linkedAcervos()->syncWithPivotValues($sel, ['created_by' => auth()->id()]);
                            $names = \App\Models\Acervo::whereIn('id', $sel)->pluck('name')->implode(', ');
                            \App\Models\ActivityLog::record('box', null, $sel ? "Vinculou a caixa {$r->name} a {$names}" : "Removeu os vínculos da caixa {$r->name}");
                            foreach (array_diff($sel, $before) as $aid) {
                                \App\Models\ActivityLog::record('box', null, "Caixa {$r->name} vinculada do acervo {$r->acervo->name}", acervoId: $aid);
                            }
                            \Filament\Notifications\Notification::make()->success()->title($sel ? 'Vínculos salvos' : 'Vínculos removidos')->send();
                        }),
                    Action::make('unlink')->label('Remover vínculo deste acervo')->icon(Heroicon::OutlinedXMark)->color('danger')
                        ->visible(fn (Box $r) => $r->isLinkedHere() && auth()->user()->canEdit())
                        ->requiresConfirmation()
                        ->modalDescription(fn (Box $r) => "A caixa deixa de aparecer aqui. As peças continuam no acervo {$r->acervo?->name}; nada é apagado.")
                        ->action(function (Box $r) {
                            $r->linkedAcervos()->detach(\App\Models\Acervo::current()->id);
                            \App\Models\ActivityLog::record('box', null, "Removeu o vínculo da caixa {$r->name} (de {$r->acervo?->name})");
                        }),
                    Action::make('camera')->label('Fotografar peças')->icon(Heroicon::OutlinedCamera)
                        ->visible(fn () => auth()->user()->canEdit())
                        ->url(fn (Box $r) => \App\Filament\Pages\Camera::getUrl(['box' => $r->id])),
                ])->iconButton()->color('gray'),
                Action::make('batch')->label('Lote')->icon(Heroicon::OutlinedArrowUpTray)->color('gray')
                    ->visible(fn () => auth()->user()->canEdit())
                    ->url(fn (Box $r) => BatchUpload::getUrl(['box' => $r->id])),
                EditAction::make()->iconButton(),
                DeleteAction::make()->iconButton()->hidden(fn (Box $r) => $r->isLinkedHere())
                    ->modalDescription(fn (Box $r) => ($r->items_count ? "As {$r->items_count} peça(s) desta caixa ficarão como “Sem caixa”. " : '').'A caixa fica 30 dias na Lixeira; ao restaurar, as peças voltam para ela.'),
            ]);

        if ($grid) {
            return $table
                ->contentGrid(['md' => 2, 'xl' => 3])
                ->columns([
                    Stack::make([
                        Split::make([
                            TextColumn::make('badge')->state(fn (Box $r) => static::badge($r, 52))->html()->grow(false),
                            Stack::make([
                                TextColumn::make('name')->weight('bold')->size('lg')->searchable(),
                                TextColumn::make('location')->color('gray')->placeholder('Sem local definido'),
                                TextColumn::make('link_note')->state(fn (Box $r) => static::linkNote($r))->color('primary')->size('sm')->weight('semibold')
                                    ->icon(Heroicon::OutlinedLink),
                            ]),
                        ])->extraAttributes(['class' => 'df-box-top']),
                        ViewColumn::make('items_count')->view('filament.box-bar'),
                        TextColumn::make('created_at')->color('gray')->size('sm')
                            ->formatStateUsing(fn (Box $r) => "{$r->items_count} peça".($r->items_count === 1 ? '' : 's').' · criada '.$r->created_at->diffForHumans()),
                    ])->space(3)->extraAttributes(['class' => 'df-box-card']),
                ]);
        }

        return $table->columns([
            TextColumn::make('badge')->label('')->state(fn (Box $r) => static::badge($r, 40))->html()->width('56px'),
            TextColumn::make('name')->label('Caixa')->weight('semibold')->searchable(),
            TextColumn::make('location')->label('Local')->placeholder('—')->color('gray'),
            TextColumn::make('link_note')->label('Vínculo')->state(fn (Box $r) => static::linkNote($r))->color('primary')->placeholder('—'),
            TextColumn::make('items_count')->label('Peças')->numeric()->alignEnd(),
            TextColumn::make('creator.name')->label('Criada por')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('created_at')->label('Criada')->since(),
        ]);
    }

    public static function linkNote(Box $box): ?string
    {
        if ($box->isLinkedHere()) {
            return 'de '.($box->acervo?->name ?? 'outro acervo');
        }
        $n = $box->linked_acervos_count ?? $box->linkedAcervos()->count();

        return $n ? 'em '.($n + 1).' acervos' : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return ItemAccess::restrictBoxes(parent::getEloquentQuery());
    }

    public static function getPages(): array
    {
        return ['index' => ManageBoxes::route('/')];
    }
}
