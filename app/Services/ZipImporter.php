<?php

namespace App\Services;

use App\Jobs\AnalyzeItem;
use App\Models\ActivityLog;
use App\Models\Box;
use App\Models\Item;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Cadastro em lote a partir de um .zip com pastas:
 *  - org "boxes": cada pasta de 1º nível vira uma caixa (criada se não existir);
 *    cada subpasta dentro dela vira uma peça; fotos soltas na caixa são agrupadas pelo modo escolhido.
 *  - org "box": tudo vai para a caixa escolhida; cada pasta vira uma peça.
 * Uma pasta "invólucro" (ex.: Acervo/Caixa 1/Peça/foto.jpg) é ignorada sozinha. __MACOSX e arquivos ocultos são pulados.
 */
class ZipImporter
{
    private const IMG = '/\.(jpe?g|png|webp|gif|bmp|heic|heif)$/i';

    /**
     * @return array{batch:string, items:int, boxes:array<string,int>, created:int}
     */
    public function import(string $localZip, int $acervoId, string $org, ?int $boxId, string $mode, int $per, bool $ai, array $tags, string $zipName): array
    {
        $zip = new ZipArchive;
        if ($zip->open($localZip) !== true) {
            throw new RuntimeException('Não foi possível abrir o .zip.');
        }

        // 1) lista as fotos
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if (str_ends_with($name, '/') || ! preg_match(self::IMG, $name) || preg_match('#(^|/)(__MACOSX|\.)#', $name)) {
                continue;
            }
            $entries[] = ['i' => $i, 'parts' => array_values(array_filter(explode('/', $name), 'strlen'))];
        }
        if (! $entries) {
            throw new RuntimeException('O .zip não tem fotos (JPG, PNG, WebP).');
        }
        usort($entries, fn ($a, $b) => strnatcasecmp(implode('/', $a['parts']), implode('/', $b['parts'])));

        // 2) tira um invólucro comum quando há caixa/peça/foto abaixo dele
        $all = array_column($entries, 'parts');
        $first = $all[0][0];
        if (collect($all)->every(fn ($p) => count($p) >= 4 && $p[0] === $first)) {
            foreach ($entries as &$e) {
                array_shift($e['parts']);
            }
            unset($e);
        }

        // 3) agrupa em [caixa => [peça => [entradas]]]
        $uploader = app(BatchUploader::class);
        $rootBox = pathinfo($zipName, PATHINFO_FILENAME);
        $plan = [];
        foreach ($entries as $e) {
            $p = $e['parts'];
            $file = array_pop($p);
            if ($org === 'boxes' && count($p) >= 1) {
                $box = array_shift($p);
            } else {
                $box = null;                                     // caixa escolhida (ou o nome do .zip)
            }
            if ($p) {
                $key = 'dir:'.($org === 'boxes' ? $p[0] : end($p)); // pasta = peça
            } else {
                $key = 'loose:'.$uploader->key($file);
            }
            $plan[$box ?? ''][$key][] = ['i' => $e['i'], 'file' => $file];
        }

        // modos "uma por peça" e "em sequência" para as fotos soltas
        foreach ($plan as $box => $groups) {
            $loose = collect($groups)->filter(fn ($v, $k) => str_starts_with($k, 'loose:'))->flatten(1)->values();
            if ($mode !== 'name' && $loose->isNotEmpty()) {
                $plan[$box] = collect($groups)->reject(fn ($v, $k) => str_starts_with($k, 'loose:'))->all();
                foreach ($loose->chunk($mode === 'one' ? 1 : max(2, $per)) as $n => $chunk) {
                    $plan[$box]['loose:'.$uploader->key($chunk->first()['file']).'#'.$n] = $chunk->values()->all();
                }
            }
        }

        // 4) caixas
        $boxIds = [];
        $created = 0;
        foreach (array_keys($plan) as $name) {
            if ($name === '') {
                $boxIds[''] = $boxId ?: $this->box($acervoId, $rootBox, $created)->id;
            } else {
                $boxIds[$name] = $this->box($acervoId, $name, $created)->id;
            }
        }

        // 5) peças e fotos
        $disk = Storage::disk(config('acervo.disk'));
        $photos = app(PhotoManager::class);
        $batch = 'zip-'.now()->format('YmdHi').'-'.Str::lower(Str::random(4));
        $count = 0;
        foreach ($plan as $boxName => $groups) {
            foreach ($groups as $key => $files) {
                $paths = [];
                foreach ($files as $f) {
                    $ext = strtolower(pathinfo($f['file'], PATHINFO_EXTENSION)) ?: 'jpg';
                    $path = config('acervo.photos_dir').'/'.Str::uuid().'.'.$ext;
                    $in = $zip->getStream($zip->getNameIndex($f['i']));
                    if (! $in) {
                        continue;
                    }
                    $disk->writeStream($path, $in);
                    is_resource($in) && fclose($in);
                    $paths[] = $path;
                }
                if (! $paths) {
                    continue;
                }
                $isDir = str_starts_with($key, 'dir:');
                $label = $isDir ? $this->folderTitle(substr($key, 4)) : $uploader->humanize(substr($key, 6));
                $item = new Item(['title' => $label ?: 'Sem título', 'box_id' => $boxIds[$boxName], 'batch' => $batch, 'ai_status' => $ai ? 'pending' : 'none']);
                $item->acervo_id = $acervoId;
                $item->save();
                $item->syncTagNames($tags);
                $photos->sync($item, $paths, log: false);
                if ($ai) {
                    // título vindo do nome da pasta é mantido e serve de pista para a IA
                    AnalyzeItem::dispatch($item->id, false, $isDir && $label ? "Nome dado pelo usuário: {$label}" : '');
                }
                $count++;
            }
        }
        $zip->close();

        ActivityLog::record('create', null, "Cadastrou {$count} peça(s) de {$zipName} em ".count($boxIds).' caixa(s)', acervoId: $acervoId);

        return ['batch' => $batch, 'items' => $count, 'boxes' => $boxIds, 'created' => $created];
    }

    private function box(int $acervoId, string $name, int &$created): Box
    {
        $name = trim($name);
        $box = Box::withoutGlobalScope('acervo')->where('acervo_id', $acervoId)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
        if (! $box) {
            $box = new Box(['name' => $name]);
            $box->acervo_id = $acervoId;
            $box->save();
            $created++;
        }

        return $box;
    }

    private function folderTitle(string $k): string
    {
        $t = trim(preg_replace('/\s+/', ' ', str_replace('_', ' ', $k)));

        return $t === '' || preg_match('/^\d+$/', $t) ? '' : Str::ucfirst($t);
    }
}
