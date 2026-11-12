<?php

namespace App\Console\Commands;

use App\Jobs\ImportFolderPhotos;
use App\Models\Acervo;
use App\Models\User;
use App\Support\FolderImport;
use App\Support\Memberships;
use App\Support\PageStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Monta acervos a partir de pastas copiadas para o bucket (ex.: do MEGA com o rclone).
 *
 *   php artisan datafox:import-folder imports/mega --user=voce@email.com --dry-run
 *   php artisan datafox:import-folder imports/mega --user=voce@email.com
 *   php artisan datafox:import-folder imports/mega --report
 *
 * Pasta de 1º nível = acervo · 2º nível = caixa · cada foto = uma peça (em Pendências, para a IA completar).
 */
class ImportFolder extends Command
{
    protected $signature = 'datafox:import-folder
        {prefix=imports/mega : Pasta no bucket com uma subpasta por acervo}
        {--user= : E-mail de quem fica como dono dos acervos}
        {--only=* : Só estes acervos (nome da pasta; pode repetir)}
        {--dry-run : Só mostra o que seria criado}
        {--report : Mostra quanto já foi importado e o que falhou}
        {--sync : Processa aqui mesmo, sem a fila (só para poucos arquivos)}
        {--chunk=20 : Fotos por tarefa da fila}';

    protected $description = 'Importa pastas de fotos do bucket (acervo/caixa/fotos) como acervos, caixas e peças';

    public function handle(): int
    {
        $prefix = trim((string) $this->argument('prefix'), '/');
        $this->line("Lendo <info>{$prefix}/</info> no bucket…");
        [$tree, $skipped] = FolderImport::scan($prefix, $this->option('only') ?: null);
        if (! $tree) {
            $this->error('Nenhuma foto encontrada nessa pasta. Confira o caminho (ex.: imports/mega) e se a cópia terminou.');

            return self::FAILURE;
        }

        $rows = [];
        $plan = [];
        foreach ($tree as $folder => $boxes) {
            $acervo = $this->findAcervo($folder);
            $done = $acervo ? $this->importedIds($acervo->id) : [];
            $files = array_merge(...array_values($boxes));
            $have = count(array_filter($files, fn ($p) => isset($done[FolderImport::docId('item', $p)])));
            $rows[] = [$folder, $acervo ? "existe ({$acervo->name})" : 'novo: '.$this->title($folder), count(array_filter(array_keys($boxes))), count($files), $have];
            $plan[$folder] = [$acervo, $boxes, $done];
        }
        $this->table(['Pasta', 'Acervo no Data Fox', 'Caixas', 'Fotos', 'Já importadas'], $rows);
        if ($skipped) {
            $byExt = collect($skipped)->countBy(fn ($p) => Str::lower(pathinfo($p, PATHINFO_EXTENSION)) ?: '(sem extensão)')->sortDesc();
            $this->warn(count($skipped).' arquivo(s) que não são fotos JPG/PNG/WebP ficam de fora: '.$byExt->map(fn ($n, $e) => "{$e} {$n}")->implode(', '));
        }

        if ($this->option('report')) {
            return $this->report($plan);
        }
        if ($this->option('dry-run')) {
            $this->info('Nada foi gravado (--dry-run). Rode sem --dry-run para importar.');

            return self::SUCCESS;
        }

        $owner = $this->option('user') ? User::whereRaw('lower(email) = ?', [Str::lower($this->option('user'))])->first() : null;
        if (! $owner) {
            $this->error('Informe --user=email de uma conta existente (fica como dona dos acervos).');

            return self::FAILURE;
        }
        $ownerUid = PageStore::uid($owner);
        $batch = 'mega-'.now()->format('Ymd');
        $queued = 0;

        foreach ($plan as $folder => [$acervo, $boxes, $done]) {
            if (! $acervo) {
                $acervo = new Acervo(['name' => $this->title($folder)]);
                $acervo->owner_id = $owner->id;
                $acervo->save();
            }
            if (! $acervo->members()->where('users.id', $owner->id)->exists()) {
                Memberships::set($owner, $acervo, ['role' => 'admin'], $owner);
            }
            $aid = $acervo->id;
            $next = $this->maxCode($aid);
            $year = now()->year;
            $jobs = [];
            $ci = DB::table('docs')->where('acervo_id', $aid)->where('col', 'boxes')->count();
            $colors = ['#2A6A5C', '#97752A', '#8A4B3A', '#4A5E8C', '#6E4A7E', '#3F7A3A', '#9A3F5C', '#5A5A5A'];
            $new = 0;
            foreach ($boxes as $box => $files) {
                $boxId = null;
                if ($box !== '') {
                    $boxId = FolderImport::docId('box', $folder.'/'.$box);
                    $exists = DB::table('docs')->where('acervo_id', $aid)->where('col', 'boxes')->where('doc_id', $boxId)->exists();
                    if (! $exists) {
                        DB::table('docs')->insert(['acervo_id' => $aid, 'col' => 'boxes', 'doc_id' => $boxId, 'rev' => PageStore::nextRev(), 'created_at' => now(), 'updated_at' => now(),
                            'data' => json_encode(['name' => $this->title($box), 'location' => '', 'color' => $colors[$ci++ % count($colors)], 'createdAt' => now()->toIso8601String(), 'createdBy' => $ownerUid], JSON_UNESCAPED_UNICODE)]);
                    }
                }
                foreach ($files as $p) {
                    if (isset($done[FolderImport::docId('item', $p)])) {
                        continue;
                    }
                    $jobs[] = ['path' => $p, 'box' => $boxId, 'code' => sprintf('ACV-%d-%04d', $year, ++$next)];
                    $new++;
                }
            }
            foreach (array_chunk($jobs, max(1, (int) $this->option('chunk'))) as $chunk) {
                $job = new ImportFolderPhotos($aid, $prefix, $chunk, $batch, $ownerUid);
                $this->option('sync') ? dispatch_sync($job) : dispatch($job);
            }
            $queued += $new;
            if ($new) {
                PageStore::logActivity($aid, $owner, "Importação do MEGA: {$new} foto(s) em ".count(array_filter(array_keys($boxes))).' caixa(s)', 'import');
            }
            $this->line(sprintf('  %-32s %6d foto(s) %s', $acervo->name, $new, $new ? ($this->option('sync') ? 'importadas' : 'na fila') : '(nada novo)'));
        }

        $this->newLine();
        $this->info($this->option('sync') ? 'Concluído.' : "{$queued} foto(s) na fila. O servidor processa em segundo plano; as peças aparecem no Data Fox aos poucos.");
        $this->line('Para acompanhar: php artisan datafox:import-folder '.$prefix.' --report');

        return self::SUCCESS;
    }

