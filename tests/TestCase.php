<?php

namespace Tests;

use App\Models\Acervo;
use App\Models\Box;
use App\Models\Item;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel('admin');
    }

    /** Por padrão, administrador do sistema (o painel /admin é só deles); passe role=editor para um usuário comum. */
    protected function user(array $attrs = []): User
    {
        return User::factory()->create($attrs + ['role' => 'admin', 'active' => true]);
    }

    /** Acervo com o dono como administrador. */
    protected function acervo(User $owner, string $name = 'Acervo Teste'): Acervo
    {
        $this->actingAs($owner);
        $a = Acervo::create(['name' => $name, 'owner_id' => $owner->id]);
        auth()->logout();

        return $a->fresh();
    }

    protected function member(Acervo $a, User $u, string $role, ?array $boxes = null, string $status = 'active'): void
    {
        $a->members()->syncWithoutDetaching([$u->id => ['role' => $role, 'status' => $status, 'boxes' => $boxes]]);
    }

    protected function box(Acervo $a, string $name = 'Caixa 1'): Box
    {
        return Box::withoutGlobalScopes()->forceCreate(['acervo_id' => $a->id, 'name' => $name]);
    }

    protected function item(Acervo $a, ?Box $box = null, array $attrs = []): Item
    {
        return Item::withoutGlobalScopes()->forceCreate($attrs + ['acervo_id' => $a->id, 'title' => 'Peça '.uniqid(), 'box_id' => $box?->id]);
    }
}
