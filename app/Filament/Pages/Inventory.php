<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Items\ItemResource;
use App\Models\ActivityLog;
use App\Models\Box;
use App\Models\Inventory as InventoryModel;
use App\Models\Item;
use App\Support\ItemAccess;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Conferência física de uma caixa: lê as etiquetas (leitor USB, câmera do celular
 * abrindo a ficha, ou digitando o tombo) e mostra o que falta e o que sobrou.
 */
class Inventory extends Page
{
    protected string $view = 'filament.pages.inventory';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Acervo';

    protected static ?string $navigationLabel = 'Conferência';

    protected static ?string $title = 'Conferência de caixa';

    protected static ?string $slug = 'conferencia';

    protected static ?int $navigationSort = 5;

    public ?int $boxId = null;

    public string $code = '';

    public ?int $flash = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->canEdit() ?? false;
    }

    public function mount(): void
    {
        $this->boxId = request()->integer('box') ?: $this->current()?->box_id;
    }

    public function current(): ?InventoryModel
    {
        return InventoryModel::openFor(auth()->user());
    }

    /** @return Collection<int, Box> */
    public function boxes(): Collection
    {
        return ItemAccess::restrictBoxes(Box::query())->naturalOrder()->withCount('items')->get();
    }

    public function start(): void
    {
        $box = $this->boxId ? ItemAccess::restrictBoxes(Box::query())->find($this->boxId) : null;
        if (! $box) {
            Notification::make()->title('Escolha uma caixa')->warning()->send();

            return;
        }
        $this->current()?->update(['status' => 'closed', 'finished_at' => now()]);
        InventoryModel::create(['box_id' => $box->id, 'user_id' => auth()->id(), 'status' => 'open', 'found' => [], 'extra' => []]);
        Notification::make()->title("Conferência de {$box->name} iniciada")
            ->body('Leia as etiquetas com o leitor, digite o tombo ou abra as fichas pelo QR code no celular.')->success()->send();
    }

    protected function visible(): \Illuminate\Database\Eloquent\Builder
    {
        return ItemAccess::restrict(Item::query());
    }

    /** Peças que deveriam estar na caixa. */
    public function expected(): Collection
    {
        $inv = $this->current();

        return $inv ? Item::where('box_id', $inv->box_id)->with('cover')->orderBy('code')->get() : collect();
    }

    public function extras(): Collection
    {
        $inv = $this->current();

        return $inv && $inv->extra ? Item::whereIn('id', $inv->extra)->with(['cover', 'box'])->get() : collect();
    }

    /** Leitura de etiqueta: aceita o link do QR code, o Nº de tombo ou parte dele. */
    public function scanCode(): void
    {
        $inv = $this->current();
        $raw = trim($this->code);
        $this->code = '';
        if (! $inv || $raw === '') {
            return;
        }

        $item = null;
        if (preg_match('~/items/(\d+)~', $raw, $m)) {
            $item = $this->visible()->find((int) $m[1]);
        }
        $item ??= $this->visible()->whereRaw('upper(code) = ?', [mb_strtoupper($raw)])->first()
            ?? $this->visible()->where('code', 'ilike', '%'.$raw)->orderBy('code')->first();

        if (! $item) {
            Notification::make()->title('Etiqueta não reconhecida')->body($raw)->danger()->send();
            $this->dispatch('df-beep', ok: false);

            return;
        }
        $this->mark($inv, $item);
    }

    public function toggle(int $id): void
    {
        $inv = $this->current();
        if (! $inv) {
            return;
        }
        if (in_array($id, $inv->found ?? [], true)) {
            $inv->found = array_values(array_diff($inv->found, [$id]));
            $inv->save();

            return;
        }
        if ($item = $this->visible()->find($id)) {
            $this->mark($inv, $item);
        }
    }

    protected function mark(InventoryModel $inv, Item $item): void
    {
        $here = $inv->scan($item);
        $this->flash = $item->id;
        $this->dispatch('df-beep', ok: $here);
        if (! $here) {
            Notification::make()->title("{$item->code} é de outra caixa")
                ->body(($item->box?->name ?? 'Sem caixa').' · '.$item->title)->warning()->send();
        }
    }

    /** Peças de outras caixas encontradas aqui passam para esta caixa. */
    public function moveExtras(): void
    {
        $inv = $this->current();
        if (! $inv || ! $inv->extra) {
            return;
        }
        $n = 0;
        foreach (Item::whereIn('id', $inv->extra)->get() as $item) {
            $item->update(['box_id' => $inv->box_id]);   // o observer registra a mudança
            $n++;
        }
        $inv->update(['found' => array_values(array_unique([...($inv->found ?? []), ...$inv->extra])), 'extra' => []]);
        Notification::make()->title("{$n} peça(s) movida(s) para {$inv->box?->name}")->success()->send();
    }

    public function finish(): void
    {
        $inv = $this->current();
        if (! $inv) {
            return;
        }
        $expected = $this->expected()->pluck('id')->all();
        $found = array_values(array_intersect($expected, $inv->found ?? []));
        $missing = array_values(array_diff($expected, $found));
        $inv->update([
            'status' => 'closed',
            'finished_at' => now(),
            'summary' => ['total' => count($expected), 'found' => count($found), 'missing' => $missing, 'extra' => $inv->extra ?? []],
        ]);
        ActivityLog::record('update', null, sprintf('Conferiu %s: %d de %d encontradas%s',
            $inv->box?->name ?? 'caixa', count($found), count($expected), $missing ? ', '.count($missing).' faltando' : ''));
        Notification::make()->title('Conferência concluída')
            ->body(count($missing) ? count($missing).' peça(s) não encontrada(s).' : 'Todas as peças estão na caixa.')
            ->{count($missing) ? 'warning' : 'success'}()->send();
    }

    public function cancel(): void
    {
        $this->current()?->delete();
    }

    public function history(): Collection
    {
        return InventoryModel::where('status', 'closed')->with(['box', 'user'])->latest('finished_at')->limit(12)->get();
    }

    public function itemUrl(Item $item): string
    {
        return ItemResource::getUrl('view', ['record' => $item]);
    }

}
