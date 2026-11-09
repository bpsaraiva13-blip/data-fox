<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\User;
use App\Support\PageStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Tela Usuários: criar a conta já com acervos, vincular depois e primeiro acesso pelo link. */
class UsuariosTest extends TestCase
{
    use RefreshDatabase;

    public function test_so_administrador_do_sistema_usa_a_tela_usuarios(): void
    {
        $comum = $this->user(['role' => 'editor']);
        $this->actingAs($comum)->getJson('/api/users')->assertForbidden();
        $this->actingAs($comum)->postJson('/api/users', ['name' => 'X', 'email' => 'x@x.com', 'access' => 'link'])->assertForbidden();
        $this->actingAs($this->user())->getJson('/api/users')->assertOk()->assertJsonStructure(['users', 'acervos', 'mail']);
    }

    public function test_cria_usuario_ja_vinculado_a_acervos_com_perfil_e_caixas(): void
    {
        $admin = $this->user();
        $a1 = $this->acervo($admin, 'Azzurra');
        $a2 = $this->acervo($admin, 'Reserva');

        $r = $this->actingAs($admin)->postJson('/api/users', [
            'name' => 'Maria Souza', 'email' => 'Maria@Email.com', 'access' => 'link',
            'acervos' => [['aid' => $a1->id, 'role' => 'editor', 'boxes' => null], ['aid' => $a2->id, 'role' => 'viewer', 'boxes' => ['cx1', 'none']]],
        ])->assertCreated();

        $u = User::where('email', 'maria@email.com')->firstOrFail();
        $this->assertSame('editor', $u->role);
        $this->assertNotNull($u->email_verified_at);
        $this->assertTrue($r->json('user.pending'));
        $this->assertStringContainsString('/acesso/', $r->json('link'));
        $this->assertSame('editor', $u->membership($a1)->role);
        $this->assertNull($u->membership($a1)->allowedBoxes());
        $this->assertSame(['cx1', 'none'], $u->membership($a2)->allowedBoxes());
        $this->assertCount(2, $r->json('user.acervos'));

        // aparece na Equipe do acervo (mesma fonte de dados da interface)
        $doc = collect((new PageStore($admin))->acervoDocs())->firstWhere('id', (string) $a1->id);
        $this->assertSame('editor', $doc['data']['members']->{PageStore::uid($u)}['role']);
        // e fica registrado na Atividade
        $this->assertTrue(DB::table('docs')->where('acervo_id', $a1->id)->where('col', 'activity')->where('data', 'like', '%Adicionou Maria Souza como Editor%')->exists());
    }

