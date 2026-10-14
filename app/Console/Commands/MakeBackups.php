<?php

namespace App\Console\Commands;

use App\Models\Acervo;
use App\Services\BackupMaker;
use Illuminate\Console\Command;
use Throwable;

/**
 *   php artisan datafox:backup              (todos os acervos)
 *   php artisan datafox:backup --acervo=slug
 */
class MakeBackups extends Command
{
    protected $signature = 'datafox:backup {--acervo= : Slug de um acervo específico}';

    protected $description = 'Gera o backup .zip (data.json + fotos) de cada acervo e aplica a retenção';

    public function handle(BackupMaker $maker): int
    {
        $acervos = Acervo::query()->when($this->option('acervo'), fn ($q, $s) => $q->where('slug', $s))->get();
        $failed = 0;
        foreach ($acervos as $a) {
            try {
                $this->info("{$a->name}: ".$maker->make($a));
            } catch (Throwable $e) {
                $failed++;
                $this->error("{$a->name}: {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
