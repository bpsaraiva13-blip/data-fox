<?php

namespace App\Support;

use App\Services\ImageSignature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Importação de pastas copiadas para o bucket (ex.: do MEGA com o rclone):
 *
 *   <prefixo>/<ACERVO>/<CAIXA>/[<SUBPASTA>/…]<foto>
 *
 * Cada pasta de 1º nível é um acervo, cada pasta de 2º nível uma caixa, e cada foto uma peça.
 * A subpasta logo abaixo da caixa vira a categoria (e o título provisório); as mais fundas viram etiquetas.
 * Ids determinísticos (pelo caminho do arquivo): repetir a importação não duplica nada.
 */
class FolderImport
{
    public const IMAGES = ['jpg', 'jpeg', 'png', 'webp'];

    /** Lê o prefixo e devolve [acervo => [caixa => [arquivos]]] + os arquivos que não são fotos. */
    public static function scan(string $prefix, ?array $only = null): array
    {
        $disk = Storage::disk(config('acervo.disk'));
        $prefix = trim($prefix, '/');
        $tree = [];
        $skipped = [];
        foreach ($disk->allFiles($prefix) as $path) {
            $rel = substr($path, strlen($prefix) + 1);
            $parts = explode('/', $rel);
            if (count($parts) < 2) {
                $skipped[] = $rel;

                continue;
            }
            $acervo = $parts[0];
            if ($only && ! in_array(self::key($acervo), array_map([self::class, 'key'], $only), true)) {
                continue;
            }
            $ext = Str::lower(pathinfo($rel, PATHINFO_EXTENSION));
            if (! in_array($ext, self::IMAGES, true)) {
                $skipped[] = $rel;

                continue;
            }
            $box = count($parts) >= 3 ? $parts[1] : '';   // foto solta na raiz do acervo: sem caixa
            $tree[$acervo][$box][] = $path;
        }
        ksort($tree, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($tree as &$boxes) {
            ksort($boxes, SORT_NATURAL | SORT_FLAG_CASE);
            foreach ($boxes as &$files) {
                sort($files, SORT_NATURAL | SORT_FLAG_CASE);
            }
        }

        return [$tree, $skipped];
    }

    public static function key(string $s): string
    {
        return Str::lower(Str::ascii(trim($s)));
    }

    /** "POTES MARGARINA" → "Potes margarina" (mantém como está o que já tem minúsculas). */
    public static function label(string $s): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s));

        return $s === mb_strtoupper($s) ? Str::ucfirst(mb_strtolower($s)) : $s;
    }

    /** "03 e 04 - GARRAFAS" → "Garrafas" (o nome da caixa sem a numeração). */
    public static function boxLabel(string $box): string
    {
        return trim(preg_replace('/^\s*\d+(?:\s*(?:e|a|,|\/)\s*\d+)*\s*[-–.]?\s*/iu', '', $box)) ?: $box;
    }

    public static function docId(string $kind, string $path): string
    {
        return 'm'.$kind[0].substr(sha1($path), 0, 18);
    }

    /** Categoria, etiquetas e título provisório a partir das subpastas entre a caixa e a foto. */
    public static function describe(string $prefix, string $path): array
    {
        $parts = explode('/', substr($path, strlen(trim($prefix, '/')) + 1));
        $mid = array_slice($parts, 2, -1);
        $category = $mid ? self::label($mid[0]) : '';
        $tags = array_values(array_unique(array_map(fn ($t) => mb_strtolower(self::label($t)), array_slice($mid, 1))));
        $title = $category ?: (count($parts) >= 3 ? self::label(self::boxLabel($parts[1])) : '') ?: 'Sem título';

        return [$category, $tags, $title, end($parts)];
    }

    /** Importa uma foto como peça. Devolve false se já existia. */
    public static function importPhoto(int $aid, string $prefix, string $path, ?string $boxId, string $code, string $batch, ?string $ownerUid): bool
    {
        $itemId = self::docId('item', $path);
        if (DB::table('docs')->where('acervo_id', $aid)->where('col', 'items')->where('doc_id', $itemId)->exists()) {
            return false;
        }
        $disk = Storage::disk(config('acervo.disk'));
        $bin = $disk->get($path);
        if ($bin === null || $bin === '') {
            throw new \RuntimeException("Arquivo vazio: {$path}");
        }
        $im = @imagecreatefromstring($bin);
        if (! $im) {
            throw new \RuntimeException("Imagem que não abre: {$path}");
        }
        $im = self::orient($im, $bin);
        $full = self::jpeg($im, 1800, 86);
        $thumb = self::jpeg($im, 520, 80);
        $w = imagesx($im);
        $h = imagesy($im);
        imagedestroy($im);
        $sig = ImageSignature::fromBinary($thumb);

        $fid = self::docId('full', $path);
        $tid = self::docId('thumb', $path);
        foreach ([[$fid, $full], [$tid, $thumb]] as [$id, $data]) {
            if (! DB::table('assets')->where('id', $id)->exists()) {
                $p = 'acervo/assets/'.substr(md5($id), 0, 2).'/'.$id;
                $disk->put($p, $data, ['ContentType' => 'image/jpeg']);
                DB::table('assets')->insertOrIgnore(['id' => $id, 'acervo_id' => $aid, 'path' => $p, 'mime' => 'image/jpeg', 'size' => strlen($data), 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        [$category, $tags, $title, $file] = self::describe($prefix, $path);
        $now = now()->toIso8601String();
        $s = min(1, 1800 / max($w, $h));
        $doc = [
            'title' => $title, 'category' => $category, 'era' => '', 'origin' => '', 'material' => '', 'dimensions' => '', 'condition' => '',
            'value' => null, 'description' => '', 'provenance' => '', 'acquiredAt' => '', 'notes' => 'Importado do MEGA: '.$file, 'tags' => $tags,
            'boxId' => $boxId, 'status' => 'available', 'code' => $code, 'batch' => $batch,
            'photos' => [['id' => $fid, 'thumb' => $tid, 'hash' => $sig['hash'], 'color' => $sig['color'], 'w' => (int) round($w * $s), 'h' => (int) round($h * $s)]],
            'ai' => null,
            // vai para Pendências → "Reprocessar com IA": a IA preenche a ficha a partir da foto
            'aiFail' => ['at' => $now, 'error' => 'Importado do MEGA, ainda sem análise', 'tries' => 0, 'titleAuto' => true, 'hint' => $category],
            'createdAt' => $now, 'createdBy' => $ownerUid, 'updatedAt' => $now, 'updatedBy' => $ownerUid,
        ];
        DB::table('docs')->insertOrIgnore(['acervo_id' => $aid, 'col' => 'items', 'doc_id' => $itemId, 'data' => json_encode($doc, JSON_UNESCAPED_UNICODE), 'rev' => PageStore::nextRev(), 'created_at' => now(), 'updated_at' => now()]);

        return true;
    }

    /** Gira conforme o EXIF (fotos de celular "deitadas"). */
    private static function orient(\GdImage $im, string $bin): \GdImage
    {
        if (! function_exists('exif_read_data') || ! str_starts_with($bin, "\xFF\xD8")) {
            return $im;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode(substr($bin, 0, 262144)));
        $o = (int) ($exif['Orientation'] ?? 1);
        $r = match ($o) { 3 => imagerotate($im, 180, 0), 6 => imagerotate($im, -90, 0), 8 => imagerotate($im, 90, 0), default => null };
        if ($r) {
            imagedestroy($im);

            return $r;
        }

        return $im;
    }

    private static function jpeg(\GdImage $im, int $max, int $q): string
    {
        $w = imagesx($im);
        $h = imagesy($im);
        $s = min(1, $max / max($w, $h));
        $t = $s < 1 ? imagescale($im, max(1, (int) round($w * $s)), max(1, (int) round($h * $s)), IMG_BICUBIC) : $im;
        ob_start();
        imageinterlace($t, true);
        imagejpeg($t, null, $q);
        $out = (string) ob_get_clean();
        if ($t !== $im) {
            imagedestroy($t);
        }

        return $out;
    }
}
