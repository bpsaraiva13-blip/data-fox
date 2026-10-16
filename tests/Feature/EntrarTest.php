<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** Tela de entrar do Data Fox: e-mail e senha, erros, conta desativada e duas etapas. */
class EntrarTest extends TestCase
{
    use RefreshDatabase;

    public function test_tela_tem_o_visual_do_data_fox_e_os_botoes_quando_configurados(): void
    {
        $this->get('/entrar')->assertOk()->assertSee('Entrar no Data Fox')->assertDontSee('Continuar com Google');
        config(['services.google' => ['client_id' => 'x', 'client_secret' => 'y', 'redirect' => '/auth/google/callback']]);
        $this->get('/entrar')->assertOk()->assertSee('Continuar com Google');
    }

    public function test_entra_com_senha_e_vai_para_o_data_fox(): void
    {
        $u = $this->user(['email' => 'ana@exemplo.com', 'password' => 'senha-forte-1']);
        $this->post('/entrar', ['email' => 'ANA@exemplo.com', 'password' => 'senha-forte-1'])->assertRedirect(url('/'));
        $this->assertAuthenticatedAs($u);
        $this->get('/entrar')->assertRedirect(url('/'));
    }

    public function test_senha_errada_nao_entra_e_limita_tentativas(): void
    {
        $this->user(['email' => 'ana@exemplo.com', 'password' => 'senha-forte-1']);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/entrar', ['email' => 'ana@exemplo.com', 'password' => 'errada'])->assertSessionHasErrors('email');
        }
        $this->post('/entrar', ['email' => 'ana@exemplo.com', 'password' => 'senha-forte-1'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_conta_desativada_nao_entra(): void
    {
        $this->user(['email' => 'ana@exemplo.com', 'password' => 'senha-forte-1', 'active' => false]);
        $this->post('/entrar', ['email' => 'ana@exemplo.com', 'password' => 'senha-forte-1'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_duas_etapas_pede_o_codigo_tambem_com_senha(): void
    {
        $g = new Google2FA;
        $secret = $g->generateSecretKey();
        $u = $this->user(['email' => 'ana@exemplo.com', 'password' => 'senha-forte-1']);
        $u->saveAppAuthenticationSecret($secret);
        $this->post('/entrar', ['email' => 'ana@exemplo.com', 'password' => 'senha-forte-1'])->assertRedirect(route('social.2fa'));
        $this->assertGuest();
        $this->get(route('social.2fa'))->assertOk()->assertSee('e-mail e senha');
        $this->post(route('social.2fa.verify'), ['code' => $g->getCurrentOtp($secret)])->assertRedirect(url('/'));
        $this->assertAuthenticatedAs($u);
    }

    public function test_login_do_painel_vira_a_tela_do_data_fox(): void
    {
        $this->get('/admin/login')->assertRedirect(route('login'));
        $this->get('/admin')->assertRedirect();
    }
}
