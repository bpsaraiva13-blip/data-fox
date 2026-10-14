<?php

namespace App\Services;

use App\Models\Acervo;
use App\Models\ActivityLog;
use App\Models\Box;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Gera o "Backup completo (.zip)" de um acervo: data.json no mesmo formato da
 * versão web + as fotos. O arquivo volta para o sistema com "datafox:import".
 */
class BackupMaker
{
    /** @return string caminho do .zip no disco de backup */
    public function make(Acervo $acervo): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('A extensão PHP "zip" não está disponível.');
        }
        $photos = Storage::disk(config('acervo.disk'));
        $tmpDir = storage_path('app/tmp/backup-'.Str::random(8));
        File::ensureDirectoryExists($tmpDir.'/photos');

        $boxes = Box::withoutGlobalScope('acervo')->where('acervo_id', $acervo->id)->get();
        $items = Item::withoutGlobalScope('acervo')->where('acervo_id', $acervo->id)->with(['photos', 'tags'])->get();
        $people = User::whereIn('id', $items->pluck('created_by')->merge($items->pluck('updated_by'))->merge($boxes->pluck('created_by'))->filter()->unique())
            ->pluck('name', 'id')->mapWithKeys(fn ($n, $id) => ["u{$id}" => $n]);
        $u = fn ($id) => $id ? "u{$id}" : null;

        $data = [
            'app' => 'data-fox',
            'version' => 2,
            'acervo' => $acervo->name,
            'exportedAt' => now()->toIso8601String(),
            'people' => $people,
            'boxes' => $boxes->map(fn (Box $b) => [
                'id' => "b{$b->id}", 'name' => $b->name, 'location' => $b->location, 'color' => $b->color,
                'createdAt' => $b->created_at?->toIso8601String(), 'createdBy' => $u($b->created_by),
            ])->values(),
            'items' => [],
            'activity' => ActivityLog::withoutGlobalScope('acervo')->where('acervo_id', $acervo->id)->orderBy('created_at')->get()
                ->map(fn (ActivityLog $a) => [
                    'id' => "a{$a->id}", 'type' => $a->type, 'itemId' => $a->item_id ? "i{$a->item_id}" : null,
                    'label' => $a->label, 'by' => $u($a->user_id), 'at' => $a->created_at?->toIso8601String(),
                ])->values(),
        ];

        $zipPath = $tmpDir.'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($items as $i) {
            $ph = [];
            foreach ($i->photos->sortBy('position') as $p) {
                $id = "p{$p->id}";
                try {
                    $file = "{$tmpDir}/photos/{$id}.jpg";
                    File::put($file, $photos->get($p->path));
                    $zip->addFile($file, "photos/{$id}.jpg");
                    $ph[] = ['id' => $id, 'hash' => $p->hash, 'color' => $p->color, 'w' => $p->width, 'h' => $p->height];
                } catch (\Throwable) {
                    // foto ausente no disco: segue sem ela
                }
            }
            $data['items'][] = [
                'id' => "i{$i->id}", 'code' => $i->code, 'title' => $i->title, 'category' => $i->category, 'era' => $i->era,
                'origin' => $i->origin, 'material' => $i->material, 'dimensions' => $i->dimensions, 'condition' => $i->condition,
                'value' => $i->value, 'description' => $i->description, 'provenance' => $i->provenance,
                'acquiredAt' => $i->acquired_at?->toDateString(), 'notes' => $i->notes,
                'boxId' => $i->box_id ? "b{$i->box_id}" : '', 'tags' => $i->tags->pluck('name')->values(), 'photos' => $ph,
                'status' => $i->status, 'showcase' => (bool) $i->showcase, 'batch' => $i->batch, 'mergedFrom' => $i->merged_from,
                'ai' => $i->ai_analyzed_at ? ['keywords' => $i->ai_keywords, 'authenticity' => $i->ai_authenticity, 'at' => $i->ai_analyzed_at->toIso8601String()] : null,
                'createdAt' => $i->created_at?->toIso8601String(), 'createdBy' => $u($i->created_by),
                'updatedAt' => $i->updated_at?->toIso8601String(), 'updatedBy' => $u($i->updated_by),
            ];
        }
        $zip->addFromString('data.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        $zip->close();

        $disk = Storage::disk(config('acervo.backup.disk'));
        $dir = config('acervo.backup.dir').'/'.$acervo->slug;
        $dest = $dir.'/datafox-backup-'.$acervo->slug.'-'.now()->format('Y-m-d-His').'.zip';
        $in = fopen($zipPath, 'r');
        $disk->writeStream($dest, $in);
        is_resource($in) && fclose($in);
        File::deleteDirectory($tmpDir);
        File::delete($zipPath);

        // retenção
        collect($disk->files($dir))->filter(fn ($f) => str_ends_with($f, '.zip'))->sort()->reverse()
            ->slice(max(1, config('acervo.backup.keep')))->each(fn ($f) => $disk->delete($f));

        $acervo->forceFill(['last_backup_at' => now()])->saveQuietly();

        return $dest;
    }

    /** Backups guardados do acervo, mais recente primeiro. */
    public function list(Acervo $acervo): array
    {
        $disk = Storage::disk(config('acervo.backup.disk'));

        return collect($disk->files(config('acervo.backup.dir').'/'.$acervo->slug))
            ->filter(fn ($f) => str_ends_with($f, '.zip'))->sort()->reverse()->values()->all();
    }
}
