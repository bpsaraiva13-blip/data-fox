<?php

namespace App\Http\Controllers;

use App\Models\Acervo;
use App\Models\Item;
use App\Models\ItemCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Vitrine: peças marcadas para exposição, sem caixa, local, valor ou observações internas. */
class ShowcaseController extends Controller
{
    private function acervo(string $slug): Acervo
    {
        $acervo = Acervo::where('slug', $slug)->firstOrFail();
        if (! $acervo->showcase_public) {
            abort_unless(Auth::check() && Auth::user()->roleIn($acervo), 404);
        }

        return $acervo;
    }

    public function index(Request $request, string $slug)
    {
        $acervo = $this->acervo($slug);
        $items = Item::withoutGlobalScope('acervo')->where('acervo_id', $acervo->id)->where('showcase', true)
            ->with(['cover', 'tags'])->latest()
            ->when($request->integer('colecao'), fn ($q, $c) => $q->whereHas('collections', fn ($w) => $w->whereKey($c)))
            ->paginate(48)->withQueryString();
        $collections = ItemCollection::withoutGlobalScope('acervo')->where('acervo_id', $acervo->id)
            ->whereHas('items', fn ($q) => $q->where('showcase', true))->orderBy('name')->get();

        return view('public.showcase', compact('acervo', 'items', 'collections'));
    }

    public function show(string $slug, int $item)
    {
        $acervo = $this->acervo($slug);
        $item = Item::withoutGlobalScope('acervo')->where('acervo_id', $acervo->id)->where('showcase', true)->with(['photos', 'tags'])->findOrFail($item);

        return view('public.showcase-item', compact('acervo', 'item'));
    }
}
