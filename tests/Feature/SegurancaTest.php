<?php

namespace Tests\Feature;

use App\Filament\Resources\Boxes\BoxResource;
use App\Filament\Resources\Invitations\InvitationResource;
use App\Filament\Resources\Items\ItemResource;
use App\Filament\Resources\Members\MemberResource;
use App\Models\Invitation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialUser;
use Mockery;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** Regras de acesso: isolamento entre acervos, caixas permitidas, convites, login social e vitrine. */
class SegurancaTest extends TestCase
{
    use RefreshDatabase;

    public function test_nao_entra_em_acervo_de_que_nao_participa(): void
    {
        $a = $this->acervo($this->user(), 'Acervo A');
        $b = $this->acervo($this->user(), 'Acervo B');
        $u = $this->user();
        $this->member($a, $u, 'admin');
        $this->actingAs($u)->get(Filament::getUrl($b))->assertNotFound();
    }

    public function test_peca_de_outro_acervo_nao_abre(): void
    {
        $ownerA = $this->user();
        $a = $this->acervo($ownerA, 'Acervo A');
        $b = $this->acervo($this->user(), 'Acervo B');
        $itemB = $this->item($b);
        $this->actingAs($ownerA);
        $this->get(ItemResource::getUrl('view', ['record' => $itemB], tenant: $a))->assertNotFound();
        $this->get(ItemResource::getUrl('edit', ['record' => $itemB], tenant: $a))->assertNotFound();
        $this->get(route('pdf.ficha', $itemB->id))->assertNotFound();
        $this->get(route('items.share-image', $itemB->id))->assertNotFound();
    }

    public function test_equipe_e_convites_de_outros_acervos_ficam_invisiveis(): void
    {
        $ownerA = $this->user();
        $a = $this->acervo($ownerA, 'Acervo A');
        $ownerB = $this->user(['email' => 'dono-b@exemplo.com']);
        $b = $this->acervo($ownerB, 'Acervo B');
        $this->actingAs($ownerB);
        Invitation::create(['acervo_id' => $b->id, 'email' => 'convidado-b@exemplo.com', 'role' => 'admin']);

        $this->actingAs($ownerA);
        $this->get(MemberResource::getUrl('index', tenant: $a))->assertOk()->assertDontSee('dono-b@exemplo.com');
        $this->get(InvitationResource::getUrl('index', tenant: $a))->assertOk()->assertDontSee('convidado-b@exemplo.com');
    }

    public function test_caixas_permitidas_limitam_pecas_e_caixas(): void
    {
        $owner = $this->user();
        $a = $this->acervo($owner);
        $b1 = $this->box($a, 'Caixa Liberada');
        $b2 = $this->box($a, 'Caixa Fechada');
        $ok = $this->item($a, $b1, ['title' => 'Peça liberada']);
        $no = $this->item($a, $b2, ['title' => 'Peça fechada']);
        $u = $this->user();
        $this->member($a, $u, 'editor', [(string) $b1->id]);
        $this->actingAs($u);

        $this->get(ItemResource::getUrl('view', ['record' => $ok], tenant: $a))->assertOk();
        $this->get(ItemResource::getUrl('view', ['record' => $no], tenant: $a))->assertNotFound();
        $this->get(ItemResource::getUrl('index', tenant: $a))->assertOk()->assertSee('Peça liberada')->assertDontSee('Peça fechada');
        $this->get(BoxResource::getUrl('index', tenant: $a))->assertOk()->assertSee('Caixa Liberada')->assertDontSee('Caixa Fechada');
        $this->get(route('pdf.ficha', $no->id))->assertNotFound();
        $this->get(route('pdf.box', $b2->id))->assertNotFound();
    }

    public function test_bloqueado_nao_entra_e_convite_nao_desbloqueia(): void
    {
        $owner = $this->user();
        $a = $this->acervo($owner);
        $u = $this->user();
        $this->member($a, $u, 'editor', null, 'blocked');
        // bloqueado: o acervo some para a pessoa
        $this->actingAs($u)->get(ItemResource::getUrl('index', tenant: $a))->assertNotFound();

        $this->actingAs($owner);
        $inv = Invitation::create(['acervo_id' => $a->id, 'email' => $u->email, 'role' => 'editor']);
        $this->actingAs($u)->post(route('invitations.accept', $inv->token))->assertForbidden();
        $this->assertSame('blocked', $a->membershipFor($u)->status);
    }

    public function test_convite_menor_nao_rebaixa_quem_ja_participa(): void
    {
        $owner = $this->user();
        $a = $this->acervo($owner);
        $this->actingAs($owner);
        $inv = Invitation::create(['acervo_id' => $a->id, 'email' => $owner->email, 'role' => 'viewer']);
        $this->post(route('invitations.accept', $inv->token));
        $this->assertSame('admin', $a->membershipFor($owner)->role);
    }

