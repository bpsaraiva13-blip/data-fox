<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Support\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Marca do sistema: o logo (ícone do site) e os textos, editados por administradores do sistema. */
class SiteController extends Controller
{
    /** Logo / ícone do site (público: aparece na tela de entrar e na aba do navegador). */
    public function icon()
    {
        $b = Site::brand();
        $disk = Storage::disk(config('acervo.disk'));
        if (! $b['logo'] || ! $disk->exists($b['logo'])) {
            return redirect(asset('favicon.svg'));
        }

        return response()->stream(function () use ($disk, $b) {
            $in = $disk->readStream($b['logo']);
            fpassthru($in);
            is_resource($in) && fclose($in);
        }, 200, ['Content-Type' => $b['logo_mime'] ?: 'image/png', 'Cache-Control' => 'public, max-age=604800', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function update(Request $request)
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Só administradores do sistema mudam a marca.');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'sub' => ['nullable', 'string', 'max:60'],
            'tagline' => ['nullable', 'string', 'max:160'],
            'logo' => ['nullable', 'file', 'max:2048', 'mimetypes:image/png,image/jpeg,image/webp'],
            'remove_logo' => ['nullable', 'boolean'],
        ], ['logo.mimetypes' => 'Use uma imagem PNG, JPG ou WebP.', 'logo.max' => 'A imagem pode ter até 2 MB.']);

        $b = Site::brand();
        $disk = Storage::disk(config('acervo.disk'));
        $new = ['name' => trim($data['name']), 'sub' => trim((string) ($data['sub'] ?? '')), 'tagline' => trim((string) ($data['tagline'] ?? ''))];
        if ($request->boolean('remove_logo') && $b['logo']) {
            $disk->delete($b['logo']);
            $new += ['logo' => null, 'logo_mime' => null, 'v' => $b['v'] + 1];
        }
        if ($f = $request->file('logo')) {
            if ($b['logo']) {
                $disk->delete($b['logo']);
            }
            $path = 'acervo/brand/logo-'.Str::lower(Str::random(8));
            $disk->put($path, file_get_contents($f->getRealPath()), ['ContentType' => $f->getMimeType()]);
            $new += ['logo' => $path, 'logo_mime' => $f->getMimeType(), 'v' => $b['v'] + 1];
        }
        Site::save($new);

        return response()->json(['brand' => Site::public()]);
    }
}
