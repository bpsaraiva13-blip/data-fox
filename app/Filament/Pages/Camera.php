<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Items\ItemResource;
use App\Jobs\AnalyzeItem;
use App\Models\ActivityLog;
use App\Models\Box;
use App\Models\Item;
use App\Services\CuratorAI;
use App\Services\PhotoManager;
use App\Support\ItemAccess;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;
use UnitEnum;

/**
 * Fotografar peças em sequência com a câmera do celular ou do computador.
 * Cada foto vira uma peça (ou várias fotos formam uma peça) e a IA preenche a ficha.
 */
class Camera extends Page
{
    protected string $view = 'filament.pages.camera';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCamera;

    protected static string|UnitEnum|null $navigationGroup = 'Acervo';

    protected static ?string $navigationLabel = 'Fotografar peças';

    protected static ?string $title = 'Fotografar peças';

    protected static ?string $slug = 'camera';

    protected static ?int $navigationSort = 3;

    public ?int $boxId = null;

    public string $mode = 'single';   // single = uma foto por peça | multi = várias fotos por peça

    public bool $ai = true;

    /** @var TemporaryUploadedFile|null */
    public $shot = null;

    public ?int $currentItem = null;

    /** @var array<int, int> peças criadas nesta sessão (mais recente primeiro) */
    public array $made = [];

    public ?string $batch = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->canEdit() ?? false;
    }

    public function mount(): void
    {
        $this->boxId = request()->integer('box') ?: null;
        $this->ai = app(CuratorAI::class)->enabled();
        $this->batch = 'camera-'.now()->format('YmdHi').'-'.Str::lower(Str::random(4));
    }

    public function boxes(): Collection
    {
        return ItemAccess::restrictBoxes(Box::query())->naturalOrder()->get(['id', 'name']);
    }

    /** Chamado pelo navegador depois de enviar a foto para "shot". */
    public function saveShot(): void
    {
        if (! $this->shot instanceof TemporaryUploadedFile) {
            return;
        }
        $box = $this->boxId ? ItemAccess::restrictBoxes(Box::query())->find($this->boxId) : null;

        try {
            $path = $this->shot->storeAs(config('acervo.photos_dir'), Str::uuid().'.jpg', config('acervo.disk'));
            $item = $this->mode === 'multi' && $this->currentItem ? Item::find($this->currentItem) : null;

            if (! $item) {
                $item = Item::create([
                    'title' => 'Peça fotografada '.(count($this->made) + 1),
                    'box_id' => $box?->id,
                    'batch' => $this->batch,
                    'ai_status' => $this->ai ? 'pending' : 'none',
                ]);
                array_unshift($this->made, $item->id);
                // na sessão de várias fotos, a IA roda quando a peça for fechada
                if ($this->ai && $this->mode === 'single') {
                    AnalyzeItem::dispatch($item->id)->delay(now()->addSeconds(5));
                }
            }

            $paths = $item->photos()->orderBy('position')->pluck('path')->push($path)->all();
            app(PhotoManager::class)->sync($item, $paths, log: false);
            $this->currentItem = $this->mode === 'multi' ? $item->id : null;
        } catch (Throwable $e) {
            Notification::make()->title('Não foi possível salvar a foto')->body($e->getMessage())->danger()->send();
        } finally {
            $this->shot = null;
        }
    }

    /** Fecha a peça atual (modo várias fotos) e começa a próxima. */
    public function nextItem(): void
    {
        if ($this->currentItem && $this->ai) {
            AnalyzeItem::dispatch($this->currentItem);
        }
        $this->currentItem = null;
    }

    public function updatedMode(): void
    {
        $this->nextItem();
    }

    public function finish(): void
    {
        $this->nextItem();
        if ($this->made) {
            ActivityLog::record('create', null, 'Fotografou '.count($this->made).' peça(s) com a câmera');
            Notification::make()->title(count($this->made).' peça(s) cadastrada(s)')
                ->body($this->ai ? 'A IA está preenchendo as fichas.' : null)->success()->send();
            $this->redirect(ItemResource::getUrl('index', ['filters' => ['batch' => ['value' => $this->batch]]]));
        }
    }

    public function recent(): Collection
    {
        return $this->made ? Item::whereIn('id', array_slice($this->made, 0, 12))->with('cover')->withCount('photos')->get()
            ->sortBy(fn ($i) => array_search($i->id, $this->made))->values() : collect();
    }

    public function itemUrl(Item $item): string
    {
        return ItemResource::getUrl('view', ['record' => $item]);
    }
}
