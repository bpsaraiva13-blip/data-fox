<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_visitante_e_levado_ao_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_tela_de_login_abre(): void
    {
        $this->get(route('filament.admin.auth.login'))->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk()->assertSee('Entrar no Data Fox');
    }
}
