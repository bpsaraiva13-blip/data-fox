<?php

namespace App\Console\Commands;

use App\Models\Acervo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Backup diário da interface: um JSON compactado por acervo (todas as coleções) no bucket,
 * em backups/interface/<acervo>/. Guarda os últimos ACERVO_BACKUP_KEEP (padrão 14).
 * As fotos já ficam no bucket; o JSON aponta para elas pelos ids.
 */
class ExportDocs extends Command
{
    protected $signature = 'datafox:export-docs';

    protected $description = 'Backup diário (JSON) dos documentos de cada acervo no bucket';

    public function handle(): int
    {
        $disk = Storage::disk(config('acervo.backup.disk'));
        $keep = max(1, (int) config('acervo.backup.keep', 14));
        foreach (Acervo::all() as $a) {
            $out = ['app' => 'data-fox', 'format' => 'laravel-1', 'exportedAt' => now()->toIso8601String(), 'acervo' => ['id' => $a->id, 'name' => $a->name, 'page' => $a->page]];
            foreach (DB::table('docs')->where('acervo_id', $a->id)->whereNotNull('data')->orderBy('col')->cursor() as $r) {
                $out['cols'][$r->col][] = ['id' => $r->doc_id] + (json_decode($r->data, true) ?: []);
            }
            $dir = 'backups/interface/'.$a->id;
            $disk->put($dir.'/'.now()->format('Y-m-d_His').'.json.gz', gzencode(json_encode($out), 6));
            $files = collect($disk->files($dir))->sort()->values();
            $files->slice(0, max(0, $files->count() - $keep))->each(fn ($f) => $disk->delete($f));
            $this->line("{$a->name}: ".collect($out['cols'] ?? [])->sum(fn ($c) => count($c)).' documento(s)');
        }

        return self::SUCCESS;
    }
}