    public function test_usuario_desativado_nao_acessa_o_painel(): void
    {
        $owner = $this->user(['active' => false]);
        $a = $this->acervo($owner);
        $this->actingAs($owner)->get(Filament::getUrl($a))->assertForbidden();
    }

    public function test_vitrine_privada_e_pecas_fora_da_vitrine(): void
    {
        $owner = $this->user();
        $a = $this->acervo($owner);
        $a->update(['showcase_public' => false]);
        $pub = $this->item($a, null, ['title' => 'Peça exposta', 'showcase' => true]);
        $hid = $this->item($a, null, ['title' => 'Peça guardada', 'showcase' => false]);

        $this->get(route('showcase', $a->slug))->assertNotFound();
        $a->update(['showcase_public' => true]);
        $this->get(route('showcase', $a->slug))->assertOk()->assertSee('Peça exposta')->assertDontSee('Peça guardada');
        $this->get(route('showcase.item', [$a->slug, $pub->id]))->assertOk();
        $this->get(route('showcase.item', [$a->slug, $hid->id]))->assertNotFound();
    }

    public function test_cabecalhos_de_seguranca(): void
    {
        $this->get(route('filament.admin.auth.login'))->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    // ---------- login com Google ----------

    private function fakeGoogle(string $email, string $id = 'g-123', bool $verified = true): void
    {
        config(['services.google' => ['client_id' => 'x', 'client_secret' => 'y', 'redirect' => '/auth/google/callback']]);
        $social = (new SocialUser)->setRaw(['email_verified' => $verified])->map(['id' => $id, 'email' => $email, 'name' => 'Pessoa']);
        $driver = Mockery::mock();
        $driver->shouldReceive('user')->andReturn($social);
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
    }

    public function test_google_sem_conta_nem_convite_e_recusado(): void
    {
        $this->fakeGoogle('estranho@exemplo.com');
        $this->get('/auth/google/callback')->assertRedirect(Filament::getLoginUrl());
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'estranho@exemplo.com']);
    }

    public function test_google_com_email_nao_confirmado_e_recusado(): void
    {
        $this->user(['email' => 'ana@exemplo.com']);
        $this->fakeGoogle('ana@exemplo.com', verified: false);
        $this->get('/auth/google/callback')->assertRedirect(Filament::getLoginUrl());
        $this->assertGuest();
    }

    public function test_google_nao_herda_conta_com_email_nao_confirmado(): void
    {
        $this->user(['email' => 'ana@exemplo.com', 'email_verified_at' => null]);
        $this->fakeGoogle('ana@exemplo.com');
        $this->get('/auth/google/callback')->assertRedirect(Filament::getLoginUrl());
        $this->assertGuest();
    }

    public function test_google_entra_em_conta_existente(): void
    {
        $owner = $this->user(['email' => 'ana@exemplo.com']);
        $this->acervo($owner);
        $this->fakeGoogle('ana@exemplo.com');
        $this->get('/auth/google/callback')->assertRedirect();
        $this->assertAuthenticatedAs($owner);
        $this->assertSame('g-123', $owner->fresh()->google_id);
    }

    public function test_google_com_duas_etapas_pede_o_codigo(): void
    {
        $g = new Google2FA;
        $secret = $g->generateSecretKey();
        $owner = $this->user(['email' => 'ana@exemplo.com']);
        $owner->saveAppAuthenticationSecret($secret);
        $this->acervo($owner);
        $this->fakeGoogle('ana@exemplo.com');

        $this->get('/auth/google/callback')->assertRedirect(route('social.2fa'));
        $this->assertGuest();
        $this->get(route('social.2fa'))->assertOk()->assertSee('Digite o código');

        $this->post(route('social.2fa.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post(route('social.2fa.verify'), ['code' => $g->getCurrentOtp($secret)])->assertRedirect();
        $this->assertAuthenticatedAs($owner);
    }

    public function test_verificacao_sem_login_pendente_volta_ao_login(): void
    {
        $this->get(route('social.2fa'))->assertRedirect(Filament::getLoginUrl());
        $this->post(route('social.2fa.verify'), ['code' => '123456'])->assertRedirect(Filament::getLoginUrl());
        $this->assertGuest();
    }

    public function test_google_com_convite_cria_conta_e_entra(): void
    {
        $owner = $this->user();
        $a = $this->acervo($owner);
        $this->actingAs($owner);
        $inv = Invitation::create(['acervo_id' => $a->id, 'email' => 'nova@exemplo.com', 'role' => 'viewer']);
        auth()->logout();
        $this->fakeGoogle('nova@exemplo.com', 'g-999');
        $this->get('/auth/google/callback')->assertRedirect(Filament::getUrl($a));
        $nova = User::where('email', 'nova@exemplo.com')->first();
        $this->assertAuthenticatedAs($nova);
        $this->assertSame('viewer', $a->membershipFor($nova)->role);
        $this->assertNotNull($inv->fresh()->accepted_at);
    }
}
