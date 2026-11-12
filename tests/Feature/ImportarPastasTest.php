<?php

namespace Tests\Feature;

use App\Models\Acervo;
use App\Support\FolderImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** datafox:import-folder: pastas do MEGA (copiadas para o bucket) viram acervos, caixas e peças. */
class ImportarPastasTest extends TestCase
{
    use RefreshDatabase;

    private function jpg(int $w = 64, int $h = 48, int $c = 0x336699): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, $c);
        ob_start();
        imagejpeg($im);

        return ob_get_clean();
    }

    private function montar(): void
    {
        Storage::fake('local');
        config(['acervo.disk' => 'local']);
        $d = Storage::disk('local');
        $d->put('imports/mega/ACERVO AZZURRA/01 - POTES/POTES MARGARINA/20200824_152629.jpg', $this->jpg());
        $d->put('imports/mega/ACERVO AZZURRA/01 - POTES/POTES MARGARINA/DSCN0637.JPG', $this->jpg(48, 64, 0xAA3311));
        $d->put('imports/mega/ACERVO AZZURRA/01 - POTES/TIGELAS/VIDRO/x.png', (function () { $im = imagecreatetruecolor(20, 20); ob_start(); imagepng($im); return ob_get_clean(); })());
        $d->put('imports/mega/ACERVO AZZURRA/03 e 04 - GARRAFAS/a.jpg', $this->jpg());
        $d->put('imports/mega/ACERVO AZZURRA/03 e 04 - GARRAFAS/video.mp4', 'x');
        $d->put('imports/mega/NATHI E EDER/01 - CAIXA/b.jpeg', $this->jpg());
        $d->put('imports/mega/GINCANAS/x/c.jpg', $this->jpg());
    }

    public function test_dry_run_nao_grava_nada(): void
    {
        $this->montar();
        $this->user(['email' => 'bruno@x.com']);
        $this->artisan('datafox:import-folder', ['prefix' => 'imports/mega', '--dry-run' => true])
            ->expectsOutputToContain('mp4 1')->assertSuccessful();
        $this->assertSame(0, Acervo::count());
        $this->assertSame(0, DB::table('docs')->count());
    }

    public function test_importa_acervos_caixas_e_pecas_sem_duplicar(): void
    {
        $this->montar();
        $u = $this->user(['email' => 'bruno@x.com']);
        $args = ['prefix' => 'imports/mega', '--user' => 'BRUNO@x.com', '--only' => ['ACERVO AZZURRA', 'nathi e eder'], '--sync' => true];
        $this->artisan('datafox:import-folder', $args)->assertSuccessful();

        $this->assertEqualsCanonicalizing(['Acervo Azzurra', 'Nathi e Eder'], Acervo::pluck('name')->all());
        $a = Acervo::where('name', 'Acervo Azzurra')->first();
        $this->assertSame('admin', $u->membership($a)->role);

        $boxes = DB::table('docs')->where('acervo_id', $a->id)->where('col', 'boxes')->get()->map(fn ($r) => json_decode($r->data, true)['name'])->sort()->values()->all();
        $this->assertSame(['01 - Potes', '03 e 04 - Garrafas'], $boxes);

        $items = DB::table('docs')->where('acervo_id', $a->id)->where('col', 'items')->get()->map(fn ($r) => json_decode($r->data, true));
        $this->assertCount(4, $items);
        $marg = $items->firstWhere('notes', 'Importado do MEGA: DSCN0637.JPG');
        $this->assertSame('Potes margarina', $marg['category']);
        $this->assertSame('Potes margarina', $marg['title']);
        $this->assertNotNull($marg['aiFail']);
        $this->assertSame(FolderImport::docId('box', 'ACERVO AZZURRA/01 - POTES'), $marg['boxId']);
        $this->assertSame(['vidro'], $items->firstWhere('notes', 'Importado do MEGA: x.png')['tags']);
        $this->assertSame('Garrafas', $items->firstWhere('notes', 'Importado do MEGA: a.jpg')['title']);
        $this->assertCount(4, $items->pluck('code')->unique());
        // foto e miniatura gravadas no disco e na tabela de arquivos
        $p = $marg['photos'][0];
        $this->assertSame(2, DB::table('assets')->whereIn('id', [$p['id'], $p['thumb']])->count());
        Storage::disk('local')->assertExists(DB::table('assets')->where('id', $p['thumb'])->value('path'));
        $this->assertSame(16, strlen($p['hash']));

        // repetir não duplica; GINCANAS ficou de fora
        $this->artisan('datafox:import-folder', $args)->assertSuccessful();
        $this->assertSame(4, DB::table('docs')->where('acervo_id', $a->id)->where('col', 'items')->count());
        $this->assertSame(2, Acervo::count());
        $this->artisan('datafox:import-folder', ['prefix' => 'imports/mega', '--report' => true, '--only' => ['ACERVO AZZURRA']])->assertSuccessful();
    }

    public function test_peca_apagada_nao_volta_na_reimportacao(): void
    {
        $this->montar();
        $this->user(['email' => 'bruno@x.com']);
        $args = ['prefix' => 'imports/mega', '--user' => 'bruno@x.com', '--only' => ['NATHI E EDER'], '--sync' => true];
        $this->artisan('datafox:import-folder', $args)->assertSuccessful();
        DB::table('docs')->where('col', 'items')->update(['data' => null]);
        $this->artisan('datafox:import-folder', $args)->assertSuccessful();
        $this->assertSame(0, DB::table('docs')->where('col', 'items')->whereNotNull('data')->count());
    }
}
