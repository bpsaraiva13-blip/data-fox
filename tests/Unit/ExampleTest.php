<?php

namespace Tests\Unit;

use App\Services\ImageSignature;
use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    public function test_hamming_entre_assinaturas(): void
    {
        $this->assertSame(0, ImageSignature::hamming('ffffffffffffffff', 'ffffffffffffffff'));
        $this->assertSame(64, ImageSignature::hamming('0000000000000000', 'ffffffffffffffff'));
        $this->assertSame(64, ImageSignature::hamming(null, 'ffffffffffffffff'));
    }

    public function test_assinatura_de_imagem_gerada(): void
    {
        $im = imagecreatetruecolor(90, 80);
        imagefilledrectangle($im, 0, 0, 44, 79, imagecolorallocate($im, 255, 255, 255));
        ob_start();
        imagejpeg($im);
        $sig = ImageSignature::fromBinary(ob_get_clean());

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $sig['hash']);
        $this->assertCount(3, $sig['color']);
        $this->assertSame(1.0, round(ImageSignature::similarity($sig, $sig['hash'], $sig['color']), 3));
    }
}
