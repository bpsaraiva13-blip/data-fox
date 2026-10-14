<?php

namespace App\Jobs;

use App\Models\Item;
use App\Services\CuratorAI;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Análise da peça pela IA em segundo plano (lote, reanálise após unir).
 * Tenta de novo com espera crescente se a API estiver ocupada.
 */
class AnalyzeItem implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 150;

    public function __construct(public int $itemId, public bool $overwrite = false, public string $context = '') {}

    public function backoff(): array
    {
        return [20, 60, 180];
    }

    public function handle(CuratorAI $ai): void
    {
        $item = Item::find($this->itemId);
        if (! $item || ! $ai->enabled()) {
            $item?->update(['ai_status' => 'none']);

            return;
        }

        $item->forceFill(['ai_status' => 'running'])->saveQuietly();
        $ai->analyzeItem($item, $this->overwrite, $this->context);
    }

    public function failed(?Throwable $e): void
    {
        Item::whereKey($this->itemId)->update(['ai_status' => 'failed', 'ai_error' => str($e?->getMessage())->limit(250)]);
    }
}
