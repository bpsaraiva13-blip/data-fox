<?php

namespace App\Filament\Widgets;

use App\Jobs\MakeBackup;
use App\Models\Acervo;
use App\Services\BackupMaker;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Storage;

/** Lembra o administrador de fazer backup e lista os últimos arquivos gerados. */
class BackupReminder extends Widget
{
    protected string $view = 'filament.widgets.backup-reminder';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -10;

    public static function canView(): bool
    {
        return auth()->user()?->isAcervoAdmin() ?? false;
    }

    public function acervo(): ?Acervo
    {
        return Acervo::current();
    }

    public function stale(): bool
    {
        $at = $this->acervo()?->last_backup_at;

        return ! $at || $at->lt(now()->subDays(config('acervo.backup.remind_after_days')));
    }

    /** @return array<int, array{name:string, url:?string}> */
    public function files(): array
    {
        $disk = Storage::disk(config('acervo.backup.disk'));

        return collect(app(BackupMaker::class)->list($this->acervo()))->take(3)->map(function ($f) use ($disk) {
            try {
                $url = $disk->temporaryUrl($f, now()->addMinutes(30));
            } catch (\Throwable) {
                $url = null;
            }

            return ['name' => basename($f), 'url' => $url];
        })->all();
    }

    public function backupNow(): void
    {
        MakeBackup::dispatch($this->acervo()->id, auth()->id());
        Notification::make()->success()->title('Backup em andamento')
            ->body('Você pode continuar usando o sistema. O aviso chega no sininho quando terminar.')->send();
    }
}
