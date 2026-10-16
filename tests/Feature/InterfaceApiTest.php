<?php

namespace Tests\Feature;

use App\Models\Acervo;
use App\Models\Invitation;
use App\Models\User;
use App\Support\PageStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** A interface do Data Fox (a mesma da versão web) sobre a API do Laravel: dados e permissões. */
class InterfaceApiTest extends TestCase
{
    use RefreshDatabase;

    private function w(User $u, string $op, string $path, ?array $data = null)
    {
        return $this->actingAs($u)->postJson('/api/db/write', ['op' => $op, 'path' => $path, 'data' => $data]);
    }

    private function q(User $u, string $path, array $extra = []): array
    {
        $r = $this->actingAs($u)->postJson('/api/db/sync', ['queries' => ['a' => ['path' => $path] + $extra]])->assertOk();

        return $r->json('results.a');
    }

    private function ids(array $res): array
    {
        $ids = collect($res['docs'] ?? [])->pluck('id')->all();
        sort($ids);

        return $ids;
    }

    /** Acervo criado pela interface, com o dono administrador. */
    private function novoAcervo(User $owner, string $name = 'Acervo'): string
    {
        return $this->w($owner, 'add', 'acervos', ['name' => $name])->assertOk()->json('id');
    }

    public function test_pagina_abre_com_o_runtime_para_quem_esta_logado(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $u = $this->user();
        $this->actingAs($u)->get('/')->assertOk()->assertSee('window.DATAFOX_SERVER', false)->assertSee('datafox-runtime.js', false)->assertSee('Data Fox');
    }

    public function test_cria_acervo_e_lista_so_os_seus(): void
    {
        $a = $this->user();
        $b = $this->user();
        $aid = $this->novoAcervo($a, 'Da Ana');
        $this->novoAcervo($b, 'Do Beto');
        $res = $this->q($a, 'acervos');
        $this->assertSame([$aid], $this->ids($res));
        $doc = $res['docs'][0]['data'];
        $this->assertSame('Da Ana', $doc['name']);
        $this->assertSame(PageStore::uid($a), $doc['ownerId']);
        $this->assertSame('admin', $doc['members'][PageStore::uid($a)]['role']);
    }

    public function test_perfis_de_acesso_nos_documentos(): void
    {
        $owner = $this->user();
        $aid = $this->novoAcervo($owner);
        $ed = $this->user();
        $vw = $this->user();
        $fora = $this->user();
        $this->member(Acervo::find($aid), $ed, 'editor');
        $this->member(Acervo::find($aid), $vw, 'viewer');

        $this->w($owner, 'set', "acervos/{$aid}/boxes/b1", ['name' => '01 Porcelanas'])->assertOk();
        $this->w($ed, 'set', "acervos/{$aid}/items/i1", ['title' => 'Bule', 'boxId' => 'b1'])->assertOk();
        $this->w($ed, 'update', "acervos/{$aid}/items/i1", ['era' => 'c. 1900'])->assertOk();

        $this->assertSame(['i1'], $this->ids($this->q($vw, "acervos/{$aid}/items")));
        $this->assertSame('c. 1900', $this->q($vw, "acervos/{$aid}/items")['docs'][0]['data']['era']);
        $this->w($vw, 'update', "acervos/{$aid}/items/i1", ['title' => 'X'])->assertForbidden();
        $this->w($vw, 'add', "acervos/{$aid}/activity", ['label' => 'Exportou', 'at' => now()->toIso8601String()])->assertOk();

        $this->assertSame('permission_denied', $this->q($fora, "acervos/{$aid}/items")['error']['code']);
        $this->w($fora, 'set', "acervos/{$aid}/items/i2", ['title' => 'Intruso'])->assertForbidden();
        $this->w($fora, 'add', "acervos/{$aid}/activity", ['label' => 'x'])->assertForbidden();
    }

