<?php

namespace App\Console\Commands;

use App\Models\Acervo;
use App\Models\ActivityLog;
use App\Models\Box;
use App\Models\Collector;
use App\Models\ItemCollection;
use App\Models\Movement;
use App\Models\Item;
use App\Models\User;
use App\Services\PhotoManager;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

/**
 * Importa o "Backup completo (.zip)" gerado pela versão anterior do Data Fox.
 * Pode rodar várias vezes: o que já foi importado é ignorado (import_ref).
 *
 *   php artisan datafox:import storage/app/datafox-backup-acervo.zip --user=voce@email.com
 *   php artisan datafox:import s3:imports/datafox-backup-acervo.zip --user=voce@email.com
 */
class ImportBackup extends Command
{
    protected $signature = 'datafox:import
        {file : Caminho do .zip (local ou "disco:caminho", ex.: s3:imports/backup.zip)}
        {--user= : E-mail do usuário que fica como autor quando o nome original não corresponde a ninguém}
        {--acervo= : Nome ou slug do acervo de destino (é criado se não existir; padrão: o primeiro acervo)}
        {--dry-run : Só mostra o que seria importado}';

    protected $description = 'Importa peças, caixas, tags, histórico e fotos de um backup .zip do Data Fox';

    public function handle(PhotoManager $photos): int
    {
        if (! class_exists(ZipArchive::class)) {
            $this->error('A extensão PHP "zip" não está disponível neste servidor.');

            return self::FAILURE;
        }
        $zipPath = $this->localZip($this->argument('file'));
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            $this->error('Não foi possível abrir o arquivo .zip.');

            return self::FAILURE;
        }

        $data = json_decode((string) $zip->getFromName('data.json'), true);
        if (($data['app'] ?? null) !== 'data-fox') {
            $this->error('O arquivo não parece ser um backup do Data Fox (falta data.json).');

            return self::FAILURE;
        }

        $fallback = $this->option('user') ? User::where('email', $this->option('user'))->first() : User::where('role', 'admin')->first();
        if (! $fallback) {
            $this->error('Crie um usuário administrador antes (php artisan datafox:admin) ou informe --user=email.');

            return self::FAILURE;
        }

        $acervo = $this->acervo($fallback, $data);
        $aid = $acervo->id;
        $this->info("Acervo de destino: {$acervo->name} ({$acervo->slug})");

        $people = $data['people'] ?? [];
        $userFor = function (?string $oldId) use ($people, $fallback) {
            $name = $people[$oldId] ?? null;
            $match = $name ? User::where('name', $name)->first() : null;

            return [$match?->id ?? $fallback->id, $name];
        };

        $items = collect($data['items'] ?? []);
        $this->info(sprintf('Backup de %s: %d caixas, %d peças, %d fotos, %d registros de atividade.',
            Carbon::parse($data['exportedAt'] ?? now())->format('d/m/Y H:i'),
            count($data['boxes'] ?? []), $items->count(), $items->sum(fn ($i) => count($i['photos'] ?? [])), count($data['activity'] ?? [])));

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        // Caixas
        $boxMap = [];
        foreach ($data['boxes'] ?? [] as $b) {
            $box = Box::withoutGlobalScope('acervo')->where('acervo_id', $aid)->where('import_ref', $b['id'])->first()
                ?? Box::withoutGlobalScope('acervo')->where('acervo_id', $aid)->where('name', $b['name'])->first();
            if (! $box) {
                [$uid] = $userFor($b['createdBy'] ?? null);
                $box = new Box(['name' => $b['name'], 'location' => ($b['location'] ?? null) ?: null, 'color' => $b['color'] ?? null]);
                $box->forceFill(['acervo_id' => $aid, 'import_ref' => $b['id'], 'created_by' => $uid, 'created_at' => $b['createdAt'] ?? now()])->save();
            }
            $boxMap[$b['id']] = $box->id;
        }

