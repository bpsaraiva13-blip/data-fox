<?php

namespace App\Services;

use RuntimeException;

/**
 * Assinatura visual de uma imagem: dHash de 64 bits + cor média.
 * Mesmo algoritmo do protótipo web, então os valores são comparáveis.
 */
class ImageSignature
{
    /** @return array{hash:string,color:array{int,int,int},width:int,height:int} */
    public static function fromBinary(string $bytes): array
    {
        $src = @imagecreatefromstring($bytes);
        if (! $src) {
            throw new RuntimeException('Imagem inválida ou formato não suportado.');
        }

        $w = imagesx($src);
        $h = imagesy($src);

        // dHash: 9x8 em tons de cinza, compara pixels vizinhos na horizontal
        $small = imagecreatetruecolor(9, 8);
        imagecopyresampled($small, $src, 0, 0, 0, 0, 9, 8, $w, $h);
        $bits = '';
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $bits .= self::gray($small, $x, $y) > self::gray($small, $x + 1, $y) ? '1' : '0';
            }
        }
        $hash = '';
        foreach (str_split($bits, 4) as $nibble) {
            $hash .= dechex(bindec($nibble));
        }

        // Cor média em 4x4
        $tiny = imagecreatetruecolor(4, 4);
        imagecopyresampled($tiny, $src, 0, 0, 0, 0, 4, 4, $w, $h);
        $r = $g = $b = 0;
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $c = imagecolorat($tiny, $x, $y);
                $r += ($c >> 16) & 0xFF;
                $g += ($c >> 8) & 0xFF;
                $b += $c & 0xFF;
            }
        }

        imagedestroy($small);
        imagedestroy($tiny);
        imagedestroy($src);

        return ['hash' => $hash, 'color' => [intdiv($r, 16), intdiv($g, 16), intdiv($b, 16)], 'width' => $w, 'height' => $h];
    }

    public static function hamming(?string $a, ?string $b): int
    {
        if (! $a || ! $b || strlen($a) !== strlen($b)) {
            return 64;
        }
        $n = 0;
        for ($i = 0; $i < strlen($a); $i++) {
            $n += substr_count(decbin(hexdec($a[$i]) ^ hexdec($b[$i])), '1');
        }

        return $n;
    }

    /** Similaridade 0–1: 78% forma (dHash) + 22% cor. */
    public static function similarity(array $sig, ?string $hash, ?array $color): float
    {
        $shape = 1 - self::hamming($sig['hash'], $hash) / 64;
        $col = $color
            ? 1 - sqrt(($sig['color'][0] - $color[0]) ** 2 + ($sig['color'][1] - $color[1]) ** 2 + ($sig['color'][2] - $color[2]) ** 2) / 441.7
            : 0.5;

        return max(0, min(1, 0.78 * $shape + 0.22 * $col));
    }

    /** Gera miniatura JPEG respeitando a orientação EXIF. */
    public static function thumbnail(string $bytes, int $max): string
    {
        $src = imagecreatefromstring($bytes);
        $src = self::fixOrientation($src, $bytes);
        $w = imagesx($src);
        $h = imagesy($src);
        $k = min(1, $max / max($w, $h));
        $tw = max(1, (int) round($w * $k));
        $th = max(1, (int) round($h * $k));
        $dst = imagecreatetruecolor($tw, $th);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
        ob_start();
        imagejpeg($dst, null, 82);
        $out = ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);

        return $out;
    }

    private static function gray($img, int $x, int $y): float
    {
        $c = imagecolorat($img, $x, $y);

        return (($c >> 16) & 0xFF) * 0.299 + (($c >> 8) & 0xFF) * 0.587 + ($c & 0xFF) * 0.114;
    }

    private static function fixOrientation($img, string $bytes)
    {
        if (! function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $angle = match ($exif['Orientation'] ?? 1) {
            3 => 180, 6 => -90, 8 => 90, default => 0,
        };

        return $angle ? imagerotate($img, $angle, 0) : $img;
    }
}
