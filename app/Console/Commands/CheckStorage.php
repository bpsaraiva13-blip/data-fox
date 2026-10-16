<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/** Confere o disco das fotos (bucket): grava, lê, gera link temporário e apaga um arquivo de teste. */
class CheckStorage extends Command
{
    protected $signature = 'datafox:check-storage';

    protected $description = 'Testa o bucket das fotos e mostra o erro exato, se houver';

    public function handle(): int
    {
        $name = config('acervo.disk');
        $cfg = (array) config("filesystems.disks.{$name}");
        $this->line("Disco das fotos: {$name} (FILESYSTEM_DISK=".env('FILESYSTEM_DISK').', ACERVO_DISK='.(env('ACERVO_DISK') ?: 'vazio').')');
        $this->line('Driver: '.($cfg['driver'] ?? '—').' · bucket: '.($cfg['bucket'] ?? '—').' · região: '.($cfg['region'] ?? '—').' · endpoint: '.($cfg['endpoint'] ?? '—'));
        $this->line('Chave configurada: '.(! empty($cfg['key']) ? 'sim' : 'NÃO').' · segredo: '.(! empty($cfg['secret']) ? 'sim' : 'NÃO'));
        $this->line('Discos disponíveis: '.implode(', ', array_keys((array) config('filesystems.disks'))));

        $path = 'acervo/teste-'.Str::random(6).'.txt';
        $steps = [
            'gravar' => fn ($d) => $d->put($path, 'ok', ['ContentType' => 'text/plain']) ?: throw new \RuntimeException('put() devolveu false'),
            'ler' => fn ($d) => $d->get($path) === 'ok' ?: throw new \RuntimeException('conteúdo diferente'),
            'link temporário' => fn ($d) => ($cfg['driver'] ?? '') === 's3' ? $this->line('   '.substr($d->temporaryUrl($path, now()->addMinutes(5)), 0, 90).'…') : $this->line('   (disco local: sem link temporário)'),
            'apagar' => fn ($d) => $d->delete($path),
        ];
        $disk = Storage::build(array_merge($cfg, ['throw' => true]));
        $fail = false;
        foreach ($steps as $label => $fn) {
            try {
                $fn($disk);
                $this->info("✓ {$label}");
            } catch (Throwable $e) {
                $fail = true;
                $this->error("✗ {$label}: ".get_class($e).': '.Str::limit($e->getMessage(), 400));
                if ($e->getPrevious()) {
                    $this->error('   causa: '.Str::limit($e->getPrevious()->getMessage(), 400));
                }
                break;
            }
        }

        return $fail ? self::FAILURE : self::SUCCESS;
    }
}
