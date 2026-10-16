<?php

namespace App\Console\Commands;

use App\Models\Acervo;
use App\Models\User;
use App\Support\PageStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Traz um backup .zip da versão web (botão "Backup completo") para a interface do Laravel:
 * caixas, peças, histórico, movimentações, colecionadores e coleções com os MESMOS ids,
 * e as fotos com os mesmos ids (as miniaturas são geradas aqui). Pode ser repetido:
 * cada documento é regravado com o conteúdo do backup.
 *
 *   php artisan datafox:import-web s3:imports/datafox-backup-acervo-2026-10-16.zip --user=voce@email.com --acervo="Acervo principal"
 */
class ImportWebBackup extends Command
{
    protected $signature = 'datafox:import-web
        {file : Caminho do .zip (local ou "disco:caminho", ex.: s3:imports/backup.zip)}
        {--user= : E-mail de quem fica como dono se o acervo for criado}
        {--acervo= : Nome, slug ou id do acervo de destino (criado se não existir)}
        {--dry-run : Só mostra o que seria importado}';

    protected $description = 'Importa um backup .zip da versão web do Data Fox para a interface do Laravel';

    private const COLS = ['boxes', 'items', 'activity', 'movements', 'collectors', 'collections'];

    public function handle(): int
    {
        $zipPath = $this->localZip($this->argument('file'));
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            $this->error('Não foi possível abrir o .zip.');

            return self::FAILURE;
        }
        $data = json_decode((string) $zip->getFromName('data.json'), true);
        if (($data['app'] ?? null) !== 'data-fox') {
            $this->error('Este arquivo não é um backup do Data Fox (falta data.json).');

            return self::FAILURE;
        }
        foreach (self::COLS as $c) {
            $this->line(sprintf('  %-12s %d', $c, count($data[$c] ?? [])));
        }
        $photoIds = collect($data['items'] ?? [])->flatMap(fn ($i) => $i['photos'] ?? [])->pluck('id')->filter()->unique();
        $this->line('  fotos        '.$photoIds->count());
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $owner = $this->option('user') ? User::where('email', $this->option('user'))->first() : null;
        $want = (string) $this->option('acervo');
        $acervo = $want !== '' ? Acervo::where('slug', $want)->orWhere('name', $want)->when(is_numeric($want), fn ($q) => $q->orWhere('id', (int) $want))->first() : null;
        if (! $acervo) {
            if (! $owner) {
                $this->error('Informe --user=email (dono do acervo novo) ou um --acervo existente.');

                return self::FAILURE;
            }
            $acervo = new Acervo(['name' => $want ?: 'Acervo principal']);
            $acervo->owner_id = $owner->id;
            $acervo->save();
        }
        $aid = $acervo->id;
        $this->info("Acervo de destino: {$acervo->name} (id {$aid})");

        // nomes de quem aparece no histórico (ids da versão web)
        $page = (array) ($acervo->page ?? []);
        $page['people'] = array_merge((array) ($page['people'] ?? []), array_filter((array) ($data['people'] ?? [])));
        $acervo->page = $page;
        $acervo->save();

        // documentos com os mesmos ids
        $n = 0;
        foreach (self::COLS as $col) {
            foreach ($data[$col] ?? [] as $doc) {
                $id = (string) ($doc['id'] ?? '');
                if (! preg_match('/^[A-Za-z0-9_.\-]{1,80}$/', $id)) {
                    continue;
                }
                unset($doc['id'], $doc['_home'], $doc['_box']);
                DB::table('docs')->updateOrInsert(
                    ['acervo_id' => $aid, 'col' => $col, 'doc_id' => $id],
                    ['data' => json_encode($doc), 'rev' => PageStore::nextRev(), 'updated_at' => now(), 'created_at' => now()],
                );
                $n++;
            }
        }
        $this->info("{$n} documento(s) gravados.");

        // fotos (mesmo id) e miniaturas geradas aqui
        $disk = Storage::disk(config('acervo.disk'));
        $put = function (string $id, string $bin, string $mime) use ($disk, $aid) {
            if (DB::table('assets')->where('id', $id)->exists()) {
                return;
            }
            $path = 'acervo/assets/'.substr(md5($id), 0, 2).'/'.$id;
            $disk->put($path, $bin, ['ContentType' => $mime]);
            DB::table('assets')->insert(['id' => $id, 'acervo_id' => $aid, 'path' => $path, 'mime' => $mime, 'size' => strlen($bin), 'created_at' => now(), 'updated_at' => now()]);
        };
        $bar = $this->output->createProgressBar($photoIds->count());
        $missing = 0;
        foreach ($data['items'] ?? [] as $it) {
            foreach ($it['photos'] ?? [] as $p) {
                $bar->advance();
                $bin = isset($p['id']) ? $zip->getFromName("photos/{$p['id']}.jpg") : false;
                if ($bin === false) {
                    $missing++;

                    continue;
                }
                $put((string) $p['id'], $bin, 'image/jpeg');
                if (! empty($p['thumb']) && $p['thumb'] !== $p['id']) {
                    $put((string) $p['thumb'], $this->thumb($bin) ?? $bin, 'image/jpeg');
                }
            }
        }
        $bar->finish();
        $this->newLine();
        $zip->close();
        $this->info('Concluído.'.($missing ? " {$missing} foto(s) não estavam no .zip (backup sem fotos?)." : ''));

        return self::SUCCESS;
    }

    private function thumb(string $bin): ?string
    {
        $im = @imagecreatefromstring($bin);
        if (! $im) {
            return null;
        }
        $w = imagesx($im);
        $h = imagesy($im);
        $s = min(1, 520 / max($w, $h));
        $t = imagescale($im, max(1, (int) round($w * $s)), max(1, (int) round($h * $s)));
        ob_start();
        imagejpeg($t, null, 80);

        return ob_get_clean() ?: null;
    }

    private function localZip(string $file): string
    {
        if (preg_match('/^([a-z0-9_-]+):(?!\/\/)(.+)$/i', $file, $m) && array_key_exists($m[1], config('filesystems.disks'))) {
            $tmp = tempnam(sys_get_temp_dir(), 'dfw');
            $in = Storage::disk($m[1])->readStream($m[2]);
            $out = fopen($tmp, 'w');
            stream_copy_to_stream($in, $out);
            fclose($out);

            return $tmp;
        }

        return realpath($file) ?: base_path($file);
    }
}
