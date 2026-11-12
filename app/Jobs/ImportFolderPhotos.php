<?php

namespace App\Jobs;

use App\Support\FolderImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/** Um lote de fotos da importação de pastas (datafox:import-folder). Cada foto vira uma peça. */
class ImportFolderPhotos implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 3;

    public int $backoff = 60;

    /** @param  array<int,array{path:string,box:?string,code:string}>  $files */
    public function __construct(public int $acervoId, public string $prefix, public array $files, public string $batch, public ?string $ownerUid) {}

    public function handle(): void
    {
        @ini_set('memory_limit', '1024M');
        foreach ($this->files as $f) {
            try {
                FolderImport::importPhoto($this->acervoId, $this->prefix, $f['path'], $f['box'], $f['code'], $this->batch, $this->ownerUid);
            } catch (\Throwable $e) {
                // uma foto com problema não derruba o lote; aparece no log e no relatório (--report)
                Log::warning('datafox:import-folder', ['path' => $f['path'], 'erro' => $e->getMessage()]);
            }
            gc_collect_cycles();
        }
    }
}
