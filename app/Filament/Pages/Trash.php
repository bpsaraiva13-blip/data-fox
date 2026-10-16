<?php

namespace App\Filament\Pages;

use App\Models\ActivityLog;
use App\Models\Box;
use App\Models\Collector;
use App\Models\Item;
use App\Models\ItemPhoto;
use App\Support\ItemAccess;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Lixeira: peças, fotos, caixas e colecionadores excluídos ficam 30 dias aqui.
 * O agendador (model:prune) apaga de vez, com os arquivos, depois desse prazo.
 */
class Trash extends Page
{
    protected string $view = 'filament.pages.trash';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrash;

    protected static string|UnitEnum|null $navigationGroup = 'Acervo';

    protected static ?string $navigationLabel = 'Lixeira';

    protected static ?string $title = 'Lixeira';

    protected static ?string $slug = 'lixeira';

    protected static ?int $navigationSort = 20;

    public const DAYS = 30;

    #[Url]
    public string $tab = 'items';

    public static function canAccess(): bool
    {
        return auth()->user()?->canEdit() ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            $n = static::query('items')->count() + static::query('boxes')->count() + static::query('photos')->count() + static::query('collectors')->count();

            return $n ? (string) $n : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function query(string $tab)
    {
        return match ($tab) {
            'boxes' => Box::onlyTrashed(),
            'photos' => ItemPhoto::onlyTrashed()->whereHas('item', fn ($query) => ItemAccess::restrict($query)->withTrashed()),
            'collectors' => Collector::onlyTrashed(),
            default => ItemAccess::restrict(Item::onlyTrashed()),
        };
    }

    public function tabs(): array
    {
        return collect(['items' => 'Peças', 'photos' => 'Fotos', 'boxes' => 'Caixas', 'collectors' => 'Colecionadores'])
            ->map(fn ($l, $k) => ['label' => $l, 'count' => static::query($k)->count()])->all();
    }

    /** @return Collection<int, array{id:int, title:string, sub:string, thumb:?string, deleted:\Carbon\Carbon, left:int}> */
    public function rows(): Collection
    {
        $query = static::query($this->tab)->latest('deleted_at')->limit(300);
        $left = fn ($d) => max(0, self::DAYS - (int) floor($d->diffInDays(now())));

        return match ($this->tab) {
            'boxes' => $query->get()->map(fn (Box $b) => ['id' => $b->id, 'title' => $b->name, 'sub' => count($b->trashed_item_ids ?? []).' peça(s) estavam nela', 'thumb' => null, 'deleted' => $b->deleted_at, 'left' => $left($b->deleted_at)]),
            'photos' => $query->with('item')->get()->map(fn (ItemPhoto $p) => ['id' => $p->id, 'title' => 'Foto de '.($p->item?->code ?? '?'), 'sub' => (string) $p->item?->title, 'thumb' => $p->thumbUrl(), 'deleted' => $p->deleted_at, 'left' => $left($p->deleted_at)]),
            'collectors' => $query->get()->map(fn (Collector $c) => ['id' => $c->id, 'title' => $c->name, 'sub' => collect([$c->city, $c->phone])->filter()->implode(' · '), 'thumb' => null, 'deleted' => $c->deleted_at, 'left' => $left($c->deleted_at)]),
            default => $query->with(['box', 'photos' => fn ($p) => $p->withTrashed()])->get()->map(fn (Item $i) => ['id' => $i->id, 'title' => "{$i->code} · {$i->title}", 'sub' => $i->box?->name ?? 'Sem caixa', 'thumb' => $i->photos->first()?->thumbUrl(), 'deleted' => $i->deleted_at, 'left' => $left($i->deleted_at)]),
        };
    }

    public function restore(int $id): void
    {
        $label = '';
        switch ($this->tab) {
            case 'boxes':
                $b = Box::onlyTrashed()->findOrFail($id);
                if (Box::where('name', $b->name)->exists()) {
                    $b->name .= ' (restaurada)';
                }
                $n = $b->restoreWithItems();
                $label = "Restaurou a caixa {$b->name}".($n ? " com {$n} peça(s)" : '');
                break;
            case 'photos':
                $p = ItemPhoto::onlyTrashed()->findOrFail($id);
                if ($p->item?->trashed()) {
                    Notification::make()->warning()->title('Restaure a peça antes')->body('A peça desta foto também está na lixeira.')->send();

                    return;
                }
                $p->restoreToItem();
                $label = "{$p->item?->code} — foto restaurada da lixeira";
                break;
            case 'collectors':
                $c = Collector::onlyTrashed()->findOrFail($id);
                $c->restore();
                $label = "Restaurou o colecionador {$c->name}";
                break;
            default:
                $i = static::query('items')->findOrFail($id);
                if ($i->box_id && ! Box::whereKey($i->box_id)->exists()) {
                    $i->box_id = null;
                }
                $i->restore();
                $label = "Restaurou da lixeira {$i->code} · {$i->title}";
        }
        ActivityLog::record('create', $this->tab === 'items' ? $id : null, $label);
        Notification::make()->success()->title('Restaurado')->body($label)->send();
    }

    public function forceDelete(int $id): void
    {
        $m = static::query($this->tab)->findOrFail($id);
        if (method_exists($m, 'pruning')) {
            (fn () => $this->pruning())->call($m);   // apaga os arquivos junto
        }
        $m->forceDelete();
        Notification::make()->success()->title('Apagado de vez')->send();
    }

    public function emptyTrash(): void
    {
        if (! auth()->user()->isAcervoAdmin()) {
            Notification::make()->danger()->title('Só administradores do acervo podem esvaziar a lixeira')->send();

            return;
        }
        $n = 0;
        foreach (['photos', 'items', 'boxes', 'collectors'] as $tab) {
            foreach (static::query($tab)->get() as $m) {
                if (method_exists($m, 'pruning')) {
                    (fn () => $this->pruning())->call($m);
                }
                $m->forceDelete();
                $n++;
            }
        }
        ActivityLog::record('delete', null, "Esvaziou a lixeira ({$n})");
        Notification::make()->success()->title("Lixeira esvaziada ({$n})")->send();
    }
}
