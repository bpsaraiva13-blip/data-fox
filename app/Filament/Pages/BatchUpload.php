<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Items\ItemResource;
use App\Models\Box;
use App\Services\BatchUploader;
use App\Services\CuratorAI;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Throwable;
use UnitEnum;

/**
 * Cadastro em lote: todas as fotos de uma caixa de uma vez.
 * As fichas são criadas na hora; a IA preenche cada uma em segundo plano.
 */
class BatchUpload extends Page
{
    protected string $view = 'filament.pages.batch-upload';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Acervo';

    protected static ?string $navigationLabel = 'Cadastro em lote';

    protected static ?string $title = 'Enviar uma caixa inteira';

    protected static ?int $navigationSort = 2;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->canEdit() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'box_id' => request()->integer('box') ?: null,
            'mode' => 'name',
            'per' => 2,
            'ai' => app(CuratorAI::class)->enabled(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Fotos')
                ->description('Arraste todas as fotos da caixa. Dezenas ou centenas de imagens de uma vez.')
                ->hidden(fn (Get $get) => filled($get('zip')))
                ->schema([
                    FileUpload::make('files')
                        ->hiddenLabel()
                        ->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'image/heif'])
                        ->multiple()
                        ->maxFiles(400)
                        ->maxSize(15 * 1024)
                        ->maxParallelUploads(4)
                        ->panelLayout('grid')
                        ->disk(config('acervo.disk'))
                        ->directory(config('acervo.photos_dir'))
                        ->visibility('private')
                        ->storeFileNamesIn('names')
                        ->required(fn (Get $get) => blank($get('zip'))),
                ]),
            Section::make('Ou um .zip com pastas')
                ->description('As pastas se organizam sozinhas: cada pasta vira uma caixa e cada subpasta vira uma peça (ou, se preferir, tudo numa caixa só). Processado em segundo plano; o aviso chega no sininho.')
                ->collapsible()->collapsed(fn (Get $get) => blank($get('zip')))
                ->schema([
                    FileUpload::make('zip')->hiddenLabel()
                        ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed', 'application/octet-stream'])
                        ->maxSize(4 * 1024 * 1024)          // 4 GB
                        ->disk(config('acervo.disk'))->directory('imports/zips')->visibility('private')
                        ->storeFileNamesIn('zip_name')
                        ->live(),
                    Radio::make('org')->label('Organização')->default('boxes')->live()
                        ->visible(fn (Get $get) => filled($get('zip')))
                        ->options([
                            'boxes' => 'Cada pasta vira uma caixa (subpastas viram peças)',
                            'box' => 'Tudo na caixa escolhida abaixo (cada pasta vira uma peça)',
                        ]),
                ]),
            Section::make('Organização')->columns(2)->schema([
                Select::make('box_id')->label('Caixa de destino')
                    ->options(fn () => Box::naturalOrder()->pluck('name', 'id'))
                    ->searchable()
                    ->required(fn (Get $get) => blank($get('zip')) || $get('org') === 'box')
                    ->helperText(fn (Get $get) => filled($get('zip')) && $get('org') !== 'box' ? 'Opcional: recebe as fotos soltas fora de pastas (sem ela, a caixa leva o nome do .zip).' : null)
                    ->createOptionForm(ItemResource::boxFields())
                    ->createOptionUsing(fn (array $data) => Box::create($data)->id),
                TagsInput::make('tags')->label('Tags para todas as peças do lote')->placeholder('ex.: espolio-silva'),
                Radio::make('mode')->label('Como separar as peças')->columnSpanFull()->live()->options([
                    'name' => 'Pelo nome do arquivo — bule_frente.jpg e bule_verso.jpg viram uma peça só',
                    'one' => 'Uma foto, uma peça',
                    'seq' => 'Em sequência — a cada N fotos, uma nova peça',
                ]),
                TextInput::make('per')->label('Fotos por peça')->numeric()->minValue(2)->maxValue(10)
                    ->visible(fn (Get $get) => $get('mode') === 'seq'),
                Toggle::make('ai')->label('Analisar cada peça com IA (em segundo plano)')->columnSpanFull()
                    ->helperText('Preenche título, época, origem, material, estado, descrição e tags. Você pode continuar usando o sistema enquanto isso.')
                    ->disabled(fn () => ! app(CuratorAI::class)->enabled()),
            ]),
        ]);
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('submit')->label('Cadastrar peças')->icon(Heroicon::Check)->submit('submit'),
        ];
    }

    public function submit(): void
    {
        $data = $this->form->getState();

        if (filled($data['zip'] ?? null)) {
            $zipPath = is_array($data['zip']) ? array_values($data['zip'])[0] : $data['zip'];
            $zipName = is_array($data['zip_name'] ?? null) ? (array_values($data['zip_name'])[0] ?? basename($zipPath)) : ($data['zip_name'] ?? basename($zipPath));
            \App\Jobs\ImportZip::dispatch(
                config('acervo.disk'), $zipPath, $zipName, \App\Models\Acervo::current()->id, auth()->id(),
                $data['org'] ?? 'boxes', $data['box_id'] ?? null, $data['mode'] ?? 'name', (int) ($data['per'] ?? 2),
                (bool) ($data['ai'] ?? false), $data['tags'] ?? [],
            );
            Notification::make()->success()->title('Importando o .zip em segundo plano')
                ->body('Você pode continuar usando o sistema. O aviso chega no sininho quando terminar.')->send();
            $this->form->fill(['mode' => 'name', 'per' => 2, 'org' => 'boxes']);
            if (blank($data['files'] ?? null)) {
                return;
            }
        }
        // nomes originais dos arquivos (para agrupar frente/verso), gravados pelo FileUpload
        $files = $this->data['names'] ?? $data['names'] ?? [];
        foreach ((array) ($data['files'] ?? []) as $path) {
            $files[$path] ??= basename($path);
        }

        try {
            $res = app(BatchUploader::class)->run(
                Box::findOrFail($data['box_id']),
                $files,
                $data['mode'] ?? 'name',
                (int) ($data['per'] ?? 2),
                (bool) ($data['ai'] ?? false),
                $data['tags'] ?? [],
            );
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title('Não foi possível concluir o lote')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title("{$res['items']} peça(s) cadastrada(s)")
            ->body(($data['ai'] ?? false) ? 'A IA está preenchendo as fichas. Elas atualizam sozinhas na listagem.' : null)
            ->success()->send();

        $this->redirect(ItemResource::getUrl('index', ['batch' => $res['batch']]));
    }

    /** Remove arquivos enviados que ficaram órfãos se a pessoa sair sem cadastrar. */
    public function cancel(): void
    {
        foreach ((array) ($this->data['files'] ?? []) as $f) {
            if (is_string($f)) {
                Storage::disk(config('acervo.disk'))->delete($f);
            }
        }
        $this->form->fill(['mode' => 'name', 'per' => 2]);
    }
}
