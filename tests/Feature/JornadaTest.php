<?php

namespace Tests\Feature;

use App\Filament\Resources\Boxes\BoxResource;
use App\Filament\Resources\Collectors\CollectorResource;
use App\Filament\Resources\Invitations\InvitationResource;
use App\Filament\Resources\Items\ItemResource;
use App\Filament\Resources\Members\MemberResource;
use App\Filament\Resources\Movements\MovementResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Invitation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Jornada completa: primeiro administrador → acervo → convite → uso pela equipe. */
class JornadaTest extends TestCase
{
    use RefreshDatabase;

    public function test_primeiro_administrador_pelo_comando(): void
    {
        $this->artisan('datafox:admin', ['--name' => 'Bruno', '--email' => 'bruno@exemplo.com', '--password' => 'segredo123'])->assertSuccessful();
        $u = User::where('email', 'bruno@exemplo.com')->first();
        $this->assertTrue($u->isAdmin());
        $this->assertNotNull($u->email_verified_at);
    }

    public function test_sem_acervo_vai_para_criar_acervo(): void
    {
        $u = $this->user();
        $this->actingAs($u)->get('/')->assertRedirect();
    }

    public function test_dono_abre_todas_as_telas(): void
    {
        $owner = $this->user(['role' => 'admin']);
        $a = $this->acervo($owner);
        $box = $this->box($a);
        $item = $this->item($a, $box);
        $this->actingAs($owner);

        $urls = [
            Filament::getUrl($a),
            ItemResource::getUrl('index', tenant: $a),
            ItemResource::getUrl('create', tenant: $a),
            ItemResource::getUrl('view', ['record' => $item], tenant: $a),
            ItemResource::getUrl('edit', ['record' => $item], tenant: $a),
            BoxResource::getUrl('index', tenant: $a),
            BoxResource::getUrl('index', ['layout' => 'list'], tenant: $a),
            MemberResource::getUrl('index', tenant: $a),
            InvitationResource::getUrl('index', tenant: $a),
            CollectorResource::getUrl('index', tenant: $a),
            CollectorResource::getUrl('create', tenant: $a),
            MovementResource::getUrl('index', tenant: $a),
            UserResource::getUrl('index', tenant: $a),
            \App\Filament\Pages\Issues::getUrl(tenant: $a),
            \App\Filament\Pages\Trash::getUrl(tenant: $a),
            \App\Filament\Pages\BatchUpload::getUrl(tenant: $a),
            \App\Filament\Pages\Inventory::getUrl(tenant: $a),
            \App\Filament\Pages\Camera::getUrl(tenant: $a),
            \App\Filament\Pages\CompareImage::getUrl(tenant: $a),
            route('showcase', $a->slug),
        ];
        foreach ($urls as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_convite_cria_conta_e_entra_no_acervo(): void
    {
        $owner = $this->user();
        $a = $this->acervo($owner);
        $this->actingAs($owner);
        $inv = Invitation::create(['acervo_id' => $a->id, 'email' => 'maria@exemplo.com', 'role' => 'editor']);
        auth()->logout();

        $this->get(route('invitations.show', $inv->token))->assertOk()->assertSee('Criar conta e aceitar');
        $this->post(route('invitations.register', $inv->token), ['name' => 'Maria', 'password' => 'senha-forte-1', 'password_confirmation' => 'senha-forte-1'])
            ->assertRedirect(Filament::getUrl($a));

        $maria = User::where('email', 'maria@exemplo.com')->first();
        $this->assertAuthenticatedAs($maria);
        $this->assertSame('editor', $a->membershipFor($maria)->role);
        $this->assertNotNull($inv->fresh()->accepted_at);

        // usa o sistema: lista e cadastro abrem; equipe e convites não
        $this->get(ItemResource::getUrl('index', tenant: $a))->assertOk();
        $this->get(ItemResource::getUrl('create', tenant: $a))->assertOk();
        $this->get(MemberResource::getUrl('index', tenant: $a))->assertForbidden();
        $this->get(InvitationResource::getUrl('index', tenant: $a))->assertForbidden();
        $this->get(UserResource::getUrl('index', tenant: $a))->assertForbidden();

        // o convite não serve duas vezes
        $this->post('/logout');
        $this->post(route('invitations.register', $inv->token), ['name' => 'X', 'password' => 'senha-forte-1', 'password_confirmation' => 'senha-forte-1'])->assertStatus(410);
    }

    public function test_convite_vencido_nao_funciona(): void
    {
        $owner = $this->user();
        $a = $this->acervo($owner);
        $inv = Invitation::forceCreate(['acervo_id' => $a->id, 'email' => 'x@exemplo.com', 'role' => 'editor', 'token' => str_repeat('a', 48), 'expires_at' => now()->subDay(), 'invited_by' => $owner->id]);
        $this->get(route('invitations.show', $inv->token))->assertOk()->assertSee('Convite indisponível');
        $this->post(route('invitations.register', $inv->token), ['name' => 'X', 'password' => 'senha-forte-1', 'password_confirmation' => 'senha-forte-1'])->assertStatus(410);
    }

    public function test_somente_leitura_ve_mas_nao_cadastra(): void
    {
        $owner = $this->user();
        $a = $this->acervo($owner);
        $item = $this->item($a);
        $v = $this->user();
        $this->member($a, $v, 'viewer');
        $this->actingAs($v);
        $this->get(ItemResource::getUrl('index', tenant: $a))->assertOk();
        $this->get(ItemResource::getUrl('view', ['record' => $item], tenant: $a))->assertOk();
        $this->get(ItemResource::getUrl('create', tenant: $a))->assertForbidden();
        $this->get(ItemResource::getUrl('edit', ['record' => $item], tenant: $a))->assertForbidden();
        $this->get(\App\Filament\Pages\Trash::getUrl(tenant: $a))->assertForbidden();
    }

    public function test_so_vitrine_nao_ve_o_acervo(): void
    {
        $owner = $this->user();
        $a = $this->acervo($owner);
        $s = $this->user();
        $this->member($a, $s, 'showcase');
        $this->actingAs($s);
        $this->get(ItemResource::getUrl('index', tenant: $a))->assertForbidden();
        $this->get(route('showcase', $a->slug))->assertOk();
    }
}
