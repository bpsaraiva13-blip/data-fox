<?php

namespace App\Services;

use App\Filament\Resources\Boxes\BoxResource;
use App\Filament\Resources\Items\ItemResource;
use App\Models\Box;
use App\Models\Item;
use Barryvdh\DomPDF\Facade\Pdf;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Ficha, catálogo e etiquetas com QR code (dompdf). */
class PdfMaker
{
    public static function qr(string $text, int $scale = 8): string
    {
        return (new QRCode(new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'scale' => $scale,
            'outputBase64' => true,
            'addQuietzone' => true,
            'quietzoneSize' => 1,
        ])))->render($text);
    }

    /** Imagem do disco em data URI, reduzida para o PDF não ficar pesado. */
    public static function image(?string $path, int $max = 1000): ?string
    {
        if (! $path) {
            return null;
        }
        try {
            $bin = Storage::disk(config('acervo.disk'))->get($path);

            return 'data:image/jpeg;base64,'.base64_encode(ImageSignature::thumbnail($bin, $max));
        } catch (Throwable) {
            return null;
        }
    }

    public static function itemUrl(Item $item): string
    {
        return ItemResource::getUrl('view', ['record' => $item], tenant: $item->acervo);
    }

    public static function boxUrl(Box $box): string
    {
        return BoxResource::itemsUrl($box, $box->acervo);
    }

    /** @param  Collection<int, Item>  $items */
    public static function catalog(Collection $items, string $title)
    {
        $items->load(['photos', 'tags', 'box', 'creator', 'acervo']);
        $pages = $items->map(fn (Item $i) => self::fichaData($i));

        return Pdf::loadView('pdf.catalog', ['title' => $title, 'pages' => $pages, 'acervo' => $items->first()?->acervo])->setPaper('a4');
    }

    public static function fichaData(Item $i): array
    {
        $photos = $i->photos->values();

        return [
            'item' => $i,
            'cover' => self::image($photos->first()?->path, 1100),
            'thumbs' => $photos->slice(1, 5)->map(fn ($p) => self::image($p->thumb_path ?: $p->path, 360))->filter()->values(),
            'qr' => self::qr(self::itemUrl($i), 5),
        ];
    }

    /** @param  Collection<int, Item>  $items */
    public static function labels(Collection $items)
    {
        $items->load('box', 'acervo');
        $labels = $items->map(fn (Item $i) => ['item' => $i, 'qr' => self::qr(self::itemUrl($i), 5)]);

        return Pdf::loadView('pdf.labels', ['labels' => $labels])->setPaper('a4');
    }

    public static function boxLabel(Box $box)
    {
        return Pdf::loadView('pdf.box-label', ['box' => $box->load('acervo'), 'qr' => self::qr(self::boxUrl($box), 8)])->setPaper('a4');
    }
}
