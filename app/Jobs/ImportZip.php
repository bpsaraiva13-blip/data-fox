<?php

namespace App\Jobs;

use App\Filament\Resources\Items\ItemResource;
use App\Models\Acervo;
use App\Models\User;
use App\Services\ZipImporter;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Processa um .zip enviado no cadastro em lote, em segundo plano. Avisa no sininho ao terminar. */
class ImportZip implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public string $disk,
        public string $path,
        public string $zipName,
        public int $acervoId,
        public int $userId,
        public string $org,
        public ?int $boxId,
        public string $mode,
        public int $per,
        public bool $ai,
        public array $tags,
    ) {}

    public function handle(ZipImporter $importer): void
    {
        $user = User::findOrFail($this->userId);
        Auth::setUser($user);
        $acervo = Acervo::findOrFail($this->acervoId);

        // copia o .zip para um arquivo local (o ZipArchive precisa ler do disco)
        $tmp = tempnam(sys_get_temp_dir(), 'dfzip');
        $in = Storage::disk($this->disk)->readStream($this->path);
        $out = fopen($tmp, 'w');
        stream_copy_to_stream($in, $out);
        fclose($out);
        is_resource($in) && fclose($in);

        try {
            $r = $importer->import($tmp, $acervo->id, $this->org, $this->boxId, $this->mode, $this->per, $this->ai, $this->tags, $this->zipName);
            Notification::make()->success()
                ->title("{$r['items']} peça(s) cadastradas de {$this->zipName}")
                ->body(count($r['boxes']).' caixa(s)'.($r['created'] ? ", {$r['created']} nova(s)" : '').($this->ai ? '. A IA está preenchendo as fichas.' : '.'))
                ->actions([Action::make('ver')->label('Ver peças')->url(ItemResource::getUrl('index', ['filters' => ['batch' => ['value' => $r['batch']]]], tenant: $acervo))])
                ->sendToDatabase($user);
        } finally {
            @unlink($tmp);
            Storage::disk($this->disk)->delete($this->path);
        }
    }

    public function failed(?Throwable $e): void
    {
        if ($user = User::find($this->userId)) {
            Notification::make()->danger()->title("Não foi possível importar {$this->zipName}")->body($e?->getMessage())->sendToDatabase($user);
        }
    }
}
