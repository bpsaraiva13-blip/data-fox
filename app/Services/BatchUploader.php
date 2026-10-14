<?php

namespace App\Services;

use App\Jobs\AnalyzeItem;
use App\Models\ActivityLog;
use App\Models\Box;
use App\Models\Item;
use Illuminate\Support\Str;

/**
 * Cadastro em lote de uma caixa: agrupa as fotos enviadas em peças,
 * cria as fichas na hora e manda a análise da IA para a fila.
 */
class BatchUploader
{
    private const GENERIC = '/^(img|dsc|dscn|pxl|photo|foto|image|imagem|whatsapp|screenshot|captura|p\d{3,}|\d{6,})/i';

    /**
     * @param  array<string,string>  $files  caminho salvo => nome original
     * @return array{batch:string, items:int}
     */
    public function run(Box $box, array $files, string $mode, int $per, bool $ai, array $tags): array
    {
        uasort($files, fn ($a, $b) => strnatcasecmp($a, $b));
        $groups = $this->group($files, $mode, $per);
        $batch = 'lote-'.now()->format('YmdHi').'-'.Str::lower(Str::random(4));
        $photos = app(PhotoManager::class);

        foreach ($groups as $key => $paths) {
            $item = Item::create([
                'title' => $this->humanize($key) ?: 'Sem título',
                'box_id' => $box->id,
                'batch' => $batch,
                'ai_status' => $ai ? 'pending' : 'none',
            ]);
            $item->syncTagNames($tags);
            $photos->sync($item, $paths);
            if ($ai) {
                AnalyzeItem::dispatch($item->id);
            }
        }

        ActivityLog::record('create', null, 'Cadastrou um lote de '.count($groups)." peça(s) na caixa {$box->name}");

        return ['batch' => $batch, 'items' => count($groups)];
    }

    /** @return array<string, string[]> chave do grupo => caminhos */
    public function group(array $files, string $mode, int $per = 2): array
    {
        $out = [];
        $i = 0;
        foreach ($files as $path => $name) {
            $base = basename(str_replace('\\', '/', $name));
            $key = match ($mode) {
                'one' => $this->key($base).'#'.$i,
                'seq' => $this->key(array_values($files)[intdiv($i, $per) * $per]).'#'.intdiv($i, $per),
                'folder' => str_contains($name, '/') ? basename(dirname($name)) : $this->key($base),
                default => $this->key($base),
            };
            $out[$key][] = $path;
            $i++;
        }

        return $out;
    }

    public function key(string $name): string
    {
        $k = Str::lower(trim(preg_replace('/\.[a-z0-9]+$/i', '', basename($name))));
        for ($n = 0; $n < 3; $n++) {
            $k = preg_replace('/[\s_.-]*(\(\d+\)|\d{1,3}|frente|verso|costas|detalhe\d*|marca\d*|fundo|lateral\d*|topo|base|assinatura|selo|zoom)$/i', '', $k);
        }

        return $k !== '' ? $k : $name;
    }

    public function humanize(string $key): string
    {
        $k = preg_replace('/#\d+$/', '', $key);
        if (preg_match(self::GENERIC, $k)) {
            return '';
        }
        $t = trim(preg_replace('/\s+/', ' ', preg_replace('/[_-]+/', ' ', $k)));

        return $t !== '' ? Str::ucfirst($t) : '';
    }
}