    private function report(array $plan): int
    {
        $pending = DB::table('jobs')->where('payload', 'like', '%ImportFolderPhotos%')->count();
        $failedJobs = DB::table('failed_jobs')->where('payload', 'like', '%ImportFolderPhotos%')->count();
        $this->line("Tarefas na fila: {$pending} · tarefas que falharam: {$failedJobs}");
        foreach ($plan as $folder => [$acervo, $boxes, $done]) {
            $missing = collect(array_merge(...array_values($boxes)))->reject(fn ($p) => isset($done[FolderImport::docId('item', $p)]))->values();
            if ($missing->isNotEmpty() && ! $pending) {
                $this->warn("{$folder}: {$missing->count()} foto(s) não entraram (ex.: ".$missing->take(3)->implode(' | ').'). Rode a importação de novo para tentar só essas.');
            }
        }

        return self::SUCCESS;
    }

    private function findAcervo(string $folder): ?Acervo
    {
        $k = FolderImport::key($folder);

        return Acervo::all()->first(fn (Acervo $a) => FolderImport::key($a->name) === $k);
    }

    /** @return array<string,true> */
    private function importedIds(int $aid): array
    {
        return DB::table('docs')->where('acervo_id', $aid)->where('col', 'items')->where('doc_id', 'like', 'mi%')->pluck('doc_id')->flip()->map(fn () => true)->all();
    }

    private function maxCode(int $aid): int
    {
        $max = 0;
        $y = now()->year;
        DB::table('docs')->where('acervo_id', $aid)->where('col', 'items')->whereNotNull('data')->select(['data'])->orderBy('id')
            ->chunk(2000, function ($rows) use (&$max, $y) {
                foreach ($rows as $r) {
                    if (preg_match('/"code":\s*"ACV-'.$y.'-(\d+)"/', $r->data, $m)) {
                        $max = max($max, (int) $m[1]);
                    }
                }
            });

        return $max;
    }

    /** Só as palavras em CAIXA ALTA mudam: "DENISE (mãe Pedro Borges)" → "Denise (mãe Pedro Borges)"; "NATHI E EDER" → "Nathi e Eder". */
    private function title(string $s): string
    {
        $small = ['e', 'de', 'da', 'do', 'das', 'dos', 'a', 'o', 'em', 'com', 'para'];
        $words = explode(' ', trim(preg_replace('/\s+/', ' ', $s)));
        foreach ($words as $i => $w) {
            // sem vogal = sigla (VHS, DVD, CD, K7): fica como está
            if (! preg_match('/\p{L}/u', $w) || $w !== mb_strtoupper($w) || ! preg_match('/[AEIOUÁÉÍÓÚÂÊÔÃÕÀ]/u', $w)) {
                continue;
            }
            $w = mb_strtolower($w);
            $words[$i] = $i > 0 && in_array($w, $small, true) ? $w : preg_replace_callback('/^(\P{L}*)(\p{L})/u', fn ($m) => $m[1].mb_strtoupper($m[2]), $w);
        }

        return implode(' ', $words);
    }
}