        // Peças + fotos
        $disk = Storage::disk(config('acervo.disk'));
        $itemMap = [];
        $bar = $this->output->createProgressBar($items->count());
        $failed = 0;
        foreach ($items as $i) {
            $bar->advance();
            if ($existing = Item::withTrashed()->withoutGlobalScope('acervo')->where('acervo_id', $aid)->where('import_ref', $i['id'])->first()) {
                $itemMap[$i['id']] = $existing->id;

                continue;
            }
            try {
                [$createdBy] = $userFor($i['createdBy'] ?? null);
                [$updatedBy] = $userFor($i['updatedBy'] ?? ($i['createdBy'] ?? null));
                $code = $i['code'] ?? null;
                if ($code && Item::withTrashed()->withoutGlobalScope('acervo')->where('acervo_id', $aid)->where('code', $code)->exists()) {
                    $code = null; // conflito: recebe um tombo novo
                }

                $item = new Item([
                    'title' => ($i['title'] ?? null) ?: 'Sem título',
                    'category' => ($i['category'] ?? null) ?: null, 'era' => ($i['era'] ?? null) ?: null, 'origin' => ($i['origin'] ?? null) ?: null,
                    'material' => ($i['material'] ?? null) ?: null, 'dimensions' => ($i['dimensions'] ?? null) ?: null, 'condition' => ($i['condition'] ?? null) ?: null,
                    'value' => is_numeric($i['value'] ?? null) ? $i['value'] : null,
                    'description' => ($i['description'] ?? null) ?: null, 'provenance' => ($i['provenance'] ?? null) ?: null,
                    'acquired_at' => ($i['acquiredAt'] ?? '') ?: null, 'notes' => ($i['notes'] ?? null) ?: null,
                    'box_id' => $boxMap[$i['boxId'] ?? ''] ?? null,
                    'ai_keywords' => $i['ai']['keywords'] ?? null,
                    'ai_authenticity' => ($i['ai']['authenticity'] ?? '') ?: null,
                    'ai_analyzed_at' => $i['ai']['at'] ?? null,
                    'ai_status' => isset($i['ai']) ? 'done' : 'none',
                    'batch' => $i['batch'] ?? null,
                    'merged_from' => $i['mergedFrom'] ?? null,
                    'status' => in_array($i['status'] ?? '', ['available', 'loaned', 'sold'], true) ? $i['status'] : 'available',
                    'showcase' => (bool) ($i['showcase'] ?? false),
                ]);
                $item->forceFill([
                    'acervo_id' => $aid,
                    'code' => $code ?? Item::nextCode($aid),
                    'import_ref' => $i['id'],
                    'created_by' => $createdBy, 'updated_by' => $updatedBy,
                    'created_at' => $i['createdAt'] ?? now(), 'updated_at' => $i['updatedAt'] ?? ($i['createdAt'] ?? now()),
                ])->saveQuietly();

                $item->syncTagNames($i['tags'] ?? []);

                $paths = [];
                foreach ($i['photos'] ?? [] as $p) {
                    $bin = $zip->getFromName("photos/{$p['id']}.jpg");
                    if ($bin === false) {
                        continue;
                    }
                    $path = config('acervo.photos_dir').'/'.Str::uuid().'.jpg';
                    $disk->put($path, $bin);
                    $paths[] = $path;
                }
                $photos->sync($item, $paths, log: false);
                $itemMap[$i['id']] = $item->id;
            } catch (Throwable $e) {
                $failed++;
                $this->newLine();
                $this->warn("Falha em {$i['code']}: {$e->getMessage()}");
            }
        }
        $bar->finish();
        $this->newLine();