    public function test_caixas_permitidas(): void
    {
        $owner = $this->user();
        $aid = $this->novoAcervo($owner);
        foreach (['b1', 'b2'] as $b) {
            $this->w($owner, 'set', "acervos/{$aid}/boxes/{$b}", ['name' => $b])->assertOk();
        }
        $this->w($owner, 'set', "acervos/{$aid}/items/i1", ['title' => 'Na b1', 'boxId' => 'b1']);
        $this->w($owner, 'set', "acervos/{$aid}/items/i2", ['title' => 'Na b2', 'boxId' => 'b2']);
        $this->w($owner, 'set', "acervos/{$aid}/items/i3", ['title' => 'Sem caixa', 'boxId' => '']);
        $u = $this->user();
        $this->member(Acervo::find($aid), $u, 'editor', ['b1']);

        $this->assertSame(['i1'], $this->ids($this->q($u, "acervos/{$aid}/items")));
        $this->w($u, 'update', "acervos/{$aid}/items/i2", ['title' => 'Mexi'])->assertForbidden();
        $this->w($u, 'update', "acervos/{$aid}/items/i1", ['boxId' => 'b2'])->assertForbidden();   // mover para caixa fechada
        $this->w($u, 'set', "acervos/{$aid}/boxes/b9", ['name' => 'Nova'])->assertForbidden();
        $this->w($u, 'update', "acervos/{$aid}/items/i1", ['title' => 'Ok'])->assertOk();
    }

    public function test_so_vitrine_ve_apenas_pecas_expostas(): void
    {
        $owner = $this->user();
        $aid = $this->novoAcervo($owner);
        $this->w($owner, 'set', "acervos/{$aid}/items/i1", ['title' => 'Exposta', 'showcase' => true]);
        $this->w($owner, 'set', "acervos/{$aid}/items/i2", ['title' => 'Guardada', 'showcase' => false]);
        $this->w($owner, 'add', "acervos/{$aid}/activity", ['label' => 'algo']);
        $s = $this->user();
        $this->member(Acervo::find($aid), $s, 'showcase');
        $this->assertSame(['i1'], $this->ids($this->q($s, "acervos/{$aid}/items")));
        $this->assertSame([], $this->ids($this->q($s, "acervos/{$aid}/activity")));
        $this->w($s, 'update', "acervos/{$aid}/items/i1", ['title' => 'x'])->assertForbidden();
    }

    public function test_bloqueado_perde_o_acesso(): void
    {
        $owner = $this->user();
        $aid = $this->novoAcervo($owner);
        $u = $this->user();
        $this->member(Acervo::find($aid), $u, 'editor', null, 'blocked');
        $this->assertSame('permission_denied', $this->q($u, "acervos/{$aid}/items")['error']['code']);
        $this->w($u, 'set', "acervos/{$aid}/items/x", ['title' => 'x'])->assertForbidden();
        // a interface ainda recebe o acervo (para mostrar o aviso de bloqueio)
        $this->assertSame('blocked', $this->q($u, 'acervos')['docs'][0]['data']['members'][PageStore::uid($u)]['status']);
    }

    public function test_caixa_vinculada_libera_so_as_pecas_dela(): void
    {
        $dono = $this->user();
        $home = $this->novoAcervo($dono, 'Casa');
        $outro = $this->user();
        $x = $this->novoAcervo($outro, 'Outro');
        // o dono de "Casa" também edita em "Outro" para poder vincular
        $this->member(Acervo::find($x), $dono, 'editor');
        $this->w($dono, 'set', "acervos/{$home}/boxes/bx", ['name' => 'Vinculada', 'linkedTo' => [$x]])->assertOk();
        $this->w($dono, 'set', "acervos/{$home}/boxes/by", ['name' => 'Só da casa'])->assertOk();
        $this->w($dono, 'set', "acervos/{$home}/items/i1", ['title' => 'Na vinculada', 'boxId' => 'bx']);
        $this->w($dono, 'set', "acervos/{$home}/items/i2", ['title' => 'Na outra', 'boxId' => 'by']);

        $this->assertSame(['bx'], $this->ids($this->q($outro, "acervos/{$home}/boxes", ['where' => [['linkedTo', 'array-contains', $x]]])));
        $this->assertSame(['i1'], $this->ids($this->q($outro, "acervos/{$home}/items", ['where' => [['boxId', '==', 'bx']]])));
        $this->assertSame('permission_denied', $this->q($outro, "acervos/{$home}/items")['error']['code']);
        $this->assertSame('permission_denied', $this->q($outro, "acervos/{$home}/items", ['where' => [['boxId', '==', 'by']]])['error']['code']);

        $this->w($outro, 'update', "acervos/{$home}/items/i1", ['era' => '1950'])->assertOk();
        $this->w($outro, 'set', "acervos/{$home}/items/i9", ['title' => 'Nova na vinculada', 'boxId' => 'bx'])->assertOk();
        $this->w($outro, 'update', "acervos/{$home}/items/i1", ['boxId' => 'by'])->assertForbidden();
        $this->w($outro, 'update', "acervos/{$home}/items/i2", ['era' => 'x'])->assertForbidden();
        // desvincular pode; vincular a outro acervo, não
        $this->w($outro, 'update', "acervos/{$home}/boxes/bx", ['linkedTo' => []])->assertOk();
        $this->w($outro, 'update', "acervos/{$home}/boxes/bx", ['linkedTo' => [$x]])->assertForbidden();
    }

