<?php

namespace Tests\Feature;

use App\Models\Acervo;
use App\Models\Box;
use App\Models\Collector;
use App\Models\Item;
use App\Models\ItemCollection;
use App\Models\Movement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Migração: backup .zip gerado pela versão web (página) entra inteiro no Laravel. */
class ImportacaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_importa_backup_da_pagina_sem_duplicar(): void
    {
        Storage::fake('local');
        config(['acervo.disk' => 'local']);
        $this->user(['role' => 'admin', 'email' => 'bruno@exemplo.com', 'name' => 'Bruno']);
        $zip = base_path('tests/fixtures/backup-pagina.zip');

        for ($i = 0; $i < 2; $i++) {   // a segunda vez não pode duplicar nada
            $this->artisan('datafox:import', ['file' => $zip, '--user' => 'bruno@exemplo.com', '--acervo' => 'Azzurra'])->assertSuccessful();
        }

        $a = Acervo::where('name', 'Azzurra')->firstOrFail();
        $items = Item::withoutGlobalScopes()->where('acervo_id', $a->id);
        $this->assertSame(4, (clone $items)->count());
        $this->assertSame(2, Box::withoutGlobalScopes()->where('acervo_id', $a->id)->count());
        $this->assertSame(1, Collector::withoutGlobalScopes()->where('acervo_id', $a->id)->count());
        $this->assertSame(2, Movement::withoutGlobalScopes()->where('acervo_id', $a->id)->count());

        $bule = (clone $items)->where('code', 'ACV-2026-0001')->firstOrFail();
        $this->assertSame('Bule de porcelana inglesa', $bule->title);
        $this->assertSame('01. Porcelanas', $bule->box->name);
        $this->assertSame(1, $bule->photos()->count());
        $this->assertTrue($bule->showcase);
        $this->assertEqualsCanonicalizing(['porcelana', 'inglaterra'], $bule->tags->pluck('name')->all());

        $mov = Movement::withoutGlobalScopes()->where('acervo_id', $a->id)->where('kind', 'loan')->firstOrFail();
        $this->assertSame('Denilson Cardoso', $mov->collector?->name);
        $this->assertSame('loaned', $mov->item->status);

        $col = ItemCollection::withoutGlobalScopes()->where('acervo_id', $a->id)->where('name', 'Destaques')->firstOrFail();
        $this->assertEqualsCanonicalizing(['ACV-2026-0001', 'ACV-2026-0003'], $col->items()->pluck('code')->all());
    }

    public function test_importa_backup_da_pagina_para_a_interface(): void
    {
        Storage::fake('local');
        config(['acervo.disk' => 'local', 'acervo.backup.disk' => 'local']);
        $bruno = $this->user(['email' => 'bruno@exemplo.com', 'name' => 'Bruno']);
        $zip = base_path('tests/fixtures/backup-pagina.zip');
        for ($i = 0; $i < 2; $i++) {
            $this->artisan('datafox:import-web', ['file' => $zip, '--user' => 'bruno@exemplo.com', '--acervo' => 'Azzurra'])->assertSuccessful();
        }
        $a = Acervo::where('name', 'Azzurra')->firstOrFail();
        $count = fn (string $c) => \Illuminate\Support\Facades\DB::table('docs')->where('acervo_id', $a->id)->where('col', $c)->whereNotNull('data')->count();
        $this->assertSame(4, $count('items'));
        $this->assertSame(2, $count('boxes'));
        $this->assertSame(1, $count('collectors'));
        $this->assertSame(2, $count('movements'));
        $this->assertSame(1, $count('collections'));
        // 4 fotos + 4 miniaturas geradas
        $this->assertSame(8, \Illuminate\Support\Facades\DB::table('assets')->where('acervo_id', $a->id)->count());

        // a interface lê com os mesmos ids e as fotos abrem
        $r = $this->actingAs($bruno)->postJson('/api/db/sync', ['queries' => ['i' => ['path' => "acervos/{$a->id}/items"]]])->assertOk()->json('results.i.docs');
        $bule = collect($r)->firstWhere('id', 'it1')['data'];
        $this->assertSame('Bule de porcelana inglesa', $bule['title']);
        $this->assertSame('bxA', $bule['boxId']);
        $this->actingAs($bruno)->get('/_blob/'.$bule['photos'][0]['id'])->assertOk();
        $this->assertSame('Bruno', $this->actingAs($bruno)->postJson('/api/people/profiles', ['ids' => ['u_bruno']])->json('u_bruno.name'));

        $this->artisan('datafox:export-docs')->assertSuccessful();
        $this->assertNotEmpty(Storage::disk('local')->files('backups/interface/'.$a->id));
    }
}