    public function test_e_mail_repetido_e_dados_invalidos(): void
    {
        $admin = $this->user();
        $this->user(['email' => 'ja@existe.com']);
        $this->actingAs($admin)->postJson('/api/users', ['name' => 'A', 'email' => 'JA@existe.com', 'access' => 'link'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'Já existe uma conta com este e-mail.');
        $this->actingAs($admin)->postJson('/api/users', ['name' => 'A', 'email' => 'novo@x.com', 'access' => 'password', 'password' => '123'])
            ->assertStatus(422);
        $this->actingAs($admin)->postJson('/api/users', ['name' => 'A', 'email' => 'novo@x.com', 'access' => 'link', 'acervos' => [['aid' => 999, 'role' => 'editor']]])
            ->assertStatus(422);
    }

    public function test_senha_definida_pelo_administrador(): void
    {
        $admin = $this->user();
        $r = $this->actingAs($admin)->postJson('/api/users', ['name' => 'Beto', 'email' => 'beto@x.com', 'access' => 'password', 'password' => 'senha-forte-1'])->assertCreated();
        $this->assertNull($r->json('link'));
        $this->assertTrue(Hash::check('senha-forte-1', User::where('email', 'beto@x.com')->value('password')));
    }

    public function test_vincula_muda_bloqueia_e_tira_depois_da_criacao(): void
    {
        $admin = $this->user();
        $a = $this->acervo($admin);
        $u = $this->user(['role' => 'editor']);

        $this->actingAs($admin)->putJson("/api/users/{$u->id}/acervos/{$a->id}", ['role' => 'viewer'])->assertOk();
        $this->assertSame('viewer', $u->fresh()->membership($a)->role);

        $this->actingAs($admin)->putJson("/api/users/{$u->id}/acervos/{$a->id}", ['role' => 'editor', 'status' => 'blocked', 'boxes' => ['cx9']])->assertOk()
            ->assertJsonPath('user.acervos.0.status', 'blocked')->assertJsonPath('user.acervos.0.boxes', ['cx9']);

        $this->actingAs($admin)->deleteJson("/api/users/{$u->id}/acervos/{$a->id}")->assertOk()->assertJsonCount(0, 'user.acervos');
        $this->assertNull(Membership::where('acervo_id', $a->id)->where('user_id', $u->id)->first());
    }

    public function test_dono_do_acervo_continua_administrador(): void
    {
        $admin = $this->user();
        $dono = $this->user(['role' => 'editor']);
        $a = $this->acervo($dono);
        $this->actingAs($admin)->putJson("/api/users/{$dono->id}/acervos/{$a->id}", ['role' => 'viewer', 'status' => 'blocked'])->assertOk();
        $m = $dono->fresh()->membership($a);
        $this->assertSame(['admin', 'active'], [$m->role, $m->status]);
        $this->actingAs($admin)->deleteJson("/api/users/{$dono->id}/acervos/{$a->id}")->assertStatus(422);
        $this->actingAs($admin)->deleteJson("/api/users/{$dono->id}")->assertStatus(422);
    }

    public function test_nao_pode_se_desativar_nem_se_excluir(): void
    {
        $admin = $this->user();
        $this->actingAs($admin)->patchJson("/api/users/{$admin->id}", ['active' => false])->assertStatus(422);
        $this->actingAs($admin)->patchJson("/api/users/{$admin->id}", ['system_admin' => false])->assertStatus(422);
        $this->actingAs($admin)->deleteJson("/api/users/{$admin->id}")->assertStatus(422);
    }

    public function test_desativar_encerra_as_sessoes_e_impede_o_link(): void
    {
        $admin = $this->user();
        $u = $this->user(['role' => 'editor']);
        $this->actingAs($admin)->patchJson("/api/users/{$u->id}", ['active' => false, 'name' => 'Novo Nome'])->assertOk()
            ->assertJsonPath('user.active', false)->assertJsonPath('user.name', 'Novo Nome');
        $this->actingAs($admin)->postJson("/api/users/{$u->id}/link")->assertStatus(422);
    }

    public function test_primeiro_acesso_pelo_link(): void
    {
        $admin = $this->user();
        $a = $this->acervo($admin, 'Azzurra');
        $link = $this->actingAs($admin)->postJson('/api/users', [
            'name' => 'Clara Lima', 'email' => 'clara@x.com', 'access' => 'link', 'acervos' => [['aid' => $a->id, 'role' => 'editor']],
        ])->json('link');
        Auth::logout();
        $path = parse_url($link, PHP_URL_PATH).'?'.parse_url($link, PHP_URL_QUERY);

        $this->get($path)->assertOk()->assertSee('Olá, Clara')->assertSee('Azzurra · Editor');
        $this->post(parse_url($link, PHP_URL_PATH), ['email' => 'clara@x.com', 'password' => 'senha-da-clara', 'password_confirmation' => 'outra'])->assertSessionHasErrors('password');
        $this->post(parse_url($link, PHP_URL_PATH), ['email' => 'clara@x.com', 'password' => 'senha-da-clara', 'password_confirmation' => 'senha-da-clara'])->assertRedirect('/');
        $this->assertAuthenticatedAs(User::where('email', 'clara@x.com')->first());

        // o link só vale uma vez
        Auth::logout();
        $this->get($path)->assertStatus(410)->assertSee('Este link não vale mais');
        $this->get('/acesso/token-falso?email=clara@x.com')->assertStatus(410);
    }

    public function test_equipe_procura_o_e_mail_e_convida_quem_nao_tem_conta(): void
    {
        $dono = $this->user(['role' => 'editor']);
        $a = $this->acervo($dono);
        $outro = $this->user(['role' => 'editor', 'email' => 'paulo@x.com', 'name' => 'Paulo']);

        $this->actingAs($dono)->getJson('/api/people/lookup?email=PAULO@x.com')->assertOk()
            ->assertJsonPath('exists', true)->assertJsonPath('id', PageStore::uid($outro));
        $this->actingAs($dono)->getJson('/api/people/lookup?email=ninguem@x.com')->assertJsonPath('exists', false);
        // quem não administra nenhum acervo não descobre contas
        $this->actingAs($outro)->getJson('/api/people/lookup?email='.$dono->email)->assertJsonPath('exists', false);
        // administrador do acervo (não do sistema) não cria contas: convida o e-mail
        $this->actingAs($dono)->postJson('/api/users', ['name' => 'N', 'email' => 'n@x.com', 'access' => 'link'])->assertForbidden();
        $this->actingAs($dono)->postJson('/api/db/write', ['op' => 'set', 'path' => 'invites/ABCDEFGH1234', 'data' => [
            'aid' => (string) $a->id, 'label' => 'nova@x.com', 'email' => 'nova@x.com', 'role' => 'viewer', 'boxes' => null,
        ]])->assertOk();
        $this->assertDatabaseHas('invitations', ['token' => 'ABCDEFGH1234', 'email' => 'nova@x.com', 'role' => 'viewer']);
    }

    public function test_interface_mostra_usuarios_so_para_administrador_do_sistema(): void
    {
        $this->actingAs($this->user())->get('/')->assertOk()->assertSee('id="view-usuarios"', false)->assertSee('"isAdmin":true', false);
        $this->actingAs($this->user(['role' => 'editor']))->get('/')->assertOk()->assertSee('"isAdmin":false', false);
    }
}
