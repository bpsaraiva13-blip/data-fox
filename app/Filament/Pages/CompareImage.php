<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Items\ItemResource;
use App\Models\Item;
use App\Services\CuratorAI;
use App\Services\ImageSignature;
use App\Services\PhotoManager;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;
use UnitEnum;

/**
 * Compara uma foto nova (ou a foto de uma peça) com todo o acervo:
 * 1) ranking local pela assinatura visual  2) conferência opcional com IA.
 */
class CompareImage extends Page
{
    protected string $view = 'filament.pages.compare-image';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquare2Stack;

    protected static string|UnitEnum|null $navigationGroup = 'Acervo';

    protected static ?string $navigationLabel = 'Comparar imagem';

    protected static ?string $title = 'Comparar com o acervo';

    protected static ?int $navigationSort = 2;

    #[Url]
    public ?int $item = null;

    public ?array $data = [];

    /** @var array<int, array{id:int, code:string, title:string, box:?string, thumb:string, full:string, score:float, url:string}> */
    public array $results = [];

    public array $verdicts = [];

    public ?string $sourceUrl = null;

    public int $pick = 0;

    public function mount(): void
    {
        $this->form->fill();
        if ($this->item && ($photo = Item::find($this->item)?->cover)) {
            $this->runWith(Storage::disk(config('acervo.disk'))->get($photo->path), $photo->url(), $this->item);
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            FileUpload::make('photo')->hiddenLabel()->image()->maxSize(15 * 1024)
                ->placeholder('Solte a foto da peça aqui ou clique para escolher')
                ->live()
                ->afterStateUpdated(fn () => $this->compareUpload()),
        ]);
    }

    public function compareUpload(): void
    {
        $file = collect($this->data['photo'] ?? [])->first();
        if (! $file instanceof TemporaryUploadedFile) {
            return;
        }
        $this->item = null;
        $this->runWith($file->get(), $file->temporaryUrl(), null);
    }

    protected function runWith(string $bytes, string $previewUrl, ?int $exceptId): void
    {
        $sig = ImageSignature::fromBinary($bytes);
        $this->sourceUrl = $previewUrl;
        $this->verdicts = [];
        $this->pick = 0;
        $this->results = app(PhotoManager::class)->similar($sig, $exceptId, 8)->map(fn ($r) => [
            'id' => $r['item']->id,
            'code' => $r['item']->code,
            'title' => $r['item']->title,
            'box' => $r['item']->box?->name,
            'thumb' => $r['photo']->thumbUrl(),
            'full' => $r['photo']->url(),
            'thumb_path' => $r['photo']->thumb_path ?: $r['photo']->path,
            'score' => round($r['score'], 3),
            'url' => ItemResource::getUrl('view', ['record' => $r['item']]),
        ])->all();
    }

    public function select(int $i): void
    {
        $this->pick = $i;
    }

    public function verifyWithAi(): void
    {
        if (! $this->results) {
            return;
        }
        $disk = Storage::disk(config('acervo.disk'));

        try {
            $reference = $this->item
                ? $disk->get(Item::find($this->item)->cover->path)
                : collect($this->data['photo'] ?? [])->first()?->get();

            $cands = collect($this->results)->take(config('acervo.ai.max_images') - 1)->map(fn ($r) => [
                'item' => Item::find($r['id']),
                'image' => $disk->get($r['thumb_path']),
            ])->all();

            $v = app(CuratorAI::class)->compare(ImageSignature::thumbnail($reference, 1024), $cands);
        } catch (Throwable $e) {
            Notification::make()->title('Conferência indisponível')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->verdicts = collect($v)->mapWithKeys(fn ($x, $id) => [(int) $id => [
            'v' => in_array($x['veredito'] ?? '', ['same', 'similar', 'diff'], true) ? $x['veredito'] : 'diff',
            'c' => (int) ($x['confianca'] ?? 0),
            'n' => (string) ($x['nota'] ?? ''),
        ]])->all();

        $order = ['same' => 0, 'similar' => 1, 'diff' => 2];
        usort($this->results, fn ($a, $b) => [($order[$this->verdicts[$a['id']]['v'] ?? 'x'] ?? 3), -($this->verdicts[$a['id']]['c'] ?? 0), -$a['score']]
            <=> [($order[$this->verdicts[$b['id']]['v'] ?? 'x'] ?? 3), -($this->verdicts[$b['id']]['c'] ?? 0), -$b['score']]);
        $this->pick = 0;

        $same = collect($this->results)->first(fn ($r) => ($this->verdicts[$r['id']]['v'] ?? null) === 'same');
        Notification::make()->title($same ? "Encontrada: {$same['code']}" : 'Nenhuma peça idêntica no acervo')
            ->{$same ? 'success' : 'info'}()->send();
    }

    public function aiEnabled(): bool
    {
        return app(CuratorAI::class)->enabled();
    }
}
