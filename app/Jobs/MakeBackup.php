<?php

namespace App\Jobs;

use App\Models\AccessLog;
use App\Models\Acervo;
use App\Models\User;
use App\Services\BackupMaker;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Backup pedido pela tela (o diário roda pelo agendador). */
class MakeBackup implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1200;

    public int $tries = 2;

    public function __construct(public int $acervoId, public ?int $userId = null) {}

    public function handle(BackupMaker $maker): void
    {
        $acervo = Acervo::findOrFail($this->acervoId);
        $path = $maker->make($acervo);
        if ($user = User::find($this->userId)) {
            AccessLog::create(['acervo_id' => $acervo->id, 'user_id' => $user->id, 'type' => 'backup', 'label' => basename($path)]);
            Notification::make()->success()->title('Backup pronto')->body("{$acervo->name}: ".basename($path))->sendToDatabase($user);
        }
    }
}