        // Colecionadores
        $collectorMap = [];
        $cut = fn ($v, int $n) => ($v = trim((string) $v)) === '' ? null : mb_substr($v, 0, $n);
        foreach ($data['collectors'] ?? [] as $c) {
            if (! filled($c['name'] ?? null)) {
                continue;
            }
            $col = Collector::withTrashed()->withoutGlobalScope('acervo')->where('acervo_id', $aid)->where('import_ref', $c['id'])->first();
            if (! $col) {
                [$uid] = $userFor($c['createdBy'] ?? null);
                $col = new Collector;
                $col->forceFill([
                    'acervo_id' => $aid, 'import_ref' => $c['id'], 'created_by' => $uid,
                    'name' => mb_substr($c['name'], 0, 250), 'nick' => $cut($c['nick'] ?? null, 250),
                    'kind' => in_array($c['kind'] ?? '', ['collector', 'buyer', 'seller', 'dealer', 'other'], true) ? $c['kind'] : 'collector',
                    'doc' => $cut($c['doc'] ?? null, 20), 'birth' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($c['birth'] ?? '')) ? $c['birth'] : null,
                    'interests' => array_values(array_filter((array) ($c['interests'] ?? []))) ?: null,
                    'phone' => $cut($c['phone'] ?? null, 30), 'phone2' => $cut($c['phone2'] ?? null, 30),
                    'instagram' => $cut($c['instagram'] ?? null, 60), 'email' => $cut($c['email'] ?? null, 250),
                    'cep' => $cut($c['cep'] ?? null, 9), 'street' => $cut($c['street'] ?? null, 250), 'number' => $cut($c['number'] ?? null, 20),
                    'complement' => $cut($c['complement'] ?? null, 250), 'district' => $cut($c['district'] ?? null, 250),
                    'city' => $cut($c['city'] ?? null, 250), 'uf' => $cut(mb_strtoupper((string) ($c['uf'] ?? '')), 2),
                    'notes' => $cut($c['notes'] ?? null, 10000),
                    'created_at' => $c['createdAt'] ?? now(), 'updated_at' => $c['updatedAt'] ?? ($c['createdAt'] ?? now()),
                ])->saveQuietly();
            }
            $collectorMap[$c['id']] = $col->id;
        }

        // Movimentações (empréstimos, vendas, devoluções)
        $moves = 0;
        foreach ($data['movements'] ?? [] as $m) {
            $itemId = $itemMap[$m['itemId'] ?? ''] ?? null;
            if (! $itemId || Movement::withoutGlobalScope('acervo')->where('acervo_id', $aid)->where('import_ref', $m['id'])->exists()) {
                continue;
            }
            [$uid] = $userFor($m['by'] ?? null);
            $date = preg_match('/^\d{4}-\d{2}-\d{2}/', (string) ($m['date'] ?? '')) ? substr($m['date'], 0, 10) : Carbon::parse($m['at'] ?? now())->toDateString();
            Movement::withoutGlobalScope('acervo')->forceCreate([
                'acervo_id' => $aid, 'item_id' => $itemId, 'import_ref' => $m['id'],
                'kind' => in_array($m['kind'] ?? '', ['loan', 'sale', 'return', 'undo'], true) ? $m['kind'] : 'loan',
                'date' => $date, 'person' => mb_substr(($m['person'] ?? '') ?: '—', 0, 250),
                'collector_id' => $collectorMap[$m['collectorId'] ?? ''] ?? null,
                'notes' => $cut($m['notes'] ?? null, 250), 'user_id' => $uid,
                'created_at' => $m['at'] ?? now(), 'updated_at' => $m['at'] ?? now(),
            ]);
            $moves++;
        }

        // Coleções
        foreach ($data['collections'] ?? [] as $c) {
            if (! filled($c['name'] ?? null)) {
                continue;
            }
            $coll = ItemCollection::withoutGlobalScope('acervo')->where('acervo_id', $aid)
                ->where(fn ($q) => $q->where('import_ref', $c['id'])->orWhere('name', mb_substr($c['name'], 0, 250)))->first();
            if (! $coll) {
                [$uid] = $userFor($c['createdBy'] ?? null);
                $coll = new ItemCollection;
                $coll->forceFill(['acervo_id' => $aid, 'import_ref' => $c['id'], 'name' => mb_substr($c['name'], 0, 250),
                    'description' => $cut($c['description'] ?? null, 250), 'created_by' => $uid, 'created_at' => $c['createdAt'] ?? now()])->save();
            }
            $ids = collect($c['itemIds'] ?? [])->map(fn ($x) => $itemMap[$x] ?? null)->filter()->unique()->values()->all();
            $coll->items()->syncWithoutDetaching($ids);
        }

        // Histórico
        $acts = 0;
        foreach ($data['activity'] ?? [] as $a) {
            if (ActivityLog::withoutGlobalScope('acervo')->where('acervo_id', $aid)->where('import_ref', $a['id'])->exists()) {
                continue;
            }
            [$uid, $name] = $userFor($a['by'] ?? null);
            ActivityLog::forceCreate([
                'acervo_id' => $aid,
                'type' => $a['type'] ?? 'update',
                'item_id' => $itemMap[$a['itemId'] ?? ''] ?? null,
                'label' => Str::limit((string) ($a['label'] ?? ''), 250),
                'user_id' => $name && User::where('name', $name)->exists() ? $uid : null,
                'user_name' => $name ?: User::find($uid)?->name,
                'import_ref' => $a['id'],
                'created_at' => $a['at'] ?? now(),
            ]);
            $acts++;
        }
        ActivityLog::forceCreate(['acervo_id' => $aid, 'type' => 'create', 'label' => 'Importou backup com '.count($itemMap).' peça(s)', 'user_id' => $fallback->id, 'user_name' => $fallback->name, 'created_at' => now()]);

        $zip->close();
        $this->info(sprintf('Concluído: %d peças, %d caixas, %d colecionadores, %d movimentações, %d coleções, %d registros de atividade%s.',
            count($itemMap), count($boxMap), count($collectorMap), $moves, count($data['collections'] ?? []), $acts, $failed ? ", {$failed} com falha" : ''));

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** Acervo de destino: --acervo (nome ou slug, criado se preciso) ou o primeiro existente. */
    private function acervo(User $owner, array $data): Acervo
    {
        $want = $this->option('acervo');
        $acervo = $want
            ? Acervo::where('slug', $want)->orWhere('name', $want)->first()
            : Acervo::orderBy('id')->first();
        if (! $acervo) {
            $acervo = Acervo::create(['name' => $want ?: ($data['acervo'] ?? 'Acervo principal'), 'owner_id' => $owner->id]);
        }
        if (! $acervo->members()->whereKey($owner->id)->exists()) {
            $acervo->members()->attach($owner->id, ['role' => 'admin', 'status' => 'active']);
        }

        return $acervo;
    }

    /** Baixa o zip de um disco (ex.: "s3:imports/x.zip") para um arquivo temporário, se preciso. */
    private function localZip(string $file): string
    {
        if (preg_match('/^([a-z0-9_-]+):(?!\/\/)(.+)$/i', $file, $m) && array_key_exists($m[1], config('filesystems.disks'))) {
            $tmp = tempnam(sys_get_temp_dir(), 'dfz');
            $in = Storage::disk($m[1])->readStream($m[2]);
            $out = fopen($tmp, 'w');
            stream_copy_to_stream($in, $out);
            fclose($out);

            return $tmp;
        }

        return realpath($file) ?: base_path($file);
    }
}
