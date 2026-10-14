<?php

namespace App\Services;

use App\Models\Item;
use Illuminate\Support\Facades\Storage;

/**
 * Gera o cartão de compartilhamento (1080×1350, formato do feed) e o texto
 * formatado para WhatsApp de uma peça.
 */
class ShareCard
{
    private const W = 1080;

    private const H = 1350;

    private const P = 56;

    public static function text(Item $item, array $o = []): string
    {
        $o += ['code' => true, 'desc' => true, 'tags' => true, 'box' => false, 'value' => false];
        $l = ['*'.($item->title ?: 'Sem título').'*'];
        if ($o['code'] && $item->code) {
            $l[] = "_Nº de tombo {$item->code}_";
        }
        $l[] = '';
        $rows = ['Categoria' => $item->category, 'Época' => $item->era, 'Origem' => $item->origin, 'Material' => $item->material,
            'Dimensões' => $item->dimensions, 'Estado' => $item->condition, 'Procedência' => $item->provenance];
        if ($o['box'] && $item->box) {
            $rows['Caixa'] = $item->box->name.($item->box->location ? ' · '.$item->box->location : '');
        }
        if ($o['value'] && $item->value !== null) {
            $rows['Valor estimado'] = 'R$ '.number_format((float) $item->value, 0, ',', '.');
        }
        foreach ($rows as $k => $v) {
            if (filled($v)) {
                $l[] = "• *{$k}:* {$v}";
            }
        }
        if ($o['desc'] && filled($item->description)) {
            array_push($l, '', trim($item->description));
        }
        if ($o['tags'] && $item->tags->isNotEmpty()) {
            array_push($l, '', $item->tags->map(fn ($t) => '#'.$t->name)->implode(' '));
        }
        array_push($l, '', '_Data Fox · Acervo e raridades_');

        return preg_replace("/\n{3,}/", "\n\n", implode("\n", $l));
    }

