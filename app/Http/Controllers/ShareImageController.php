<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Services\ShareCard;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Cartão de compartilhamento da peça (JPEG; PNG quando pedido para copiar). */
class ShareImageController extends Controller
{
    public function __invoke(Request $request, Item $item)
    {
        $jpg = ShareCard::image($item->load('tags'), $request->integer('foto'), $request->boolean('tombo', true), $request->boolean('tags', true));
        $name = Str::slug(($item->code ?: 'peca').'-'.$item->title, '-').'.jpg';

        if ($request->boolean('png')) {
            $im = imagecreatefromstring($jpg);
            ob_start();
            imagepng($im);
            imagedestroy($im);

            return response(ob_get_clean(), 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, max-age=60']);
        }

        return response($jpg, 200, array_filter([
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=60',
            'Content-Disposition' => $request->boolean('download') ? 'attachment; filename="'.$name.'"' : null,
        ]));
    }
}
