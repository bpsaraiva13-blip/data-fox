<?php

namespace App\Http\Controllers;

use App\Models\AccessLog;
use App\Models\Box;
use App\Models\Item;
use App\Models\ItemAttachment;
use App\Services\PdfMaker;
use App\Support\ItemAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PdfController extends Controller
{
    private function items(Request $request)
    {
        $ids = array_filter(array_map('intval', explode(',', (string) $request->query('ids'))));
        abort_if(! $ids || count($ids) > 300, 422, 'Selecione de 1 a 300 peças.');
        $items = Item::withoutGlobalScope('acervo')->whereIn('id', $ids)->orderBy('code')->get()
            ->filter(fn (Item $i) => ItemAccess::canSee($request->user(), $i))->values();
        abort_if($items->isEmpty(), 404);

        return $items;
    }

    public function ficha(Request $request, int $item)
    {
        $item = Item::withoutGlobalScope('acervo')->findOrFail($item);
        abort_unless(ItemAccess::canSee($request->user(), $item), 404);
        AccessLog::log('pdf', $item);

        return PdfMaker::catalog(collect([$item]), $item->title)
            ->download('ficha-'.Str::slug($item->code.'-'.$item->title).'.pdf');
    }

    public function catalog(Request $request)
    {
        $items = $this->items($request);
        abort_if($items->count() > 150, 422, 'Gere catálogos de até 150 peças.');
        $title = (string) $request->query('titulo', 'Seleção de peças');
        AccessLog::log('catalog', null, $title.' ('.$items->count().' peças)', $items->first()->acervo_id);

        return PdfMaker::catalog($items, $title)->download('catalogo-'.Str::slug($title).'-'.now()->format('Y-m-d').'.pdf');
    }

    public function labels(Request $request)
    {
        $items = $this->items($request);
        AccessLog::log('labels', null, $items->count().' etiqueta(s)', $items->first()->acervo_id);

        return PdfMaker::labels($items)->download('etiquetas-'.$items->count().'-pecas.pdf');
    }

    public function boxLabel(Request $request, int $box)
    {
        $box = Box::withoutGlobalScope('acervo')->findOrFail($box);
        abort_unless(ItemAccess::canSeeBox($request->user(), $box), 404);
        AccessLog::log('labels', null, 'Etiqueta da caixa '.$box->name, $box->acervo_id);

        return PdfMaker::boxLabel($box)->download('etiqueta-caixa-'.Str::slug($box->name).'.pdf');
    }

    public function attachment(Request $request, ItemAttachment $attachment)
    {
        $item = Item::withoutGlobalScope('acervo')->findOrFail($attachment->item_id);
        abort_unless(ItemAccess::canSee($request->user(), $item), 404);
        AccessLog::log('export', $item, 'Documento: '.$attachment->name);
        $disk = Storage::disk(config('acervo.disk'));
        try {
            return redirect($disk->temporaryUrl($attachment->path, now()->addMinutes(10), [
                'ResponseContentDisposition' => 'inline; filename="'.Str::ascii($attachment->name).'"',
            ]));
        } catch (\Throwable) {
            return $disk->download($attachment->path, $attachment->name);
        }
    }
}