    /** JPEG do cartão. $photoIndex escolhe a foto da peça. */
    public static function image(Item $item, int $photoIndex = 0, bool $showCode = true, bool $showTags = true): string
    {
        $font = resource_path('fonts/PlusJakartaSans.ttf');
        $im = imagecreatetruecolor(self::W, self::H);
        imagealphablending($im, true);
        $c = fn (string $hex) => imagecolorallocate($im, hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)));
        [$bg, $card, $ink, $mut, $green, $mint, $sage] = [$c('#F3F2EC'), $c('#FFFFFF'), $c('#15211A'), $c('#6D756F'), $c('#1F4D36'), $c('#DCEAD6'), $c('#8DB898')];
        imagefill($im, 0, 0, $bg);

        // Foto (recorte "cover" com cantos arredondados)
        $P = self::P;
        $PH = 620;
        $pw = self::W - 2 * $P;
        $photo = $item->photos()->skip($photoIndex)->first() ?? $item->photos()->first();
        $layer = imagecreatetruecolor($pw, $PH);
        imagefill($layer, 0, 0, $mint);
        if ($photo && ($bin = Storage::disk(config('acervo.disk'))->get($photo->path)) && ($src = @imagecreatefromstring($bin))) {
            $k = max($pw / imagesx($src), $PH / imagesy($src));
            $dw = (int) (imagesx($src) * $k);
            $dh = (int) (imagesy($src) * $k);
            imagecopyresampled($layer, $src, (int) (($pw - $dw) / 2), (int) (($PH - $dh) / 2), 0, 0, $dw, $dh, imagesx($src), imagesy($src));
            imagedestroy($src);
        } else {
            $ch = mb_strtoupper(mb_substr($item->title ?: '?', 0, 1));
            self::text_($layer, 200, $font, $green, $ch, (int) ($pw / 2 - 80), (int) ($PH / 2 + 90), true);
        }
        self::roundedCopy($im, $layer, $P, $P, $pw, $PH, 44, $bg);
        imagedestroy($layer);

        // Etiqueta de tombo
        if ($showCode && $item->code) {
            $box = imagettfbbox(24, 0, $font, $item->code);
            $tw = $box[2] - $box[0];
            self::roundRect($im, $P + 28, $P + 28, $tw + 80, 58, 29, $green);
            imagefilledellipse($im, $P + 58, $P + 57, 14, 14, $sage);
            self::text_($im, 24, $font, $mint, $item->code, $P + 76, $P + 67, true);
        }

        // Cartão de informações
        $cy = $P + $PH + 28;
        self::roundRect($im, $P, $cy, $pw, self::H - $cy - $P, 40, $card);
        $ix = $P + 44;
        $iw = $pw - 88;
        $y = $cy + 80;
        foreach (self::wrap($item->title ?: 'Sem título', $font, 44, $iw, 2) as $line) {
            self::text_($im, 44, $font, $ink, $line, $ix, $y, true);
            $y += 62;
        }
        $fields = collect(['ÉPOCA' => $item->era, 'ORIGEM' => $item->origin, 'MATERIAL' => $item->material, 'ESTADO' => $item->condition, 'CATEGORIA' => $item->category])
            ->filter()->take(4);
        $col = $iw / 2;
        foreach ($fields->chunk(2) as $pair) {
            $y += 18;
            $j = 0;
            foreach ($pair as $label => $value) {
                $fx = (int) ($ix + $j++ * $col);
                self::text_($im, 17, $font, $mut, $label, $fx, $y);
                self::text_($im, 25, $font, $ink, self::wrap($value, $font, 25, (int) $col - 24, 1)[0], $fx, $y + 42, true);
            }
            $y += 84;
        }

        $footY = self::H - $P - 46;
        if ($showTags && $item->tags->isNotEmpty() && $y + 90 < $footY) {
            $y += 28;
            $tx = $ix;
            foreach ($item->tags->take(6) as $tag) {
                $s = '#'.$tag->name;
                $b = imagettfbbox(19, 0, $font, $s);
                $w = $b[2] - $b[0] + 36;
                if ($tx + $w > $ix + $iw) {
                    break;
                }
                self::roundRect($im, $tx, $y, $w, 46, 23, $mint);
                self::text_($im, 19, $font, $green, $s, $tx + 18, $y + 31, true);
                $tx += $w + 10;
            }
        }

        // Rodapé com a marca
        self::roundRect($im, $ix, $footY - 30, 52, 52, 16, $green);
        self::text_($im, 17, $font, $mint, 'DF', $ix + 11, $footY + 4, true);
        self::text_($im, 21, $font, $ink, 'Data Fox', $ix + 68, $footY - 6, true);
        self::text_($im, 16, $font, $mut, 'Acervo e raridades', $ix + 68, $footY + 20);

        ob_start();
        imagejpeg($im, null, 88);
        imagedestroy($im);

        return ob_get_clean();
    }

    /** Texto; "bold" desenha duas vezes com 1px de deslocamento (fonte variável no GD). */
    private static function text_($im, int $size, string $font, int $color, string $text, int $x, int $y, bool $bold = false): void
    {
        imagettftext($im, $size, 0, $x, $y, $color, $font, $text);
        if ($bold) {
            imagettftext($im, $size, 0, $x + 1, $y, $color, $font, $text);
        }
    }

    private static function wrap(string $text, string $font, int $size, int $max, int $lines): array
    {
        $out = [];
        $cur = '';
        $words = preg_split('/\s+/', trim($text));
        foreach ($words as $w) {
            $try = $cur === '' ? $w : "{$cur} {$w}";
            $b = imagettfbbox($size, 0, $font, $try);
            if ($b[2] - $b[0] <= $max) {
                $cur = $try;

                continue;
            }
            if ($cur !== '') {
                $out[] = $cur;
            }
            $cur = $w;
            if (count($out) === $lines) {
                break;
            }
        }
        if ($cur !== '' && count($out) < $lines) {
            $out[] = $cur;
        }
        if (count($out) === $lines && implode(' ', $out) !== implode(' ', $words)) {
            $last = $out[$lines - 1];
            while ($last !== '' && (imagettfbbox($size, 0, $font, $last.'…')[2] - imagettfbbox($size, 0, $font, $last.'…')[0]) > $max) {
                $last = mb_substr($last, 0, -1);
            }
            $out[$lines - 1] = rtrim($last).'…';
        }

        return $out ?: [''];
    }

    private static function roundRect($im, int $x, int $y, int $w, int $h, int $r, int $color): void
    {
        imagefilledrectangle($im, $x + $r, $y, $x + $w - $r, $y + $h, $color);
        imagefilledrectangle($im, $x, $y + $r, $x + $w, $y + $h - $r, $color);
        foreach ([[$x + $r, $y + $r], [$x + $w - $r, $y + $r], [$x + $r, $y + $h - $r], [$x + $w - $r, $y + $h - $r]] as [$cx, $cy]) {
            imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $color);
        }
    }

    /** Copia $src para $dst com cantos arredondados (pinta os cantos com a cor de fundo). */
    private static function roundedCopy($dst, $src, int $x, int $y, int $w, int $h, int $r, int $bg): void
    {
        imagecopy($dst, $src, $x, $y, 0, 0, $w, $h);
        $mask = imagecreatetruecolor($r * 2, $r * 2);
        foreach ([[0, 0], [$w - $r, 0], [0, $h - $r], [$w - $r, $h - $r]] as [$ox, $oy]) {
            for ($i = 0; $i < $r; $i++) {
                for ($j = 0; $j < $r; $j++) {
                    $cx = $ox === 0 ? $r : 0;
                    $cy = $oy === 0 ? $r : 0;
                    if (($i - $cx) ** 2 + ($j - $cy) ** 2 > $r * $r) {
                        imagesetpixel($dst, $x + $ox + $i, $y + $oy + $j, $bg);
                    }
                }
            }
        }
        imagedestroy($mask);
    }
}
