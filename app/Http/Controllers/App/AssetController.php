<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Support\PageStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fotos e anexos da interface (window.claude.use('assets') e /_blob/<id>).
 * Ficam no bucket privado; o endereço /_blob/<id> só abre para quem está logado,
 * e o id é aleatório (impossível de adivinhar), como na versão web.
 */
class AssetController extends Controller
{
    private const TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'image/heif', 'application/pdf', 'text/plain', 'text/csv', 'application/zip'];

    public function upload(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'max:51200'], 'aid' => ['nullable', 'string', 'max:20']]);
        $f = $request->file('file');
        $mime = $request->input('type') ?: $f->getMimeType();
        if (! in_array($mime, self::TYPES, true)) {
            return response()->json(['error' => ['code' => 'invalid_type', 'message' => 'Tipo de arquivo não aceito.']], 422);
        }
        $aid = $request->input('aid');
        $store = new PageStore($request->user());
        $aid = $aid && $store->role($aid) ? (int) $aid : null;

        $id = (string) Str::uuid();
        $path = 'acervo/assets/'.substr($id, 0, 2).'/'.$id;
        try {
            $ok = Storage::disk(config('acervo.disk'))->put($path, file_get_contents($f->getRealPath()), ['ContentType' => $mime]);
        } catch (\Throwable $e) {
            report($e);
            $ok = false;
        }
        if (! $ok) {
            report(new \RuntimeException('Falha ao gravar no disco '.config('acervo.disk').': '.$path));

            return response()->json(['error' => ['code' => 'storage_failed', 'message' => 'Não foi possível guardar a foto no armazenamento do servidor. Avise o administrador (rode php artisan datafox:check-storage).']], 500);
        }
        DB::table('assets')->insert(['id' => $id, 'user_id' => $request->user()->id, 'acervo_id' => $aid, 'path' => $path, 'mime' => $mime, 'size' => $f->getSize(), 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['id' => $id, 'url' => url('/_blob/'.$id), 'sizeBytes' => $f->getSize(), 'contentType' => $mime]);
    }

    public function show(Request $request, string $id)
    {
        $a = DB::table('assets')->where('id', $id)->first();
        abort_unless($a, 404);
        $disk = Storage::disk(config('acervo.disk'));
        // <img>: redireciona para o bucket (rápido); fetch() da página: entrega daqui mesmo (sem depender de CORS)
        if ($request->header('Sec-Fetch-Dest') === 'image') {
            try {
                return redirect($disk->temporaryUrl($a->path, now()->addMinutes(20)))->header('Cache-Control', 'private, max-age=900');
            } catch (\Throwable) {
                // disco local: cai para a entrega direta
            }
        }
        abort_unless($disk->exists($a->path), 404);

        return response()->stream(function () use ($disk, $a) {
            $in = $disk->readStream($a->path);
            fpassthru($in);
            is_resource($in) && fclose($in);
        }, 200, ['Content-Type' => $a->mime ?: 'application/octet-stream', 'Cache-Control' => 'private, max-age=86400', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function destroy(Request $request, string $id)
    {
        $a = DB::table('assets')->where('id', $id)->first();
        if (! $a) {
            return response()->json(['ok' => true]);
        }
        $store = new PageStore($request->user());
        $ok = $a->user_id === $request->user()->id || ($a->acervo_id && $store->canWrite($a->acervo_id));
        abort_unless($ok, 403);
        Storage::disk(config('acervo.disk'))->delete($a->path);
        DB::table('assets')->where('id', $id)->delete();

        return response()->json(['ok' => true]);
    }
}