    public function test_vincular_exige_ser_editor_no_outro_acervo(): void
    {
        $dono = $this->user();
        $home = $this->novoAcervo($dono);
        $x = $this->novoAcervo($this->user());
        $this->w($dono, 'set', "acervos/{$home}/boxes/bx", ['name' => 'B', 'linkedTo' => [$x]])->assertForbidden();
    }

    public function test_equipe_pela_interface(): void
    {
        $owner = $this->user();
        $aid = $this->novoAcervo($owner);
        $ana = $this->user();
        $beto = $this->user();
        $o = PageStore::uid($owner);
        $a = PageStore::uid($ana);
        $b = PageStore::uid($beto);

        // admin adiciona e muda perfil
        $this->w($owner, 'update', "acervos/{$aid}", ['members' => [$o => ['role' => 'admin'], $a => ['role' => 'editor'], $b => ['role' => 'viewer', 'boxes' => ['b1']]]])->assertOk();
        $this->assertSame('editor', Acervo::find($aid)->membershipFor($ana)->role);
        $this->assertSame(['b1'], Acervo::find($aid)->membershipFor($beto)->allowedBoxes());

        // quem não é admin não promove ninguém nem muda configurações
        $this->w($ana, 'update', "acervos/{$aid}", ['members' => [$o => ['role' => 'admin'], $a => ['role' => 'admin'], $b => ['role' => 'admin']]])->assertOk();
        $this->assertSame('editor', Acervo::find($aid)->membershipFor($ana)->role);
        $this->assertSame('viewer', Acervo::find($aid)->membershipFor($beto)->role);
        $this->w($ana, 'update', "acervos/{$aid}", ['name' => 'Roubado'])->assertForbidden();

        // sair do acervo
        $this->w($beto, 'update', "acervos/{$aid}", ['members' => [$o => ['role' => 'admin']]])->assertOk();
        $this->assertNull(Acervo::find($aid)->membershipFor($beto));

        // o dono nunca sai nem muda
        $this->w($owner, 'update', "acervos/{$aid}", ['members' => [$a => ['role' => 'editor']]])->assertOk();
        $this->assertSame('admin', Acervo::find($aid)->membershipFor($owner)->role);
        $this->w($ana, 'delete', "acervos/{$aid}")->assertForbidden();
    }

    public function test_perfil_so_o_proprio_e_pin_alheio_escondido(): void
    {
        $owner = $this->user();
        $aid = $this->novoAcervo($owner);
        $ana = $this->user();
        $this->member(Acervo::find($aid), $ana, 'editor');
        $this->w($ana, 'set', 'profiles/'.PageStore::uid($ana), ['name' => 'Ana', 'pin' => ['hash' => 'segredo', 'salt' => 's', 'len' => 4]])->assertOk();
        $this->w($ana, 'set', 'profiles/'.PageStore::uid($owner), ['name' => 'Hack'])->assertForbidden();
        $p = collect($this->q($owner, 'profiles')['docs'])->firstWhere('id', PageStore::uid($ana))['data'];
        $this->assertSame('Ana', $p['name']);
        $this->assertSame(['len' => 4], $p['pin']);
    }

