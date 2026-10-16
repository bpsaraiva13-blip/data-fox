<?php

namespace Tests\Feature;

use App\Filament\Pages\Trash;
use App\Filament\Resources\Boxes\Pages\ManageBoxes;
use App\Filament\Resources\Invitations\Pages\ManageInvitations;
use App\Filament\Resources\Items\Pages\CreateItem;
use App\Filament\Resources\Items\ItemResource;
use App\Mail\InvitationMail;
use App\Models\ActivityLog;
use App\Models\Box;
use App\Models\Invitation;
use App\Models\Item;
use App\Services\ZipImporter;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/** Uso do dia a dia: caixas, peças, convites, lixeira, lote por .zip e backup. */
class FuncionalidadesTest extends TestCase
{
    use RefreshDatabase;

    private function entrar(): array
    {
        $owner = $this->user(['role' => 'admin']);
        $a = $this->acervo($owner);
        $this->actingAs($owner);
        Filament::setTenant($a);

        return [$owner, $a];
    }

    public function test_cria_caixa_pelo_painel(): void
    {
        [, $a] = $this->entrar();
        Livewire::test(ManageBoxes::class)->callAction('create', data: ['name' => '01 Porcelanas', 'location' => 'Estante 2'])->assertHasNoActionErrors();
        $this->assertDatabaseHas('boxes', ['name' => '01 Porcelanas', 'acervo_id' => $a->id]);
    }

    public function test_cadastra_peca_com_tombo_e_historico(): void
    {
        [, $a] = $this->entrar();
        $box = $this->box($a);
        Livewire::test(CreateItem::class)->fillForm(['title' => 'Bule de porcelana', 'box_id' => $box->id])->call('create')->assertHasNoFormErrors();
        $item = Item::where('title', 'Bule de porcelana')->first();
        $this->assertNotNull($item);
        $this->assertSame($a->id, $item->acervo_id);
        $this->assertMatchesRegularExpression('/^[A-Z]+-\d{4}-\d{4}$/', $item->code);
        $this->assertTrue(ActivityLog::where('item_id', $item->id)->where('type', 'create')->exists());
    }

    public function test_convite_por_email(): void
    {
        Mail::fake();
        [, $a] = $this->entrar();
        Livewire::test(ManageInvitations::class)->callAction('create', data: ['email' => 'joao@exemplo.com', 'role' => 'viewer', 'all_boxes' => true, 'days' => 7])->assertHasNoActionErrors();
        $inv = Invitation::where('email', 'joao@exemplo.com')->first();
        $this->assertNotNull($inv);
        $this->assertSame($a->id, $inv->acervo_id);
        $this->assertTrue($inv->isPending());
        Mail::assertSent(InvitationMail::class);
    }

    public function test_lixeira_restaura_peca(): void
    {
        [, $a] = $this->entrar();
        $item = $this->item($a, null, ['title' => 'Relógio de bolso']);
        $item->delete();
        $this->assertSoftDeleted('items', ['id' => $item->id]);
        $this->get(Trash::getUrl(tenant: $a))->assertOk()->assertSee('Relógio de bolso');
        Livewire::test(Trash::class)->call('restore', $item->id);
        $this->assertNotSoftDeleted('items', ['id' => $item->id]);
    }

    public function test_lote_por_zip_cria_caixas_e_pecas(): void
    {
        Storage::fake('local');
        config(['acervo.disk' => 'local']);
        [, $a] = $this->entrar();

        $img = function (int $r) {
            $im = imagecreatetruecolor(40, 30);
            imagefill($im, 0, 0, imagecolorallocate($im, $r, 80, 120));
            ob_start();
            imagejpeg($im);

            return ob_get_clean();
        };
        $zipPath = tempnam(sys_get_temp_dir(), 'zip');
        $z = new ZipArchive;
        $z->open($zipPath, ZipArchive::OVERWRITE);
        $z->addFromString('Acervo/Caixa Moedas/Moeda 1960/frente.jpg', $img(10));
        $z->addFromString('Acervo/Caixa Moedas/Moeda 1960/verso.jpg', $img(20));
        $z->addFromString('Acervo/Caixa Moedas/Moeda 1975/frente.jpg', $img(30));
        $z->addFromString('Acervo/Caixa Selos/Selo azul/foto.jpg', $img(40));
        $z->addFromString('__MACOSX/lixo.jpg', 'x');
        $z->close();

        $r = app(ZipImporter::class)->import($zipPath, $a->id, 'boxes', null, 'name', 3, false, ['lote-teste'], 'acervo.zip');

        $this->assertSame(3, $r['items']);
        $this->assertSame(2, $r['created']);
        $moedas = Box::withoutGlobalScopes()->where('acervo_id', $a->id)->where('name', 'Caixa Moedas')->first();
        $this->assertNotNull($moedas);
        $this->assertSame(2, Item::withoutGlobalScopes()->where('box_id', $moedas->id)->count());
        $peca = Item::withoutGlobalScopes()->where('title', 'Moeda 1960')->first();
        $this->assertSame(2, $peca->photos()->count());
    }

    public function test_backup_e_limpeza_da_lixeira_rodam(): void
    {
        Storage::fake('local');
        config(['acervo.disk' => 'local', 'acervo.backup.disk' => 'local']);
        [, $a] = $this->entrar();
        $this->item($a);
        Filament::setTenant(null);
        $this->artisan('datafox:backup')->assertSuccessful();
        $this->artisan('model:prune')->assertSuccessful();
    }

    public function test_busca_na_lista_de_pecas(): void
    {
        [, $a] = $this->entrar();
        $calice = $this->item($a, null, ['title' => 'Cálice de cristal']);
        $moeda = $this->item($a, null, ['title' => 'Moeda de prata']);
        // sem acento também encontra (unaccent do PostgreSQL)
        Livewire::test(\App\Filament\Resources\Items\Pages\ListItems::class)->searchTable('calice')
            ->assertCanSeeTableRecords([$calice])->assertCanNotSeeTableRecords([$moeda]);
    }
}
