<?php

namespace App\Filament\Resources\Items;

use App\Filament\Pages\CompareImage;
use App\Filament\Resources\Items\Pages\CreateItem;
use App\Filament\Resources\Items\Pages\EditItem;
use App\Filament\Resources\Items\Pages\ListItems;
use App\Filament\Resources\Items\Pages\ViewItem;
use App\Models\ActivityLog;
use App\Models\Box;
use App\Models\Item;
use App\Models\Tag;
use App\Services\CuratorAI;
use App\Services\ImageSignature;
use App\Services\PhotoManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;
use UnitEnum;

class ItemResource extends Resource
{
    protected static ?string $model = Item::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'Acervo';

    protected static ?string $modelLabel = 'peça';

    protected static ?string $pluralModelLabel = 'peças';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 1;

    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'code', 'era', 'material'];
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) Item::count();
    }

    /* ------------------------------------------------------------------ form */

    public static function form(Schema $schema): Schema
    {
        $disk = config('acervo.disk');

        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 5])->columnSpanFull()->schema([
                Section::make('Fotos')
                    ->description('Frente, verso, marcas e assinaturas. A primeira é a capa.')
                    ->icon(Heroicon::OutlinedCamera)
                    ->columnSpan(['lg' => 2])
                    ->schema([
                        FileUpload::make('photo_paths')
                            ->hiddenLabel()
                            ->image()
                            ->multiple()
                            ->reorderable()
                            ->appendFiles()
                            ->panelLayout('grid')
                            ->imageEditor()
                            ->maxSize(15 * 1024)
                            ->maxFiles(20)
                            ->disk($disk)
                            ->directory(config('acervo.photos_dir'))
                            ->visibility('private')
                            ->live(),

                        Actions::make([
                            Action::make('analyze')
                                ->label('Analisar fotos com IA')
                                ->icon(Heroicon::Sparkles)
                                ->color('warning')
                                ->visible(fn () => app(CuratorAI::class)->enabled())
                                ->action(fn (Get $get, Set $set, ?Item $record) => static::runAnalysis($get, $set, $record)),
                        ])->fullWidth(),

                        Hidden::make('ai_keywords'),
                        Hidden::make('ai_authenticity'),
                    ]),

                Section::make('Ficha de catalogação')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->columnSpan(['lg' => 3])
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')->label('Título')->required()->maxLength(255)->columnSpanFull()
                            ->placeholder('ex.: Bule de porcelana Limoges'),
                        Select::make('category')->label('Categoria')->searchable()
                            ->options(array_combine(config('acervo.categories'), config('acervo.categories'))),
                        TextInput::make('era')->label('Época / datação')->placeholder('c. 1880–1900'),
                        TextInput::make('origin')->label('Origem'),
                        TextInput::make('material')->label('Material / técnica'),
                        TextInput::make('dimensions')->label('Dimensões')->placeholder('A × L × P cm'),
                        Select::make('condition')->label('Estado de conservação')
                            ->options(array_combine(config('acervo.conditions'), config('acervo.conditions'))),
                        Select::make('box_id')->label('Caixa')
                            ->relationship('box', 'name', fn (Builder $q) => $q->naturalOrder())->searchable()->preload()
                            ->createOptionForm(static::boxFields())
                            ->createOptionModalHeading('Nova caixa'),
                        TextInput::make('value')->label('Valor estimado')->numeric()->prefix('R$')->minValue(0),
                        TagsInput::make('tag_names')->label('Tags')->columnSpanFull()
                            ->suggestions(fn () => Tag::orderBy('name')->pluck('name')->all())
                            ->splitKeys(['Tab', ',', ' ']),
                        Textarea::make('description')->label('Descrição')->rows(4)->columnSpanFull(),
                        TextInput::make('provenance')->label('Procedência'),
                        DatePicker::make('acquired_at')->label('Data de aquisição')->native(false)->displayFormat('d/m/Y'),
                        Textarea::make('notes')->label('Observações internas')->rows(2)->columnSpanFull(),
                    ]),
            ]),
        ]);
    }

    public static function boxFields(): array
    {
        return [
            TextInput::make('name')->label('Nome')->required()->unique(Box::class, 'name', ignoreRecord: true)
                ->placeholder('Caixa A1 — Porcelanas'),
            TextInput::make('location')->label('Local físico')->placeholder('Depósito, estante 2, prateleira 3'),
            ColorPicker::make('color')->label('Cor')->default(config('acervo.box_colors')[0]),
        ];
    }

    /** Lê as fotos do formulário (enviadas agora ou já salvas) e preenche campos vazios. */
    protected static function runAnalysis(Get $get, Set $set, ?Item $record): void
    {
        $disk = Storage::disk(config('acervo.disk'));
        $images = collect($get('photo_paths') ?? [])
            ->take(config('acervo.ai.max_images'))
            ->map(fn ($f) => $f instanceof TemporaryUploadedFile ? $f->get() : $disk->get($f))
            ->map(fn ($bin) => ImageSignature::thumbnail($bin, 1024))
            ->all();

        if (! $images) {
            Notification::make()->title('Adicione ao menos uma foto.')->warning()->send();

            return;
        }

        try {
            $ctx = collect(['title', 'category', 'era', 'origin', 'material'])->map(fn ($k) => $get($k))->filter()->implode(' · ');
            $r = app(CuratorAI::class)->analyze($images, $ctx);
        } catch (Throwable $e) {
            Notification::make()->title('Não foi possível analisar')->body($e->getMessage())->danger()->send();

            return;
        }

        $fill = fn (string $field, $value) => blank($get($field)) && filled($value) ? $set($field, $value) : null;
        $fill('title', $r['titulo'] ?? null);
        $fill('era', $r['epoca'] ?? null);
        $fill('origin', $r['origem'] ?? null);
        $fill('material', $r['material'] ?? null);
        $fill('description', $r['descricao'] ?? null);
        if (in_array($r['categoria'] ?? null, config('acervo.categories'), true)) {
            $fill('category', $r['categoria']);
        }
        if (in_array($r['estado'] ?? null, config('acervo.conditions'), true)) {
            $fill('condition', $r['estado']);
        }
        $set('tag_names', collect($get('tag_names') ?? [])->merge($r['tags'] ?? [])->map(fn ($t) => Tag::normalize($t))->filter()->unique()->values()->all());
        $set('ai_keywords', array_slice($r['palavras_chave'] ?? [], 0, 20));
        $set('ai_authenticity', $r['observacao_autenticidade'] ?? null);

        // Aviso de possível duplicata pela assinatura visual da primeira foto
        $dupes = app(PhotoManager::class)
            ->similar(ImageSignature::fromBinary($images[0]), $record?->id, 3)
            ->where('score', '>=', config('acervo.duplicate_threshold'));

        Notification::make()
            ->title('Sugestões aplicadas')
            ->body('Campos já preenchidos foram mantidos.'.(filled($r['observacao_autenticidade'] ?? null) ? ' Autenticidade: '.$r['observacao_autenticidade'] : ''))
            ->success()->send();

        foreach ($dupes as $d) {
            Notification::make()
                ->title('Possível duplicata: '.$d['item']->code)
                ->body($d['item']->title.' · '.round($d['score'] * 100).'% parecida')
                ->warning()->persistent()
                ->actions([Action::make('ver')->label('Abrir ficha')->url(static::getUrl('view', ['record' => $d['item']]), true)])
                ->send();
        }
    }

    /* -------------------------------------------------------------- infolist */

    public static function infolist(Schema $schema): Schema
    {
        $disk = config('acervo.disk');

        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 5])->columnSpanFull()->schema([
                Section::make()->columnSpan(['lg' => 2])->schema([
                    ImageEntry::make('photos.path')->hiddenLabel()->disk($disk)->visibility('private')
                        ->imageHeight(220)->stacked(false)->extraImgAttributes(['class' => 'rounded-lg object-cover acervo-zoom']),
                ]),
                Section::make(fn (Item $record) => $record->code)->columnSpan(['lg' => 3])->columns(2)->schema([
                    TextEntry::make('title')->label('Título')->size('lg')->weight('bold')->columnSpanFull(),
                    TextEntry::make('tags.name')->label('Tags')->badge()->prefix('#')->columnSpanFull(),
                    TextEntry::make('category')->label('Categoria')->placeholder('—'),
                    TextEntry::make('era')->label('Época')->placeholder('—'),
                    TextEntry::make('origin')->label('Origem')->placeholder('—'),
                    TextEntry::make('material')->label('Material')->placeholder('—'),
                    TextEntry::make('dimensions')->label('Dimensões')->placeholder('—'),
                    TextEntry::make('condition')->label('Estado')->badge()->placeholder('—'),
                    TextEntry::make('box.name')->label('Caixa')->placeholder('Sem caixa')
                        ->helperText(fn (Item $record) => $record->box?->location),
                    TextEntry::make('value')->label('Valor estimado')->money('BRL', locale: 'pt_BR')->placeholder('—'),
                    TextEntry::make('provenance')->label('Procedência')->placeholder('—'),
                    TextEntry::make('acquired_at')->label('Aquisição')->date('d/m/Y')->placeholder('—'),
                    TextEntry::make('description')->label('Descrição')->columnSpanFull()->placeholder('—'),
                    TextEntry::make('ai_authenticity')->label('Autenticidade — verificar')->columnSpanFull()
                        ->visible(fn (Item $record) => filled($record->ai_authenticity))->color('warning'),
                ]),
                Section::make('Cadastro')->icon(Heroicon::OutlinedUserCircle)->columnSpan(['lg' => 2])->schema([
                    TextEntry::make('creator.name')->label('Cadastrado por')->placeholder('—'),
                    TextEntry::make('created_at')->label('Cadastrado em')->dateTime('d/m/Y H:i'),
                    TextEntry::make('editor.name')->label('Última alteração por')->placeholder('—'),
                    TextEntry::make('updated_at')->label('Última alteração')->since()->dateTimeTooltip('d/m/Y H:i'),
                    TextEntry::make('merged_from')->label('Peças unidas a esta')->badge()
                        ->state(fn (Item $record) => collect($record->merged_from ?? [])->pluck('code')->filter()->all())
                        ->visible(fn (Item $record) => filled($record->merged_from)),
                    TextEntry::make('batch')->label('Lote')->placeholder('—')->visible(fn (Item $record) => filled($record->batch)),
                ]),
                Section::make('Histórico')->icon(Heroicon::OutlinedClock)->columnSpan(['lg' => 3])->schema([
                    RepeatableEntry::make('activities')->hiddenLabel()->contained(false)->schema([
                        TextEntry::make('label')->hiddenLabel()
                            ->helperText(fn (ActivityLog $record) => $record->actor.' · '.$record->created_at->format('d/m/Y H:i')),
                    ]),
                ]),
            ]),
        ]);
    }

    /* ----------------------------------------------------------------- table */

    public static function table(Table $table): Table
    {
        $disk = config('acervo.disk');

        return $table
            ->modifyQueryUsing(function (Builder $query, $livewire) {
                $query->with(['cover', 'box', 'tags', 'creator']);
                if ($batch = $livewire->batch ?? null) {
                    $query->where('batch', $batch);
                }
                $ids = $livewire->aiIds ?? null;
                if (is_array($ids)) {
                    $ids = array_map('intval', $ids) ?: [0];
                    $query->whereIn('items.id', $ids)
                        ->reorder()
                        ->orderByRaw('array_position(ARRAY['.implode(',', $ids).']::bigint[], items.id)');
                }
            })
            ->contentGrid(['md' => 2, 'xl' => 3, '2xl' => 4])
            ->poll(fn () => Item::whereIn('ai_status', ['pending', 'running'])->exists() ? '6s' : null)
            ->recordUrl(fn (Item $r) => static::getUrl('view', ['record' => $r]))
            ->columns([
                Stack::make([
                    ImageColumn::make('cover.thumb_path')->disk($disk)->visibility('private')
                        ->imageHeight(220)->width('100%')
                        ->extraImgAttributes(['class' => 'w-full object-cover rounded-lg acervo-card-img'])
                        ->defaultImageUrl(fn () => 'data:image/svg+xml,'.rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 5"><rect width="4" height="5" fill="#dce3dd"/></svg>')),
                    Split::make([
                        TextColumn::make('code')->label('Tombo')->badge()->color('warning')->fontFamily('mono')->searchable()->sortable(),
                        TextColumn::make('created_at')->label('Cadastro')->since()->color('gray')->size('xs')->alignEnd()->sortable(),
                    ]),
                    TextColumn::make('title')->label('Título')->weight('semibold')->size('lg')->limit(60)
                        ->searchable(query: fn (Builder $q, string $search) => $q->search($search))->sortable(),
                    TextColumn::make('era')->color('gray')->placeholder('—')
                        ->description(fn (Item $r) => ($r->box?->name ?? 'Sem caixa').' · por '.($r->creator?->name ?? '—')),
                    TextColumn::make('tags.name')->badge()->prefix('#')->limitList(4)->color('gray'),
                    TextColumn::make('ai_status')->badge()
                        ->getStateUsing(fn (Item $record) => in_array($record->ai_status, ['pending', 'running', 'failed'], true) ? $record->ai_status : null)
                        ->formatStateUsing(fn (string $state) => ['pending' => 'IA na fila', 'running' => 'IA analisando…', 'failed' => 'IA falhou', 'done' => 'IA concluída', 'none' => ''][$state] ?? $state)
                        ->color(fn (string $state) => $state === 'failed' ? 'danger' : 'warning')
                        ->icon(fn (string $state) => $state === 'running' ? Heroicon::ArrowPath : null)
                        ->tooltip(fn (Item $record) => $record->ai_error),
                ])->space(2),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('box_id')->label('Caixa')->relationship('box', 'name', fn (Builder $q) => $q->naturalOrder())->searchable()->preload(),
                SelectFilter::make('batch')->label('Lote')->options(fn () => Item::whereNotNull('batch')->distinct()->orderByDesc('batch')->limit(50)->pluck('batch', 'batch')),
                SelectFilter::make('category')->label('Categoria')->options(array_combine(config('acervo.categories'), config('acervo.categories'))),
                SelectFilter::make('condition')->label('Estado')->options(array_combine(config('acervo.conditions'), config('acervo.conditions'))),
                SelectFilter::make('tags')->label('Tags')->relationship('tags', 'name')->multiple()->searchable()->preload(),
                SelectFilter::make('created_by')->label('Cadastrado por')->relationship('creator', 'name')->searchable()->preload(),
                TrashedFilter::make(),
            ])
            ->filtersFormColumns(2)
            ->recordActions([
                ViewAction::make()->iconButton(),
                EditAction::make()->iconButton(),
                Action::make('similar')->label('Peças parecidas')->icon(Heroicon::OutlinedSquare2Stack)->iconButton()
                    ->visible(fn (Item $r) => $r->cover !== null)
                    ->url(fn (Item $r) => CompareImage::getUrl(['item' => $r->id])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('move')->label('Mover para caixa')->icon(Heroicon::OutlinedArchiveBoxArrowDown)
                        ->schema([Select::make('box_id')->label('Caixa')->options(fn () => Box::naturalOrder()->pluck('name', 'id'))->placeholder('Sem caixa')])
                        ->action(function (Collection $records, array $data) {
                            $records->each(fn (Item $i) => $i->update(['box_id' => $data['box_id'] ?: null]));
                            Notification::make()->title($records->count().' peça(s) movida(s)')->success()->send();
                        })->deselectRecordsAfterCompletion(),
                    BulkAction::make('tag')->label('Adicionar tags')->icon(Heroicon::OutlinedTag)
                        ->schema([TagsInput::make('tags')->label('Tags')->required()->suggestions(fn () => Tag::pluck('name')->all())])
                        ->action(function (Collection $records, array $data) {
                            $ids = collect($data['tags'])->map(fn ($t) => Tag::normalize($t))->filter()->map(fn ($n) => Tag::firstOrCreate(['name' => $n])->id);
                            $records->each(fn (Item $i) => $i->tags()->syncWithoutDetaching($ids));
                            ActivityLog::record('tag', null, 'Adicionou '.collect($data['tags'])->map(fn ($t) => '#'.$t)->implode(' ')." a {$records->count()} peça(s)");
                            Notification::make()->title('Tags adicionadas')->success()->send();
                        })->deselectRecordsAfterCompletion(),
                    BulkAction::make('merge')->label('Unir em uma peça')->icon(Heroicon::OutlinedLink)
                        ->modalHeading('Unir em uma só ficha')
                        ->modalDescription('A ficha escolhida mantém o Nº de tombo e os dados. Campos vazios são completados com as outras, fotos e tags são somadas e as demais fichas deixam de existir.')
                        ->schema(fn (Collection $records) => [
                            \Filament\Forms\Components\Radio::make('main')->label('Qual ficha fica?')->required()
                                ->options($records->sortBy('code')->mapWithKeys(fn (Item $i) => [$i->id => "{$i->code} · {$i->title} (".$i->photos()->count().' foto(s))']))
                                ->default($records->sortBy('code')->first()?->id),
                            \Filament\Forms\Components\Toggle::make('reanalyze')->label('Refazer a análise da IA com todas as fotos')
                                ->default(app(CuratorAI::class)->enabled())->disabled(! app(CuratorAI::class)->enabled()),
                        ])
                        ->action(function (Collection $records, array $data) {
                            if ($records->count() < 2) {
                                Notification::make()->title('Selecione ao menos duas peças.')->warning()->send();

                                return;
                            }
                            $main = app(\App\Services\ItemMerger::class)->merge($records->firstWhere('id', (int) $data['main']), $records, (bool) ($data['reanalyze'] ?? false));
                            Notification::make()->title($records->count()." peças unidas em {$main->code}")->success()->send();
                        })->deselectRecordsAfterCompletion(),
                    BulkAction::make('reanalyze')->label('Analisar com IA')->icon(Heroicon::Sparkles)
                        ->visible(fn () => app(CuratorAI::class)->enabled())
                        ->requiresConfirmation()->modalDescription('As fichas selecionadas entram na fila da IA. Campos já preenchidos são mantidos.')
                        ->action(function (Collection $records) {
                            $records->each(function (Item $i) {
                                $i->update(['ai_status' => 'pending']);
                                \App\Jobs\AnalyzeItem::dispatch($i->id);
                            });
                            Notification::make()->title($records->count().' peça(s) na fila da IA')->success()->send();
                        })->deselectRecordsAfterCompletion(),
                    BulkAction::make('csv')->label('Exportar CSV')->icon(Heroicon::OutlinedArrowDownTray)
                        ->action(fn (Collection $records) => static::exportCsv($records)),
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('O gabinete está vazio')
            ->emptyStateDescription('Cadastre a primeira peça com fotos. A IA sugere título, época, material e tags.')
            ->emptyStateIcon(Heroicon::OutlinedArchiveBox);
    }

    protected static function exportCsv(Collection $records)
    {
        $records->load(['box', 'tags', 'creator']);

        return response()->streamDownload(function () use ($records) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Tombo', 'Título', 'Categoria', 'Época', 'Origem', 'Material', 'Dimensões', 'Estado', 'Caixa', 'Valor estimado', 'Tags', 'Procedência', 'Aquisição', 'Cadastrado em', 'Cadastrado por', 'Descrição'], ';');
            foreach ($records as $i) {
                fputcsv($out, [$i->code, $i->title, $i->category, $i->era, $i->origin, $i->material, $i->dimensions, $i->condition, $i->box?->name, $i->value, $i->tags->pluck('name')->implode(', '), $i->provenance, $i->acquired_at?->format('d/m/Y'), $i->created_at->format('d/m/Y H:i'), $i->creator?->name, $i->description], ';');
            }
            fclose($out);
        }, 'acervo-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListItems::route('/'),
            'create' => CreateItem::route('/create'),
            'view' => ViewItem::route('/{record}'),
            'edit' => EditItem::route('/{record}/edit'),
        ];
    }
}