    public function test_convite_por_link_cria_conta_e_entra(): void
    {
        $owner = $this->user();
        $aid = $this->novoAcervo($owner);
        $this->w($owner, 'set', 'invites/CONVITE123', ['aid' => $aid, 'role' => 'editor', 'label' => 'Maria', 'expiresAt' => now()->addDays(7)->toIso8601String()])->assertOk();
        $this->assertSame(['CONVITE123'], $this->ids($this->q($owner, 'invites')));

        $outsider = $this->user();
        $this->w($outsider, 'set', 'invites/OUTRO12345', ['aid' => $aid, 'role' => 'admin'])->assertForbidden();
        $this->assertSame([], $this->ids($this->q($outsider, 'invites')));

        auth()->logout();
        $this->get('/convite/CONVITE123')->assertOk()->assertSee('Seu e-mail');
        $this->post('/convite/CONVITE123/registrar', ['name' => 'Maria', 'email' => 'maria@exemplo.com', 'password' => 'senha-forte-1', 'password_confirmation' => 'senha-forte-1'])
            ->assertRedirect('/?acervo='.$aid);
        $maria = User::where('email', 'maria@exemplo.com')->firstOrFail();
        $this->assertSame('editor', Acervo::find($aid)->membershipFor($maria)->role);
        $this->assertNotNull(Invitation::where('token', 'CONVITE123')->first()->accepted_at);
    }

    public function test_sincronizacao_traz_so_o_que_mudou(): void
    {
        $owner = $this->user();
        $aid = $this->novoAcervo($owner);
        $this->w($owner, 'set', "acervos/{$aid}/items/a", ['title' => 'A']);
        $this->w($owner, 'set', "acervos/{$aid}/items/b", ['title' => 'B']);
        $first = $this->q($owner, "acervos/{$aid}/items");
        $rev = (int) substr($first['ver'], 1);
        $this->assertTrue($this->q($owner, "acervos/{$aid}/items", ['ver' => $first['ver'], 'since' => $rev])['same']);

        $this->w($owner, 'update', "acervos/{$aid}/items/a", ['title' => 'A2']);
        $this->w($owner, 'delete', "acervos/{$aid}/items/b");
        $inc = $this->q($owner, "acervos/{$aid}/items", ['ver' => $first['ver'], 'since' => $rev]);
        $this->assertFalse($inc['full']);
        $this->assertSame('A2', $inc['docs'][0]['data']['title']);
        $this->assertSame(['b'], $inc['removed']);
        $this->assertSame(['a'], $this->ids($this->q($owner, "acervos/{$aid}/items")));
    }

    public function test_fotos_no_bucket(): void
    {
        Storage::fake('local');
        config(['acervo.disk' => 'local']);
        $owner = $this->user();
        $aid = $this->novoAcervo($owner);
        $id = $this->actingAs($owner)->post('/api/assets', ['file' => UploadedFile::fake()->image('a.jpg', 10, 10), 'type' => 'image/jpeg', 'aid' => $aid], ['Accept' => 'application/json'])->assertOk()->json('id');
        $this->actingAs($owner)->get('/_blob/'.$id)->assertOk();
        $this->actingAs($owner)->post('/api/assets', ['file' => UploadedFile::fake()->create('x.svg', 1, 'image/svg+xml'), 'type' => 'image/svg+xml'], ['Accept' => 'application/json'])->assertStatus(422);
        $this->actingAs($this->user())->deleteJson('/api/assets/'.$id)->assertForbidden();
        auth()->logout();
        $this->get('/_blob/'.$id)->assertRedirect();
    }

    public function test_ia_sem_chave_fica_desligada(): void
    {
        config(['acervo.ai.api_key' => null]);
        $this->actingAs($this->user())->postJson('/api/ai/json', ['prompt' => 'oi'])->assertStatus(503);
    }

    public function test_painel_admin_so_para_administradores(): void
    {
        $u = $this->user(['role' => 'editor']);
        $this->novoAcervo($u);
        $this->actingAs($u)->get('/admin')->assertRedirect('/');
        $this->actingAs($u)->get(route('filament.admin.auth.profile'))->assertOk();
    }
}
