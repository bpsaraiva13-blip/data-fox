<?php

namespace App\Jobs;

use App\Models\Acervo;
use App\Models\Box;
use App\Models\Item;
use App\Models\User;
use App\Services\ItemTransfer;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Throwable;

/** Envio grande entre acervos em segundo plano; o aviso chega no sininho. */
class TransferItems implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public array $itemIds,
        public int $targetId,
        public string $mode,
        public int|string|null $box,
        public ?int $sourceBoxId,
        public int $userId,
    ) {}

    public function handle(ItemTransfer $transfer): void
    {
        $user = User::findOrFail($this->userId);
        Auth::setUser($user);
        $target = Acervo::findOrFail($this->targetId);
        $items = Item::withoutGlobalScope('acervo')->whereIn('id', $this->itemIds)->get();
        $box = $this->sourceBoxId ? Box::withoutGlobalScope('acervo')->find($this->sourceBoxId) : null;

        try {
            $r = $transfer->send($items, $target, $this->mode, $this->box, $box);
            Notification::make()->success()
                ->title("{$r['count']} peça(s) ".($this->mode === 'move' ? 'enviada(s)' : 'copiada(s)')." para {$target->name}")
                ->body($r['renumbered'] ? "{$r['renumbered']} receberam Nº de tombo novo." : null)
                ->sendToDatabase($user);
        } catch (Throwable $e) {
            Notification::make()->danger()->title('O envio entre acervos falhou')->body($e->getMessage())->sendToDatabase($user);
            throw $e;
        }
    }
}
