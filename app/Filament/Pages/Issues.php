<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Items\ItemResource;
use App\Jobs\AnalyzeItem;
use App\Models\Acervo;
use App\Models\DupIgnore;
use App\Models\Item;
use App\Models\ItemPhoto;
use App\Services\ItemMerger;
use App\Support\ItemAccess;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Pendências do acervo: possíveis duplicatas (assinatura visual quase igual),
 * peças sem foto, sem caixa, com título provisório ou com falha na IA.
 */
class Issues extends Page
{
    protected string $view = 'filament.pages.issues';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Acervo';

    protected static ?string $navigationLabel = 'Pendências';

    protected static ?string $title = 'Pendências';

    protected static ?string $slug = 'pendencias';

    protected static ?int $navigationSort = 6;

    /** Semelhança mínima para sugerir duplicata. */
    public const DUP_MIN = 0.9;

    #[Url]
    public string $tab = 'dup';

    public static function canAccess(): bool
    {
        return auth()->user()?->canEdit() ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            $n = static::checks()['failed']()->count() + static::checks()['nophoto']()->count();

            return $n ? (string) $n : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** @return array<string, \Closure(): Builder> */
    public static function checks(): array
    {
        $base = fn () => ItemAccess::restrict(Item::query());

        return [
            'nophoto' => fn () => $base()->doesntHave('photos'),
            'nobox' => fn () => $base()->whereNull('box_id'),
            'untitled' => fn () => $base()->where(fn ($q) => $q->where('title', 'Sem título')->orWhere('title', 'like', 'Peça fotografada %')->orWhere('title', 'ilike', 'img%')->orWhere('title', 'ilike', 'dsc%')),
            'nodesc' => fn () => $base()->where(fn ($q) => $q->whereNull('description')->orWhere('description', '')),
            'failed' => fn () => $base()->where('ai_status', 'failed'),
            'pending' => fn () => $base()->whereIn('ai_status', ['pending', 'running']),
        ];
    }

    public function tabs(): array
    {
        $labels = [
            'dup' => 'Possíveis duplicatas', 'nophoto' => 'Sem foto', 'nobox' => 'Sem caixa', 'untitled' => 'Título provisório',
            'nodesc' => 'Sem descrição', 'failed' => 'Falha na IA', 'pending' => 'IA em andamento',
        ];
        $out = [];
        foreach ($labels as $k => $l) {
            $out[$k] = ['label' => $l, 'count' => $k === 'dup' ? count($this->duplicates()) : static::checks()[$k]()->count()];
        }

        return $out;
    }

    public function list(): Collection
    {
        return static::checks()[$this->tab]()->with(['cover', 'box'])->latest()->limit(200)->get();
    }

    /**
     * Pares de peças com fotos quase iguais. Compara todas as fotos entre si
     * (XOR do dHash), ignora pares já descartados e guarda o resultado por 10 min.
     *
     * @return array<int, array{a:int, b:int, score:float}>
     */
    public function duplicates(): array
    {
        $acervo = Acervo::current();
        $stamp = ItemPhoto::whereHas('item')->max('updated_at').'|'.ItemPhoto::whereHas('item')->count().'|'.DupIgnore::count();
        $key = 'dups:'.$acervo?->id.':'.md5($stamp.'|'.json_encode(auth()->user()?->membership()?->allowedBoxes()));

        return Cache::remember($key, 600, function () {
            $photos = ItemPhoto::query()->whereNotNull('hash')
                ->whereHas('item', fn ($q) => ItemAccess::restrict($q))
                ->get(['item_id', 'hash', 'color'])
                ->map(fn ($p) => [$p->item_id, @hex2bin($p->hash) ?: '', $p->color ?? [0, 0, 0]])
                ->filter(fn ($p) => strlen($p[1]) === 8)->values()->all();

            $bits = [];
            for ($i = 0; $i < 256; $i++) {
                $bits[$i] = substr_count(decbin($i), '1');
            }
            $ignored = DupIgnore::get(['item_a', 'item_b'])->mapWithKeys(fn ($d) => [min($d->item_a, $d->item_b).'-'.max($d->item_a, $d->item_b) => true])->all();
            $maxHam = (int) floor((1 - 0.86) * 64);   // forma precisa ser bem parecida
            $best = [];
            $n = count($photos);

            for ($i = 0; $i < $n; $i++) {
                [$ia, $ha, $ca] = $photos[$i];
                for ($j = $i + 1; $j < $n; $j++) {
                    [$ib, $hb, $cb] = $photos[$j];
                    if ($ia === $ib) {
                        continue;
                    }
                    $x = $ha ^ $hb;
                    $d = 0;
                    for ($k = 0; $k < 8 && $d <= $maxHam; $k++) {
                        $d += $bits[ord($x[$k])];
                    }
                    if ($d > $maxHam) {
                        continue;
                    }
                    $col = 1 - sqrt(($ca[0] - $cb[0]) ** 2 + ($ca[1] - $cb[1]) ** 2 + ($ca[2] - $cb[2]) ** 2) / 441.7;
                    $score = 0.78 * (1 - $d / 64) + 0.22 * $col;
                    if ($score < self::DUP_MIN) {
                        continue;
                    }
                    $pair = min($ia, $ib).'-'.max($ia, $ib);
                    if (isset($ignored[$pair])) {
                        continue;
                    }
                    if (! isset($best[$pair]) || $best[$pair] < $score) {
                        $best[$pair] = $score;
                    }
                }
            }
            arsort($best);

            return collect($best)->take(150)->map(function ($score, $pair) {
                [$a, $b] = array_map('intval', explode('-', $pair));

                return ['a' => $a, 'b' => $b, 'score' => round($score, 3)];
            })->values()->all();
        });
    }

    /** @return Collection<int, Item> */
    public function dupItems(): Collection
    {
        $ids = collect($this->duplicates())->flatMap(fn ($d) => [$d['a'], $d['b']])->unique()->all();

        return Item::whereIn('id', $ids)->with(['cover', 'box'])->get()->keyBy('id');
    }

    public function ignore(int $a, int $b): void
    {
        DupIgnore::firstOrCreate(['item_a' => min($a, $b), 'item_b' => max($a, $b)]);
        Notification::make()->title('Par marcado como peças diferentes')->success()->send();
    }

    /** Une a segunda peça na primeira (fotos, tags e histórico). */
    public function merge(int $keep, int $other): void
    {
        $main = ItemAccess::restrict(Item::query())->find($keep);
        $second = ItemAccess::restrict(Item::query())->find($other);
        if (! $main || ! $second || ! auth()->user()->canEdit()) {
            return;
        }
        app(ItemMerger::class)->merge($main, collect([$second]));
        Notification::make()->title("Unidas em {$main->code}")->success()->send();
    }

    public function retryFailed(): void
    {
        $n = 0;
        foreach (static::checks()['failed']()->pluck('id') as $id) {
            Item::whereKey($id)->update(['ai_status' => 'pending', 'ai_error' => null]);
            AnalyzeItem::dispatch($id);
            $n++;
        }
        Notification::make()->title("{$n} peça(s) enviadas de novo para a IA")->success()->send();
    }

    public function itemUrl(Item $item): string
    {
        return ItemResource::getUrl('view', ['record' => $item]);
    }

    public function editUrl(Item $item): string
    {
        return ItemResource::getUrl('edit', ['record' => $item]);
    }
}
