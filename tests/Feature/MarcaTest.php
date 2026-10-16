<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Marca do sistema: frase da tela de entrar, nome, subtítulo e logo (ícone do site), só por administradores. */
class MarcaTest extends TestCase
{
    use RefreshDatabase;

    public function test_padrao_aparece_na_tela_de_entrar(): void
    {
        $this->get('/entrar')->assertOk()->assertSee('Equipe Azzurra')->assertSee('Data Fox');
    }

    public function test_administrador_muda_frase_nome_e_logo(): void
    {
        Storage::fake('local');
        config(['acervo.disk' => 'local']);
        $admin = $this->user(['role' => 'admin']);
        $r = $this->actingAs($admin)->post('/api/site', [
            'name' => 'Azzurra', 'sub' => 'Coleções da família', 'tagline' => 'Nossa história em peças.',
            'logo' => UploadedFile::fake()->image('logo.png', 64, 64),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertStringContainsString('/brand/icon?v=', $r->json('brand.logo'));

        auth()->logout();
        $this->get('/entrar')->assertOk()->assertSee('Nossa história em peças.')->assertSee('Azzurra')->assertSee('/brand/icon?v=', false);
        $this->get('/brand/icon')->assertOk()->assertHeader('Content-Type', 'image/png');

        // voltar às iniciais
        $this->actingAs($admin)->post('/api/site', ['name' => 'Azzurra', 'remove_logo' => '1'], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('brand.logo', null);
        $this->get('/brand/icon')->assertRedirect();
    }

    public function test_quem_nao_e_administrador_nao_muda_a_marca(): void
    {
        $u = $this->user(['role' => 'editor']);
        $this->actingAs($u)->post('/api/site', ['name' => 'Hack'], ['Accept' => 'application/json'])->assertForbidden();
        $this->get('/entrar')->assertDontSee('Hack');
    }

    public function test_logo_svg_e_recusado(): void
    {
        $admin = $this->user(['role' => 'admin']);
        $this->actingAs($admin)->post('/api/site', ['name' => 'X', 'logo' => UploadedFile::fake()->create('a.svg', 1, 'image/svg+xml')], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_interface_recebe_a_marca(): void
    {
        $this->actingAs($this->user())->get('/')->assertOk()->assertSee('"tagline":', false);
    }
}
