@extends('public.layout')
@section('title', $item->title.' · '.$acervo->name)
@section('brand', $acervo->name)
@section('brandSub', 'Vitrine')
@section('home', route('showcase', $acervo->slug))
@section('content')
<a class="pb-back" href="{{ route('showcase', $acervo->slug) }}">← Voltar à vitrine</a>
<div class="pb-detail">
    <div class="pb-photos">
        @forelse ($item->photos as $p)
            <img src="{{ $p->url() }}" alt="{{ $item->title }}" loading="lazy">
        @empty
            <div class="pb-nophoto"></div>
        @endforelse
    </div>
    <div class="pb-info">
        <span class="pb-code">{{ $item->code }}</span>
        <h1>{{ $item->title }}</h1>
        <dl>
            @foreach (['Época' => $item->era, 'Origem' => $item->origin, 'Material' => $item->material, 'Dimensões' => $item->dimensions, 'Estado' => $item->condition, 'Categoria' => $item->category] as $k => $v)
                @if ($v)<div><dt>{{ $k }}</dt><dd>{{ $v }}</dd></div>@endif
            @endforeach
        </dl>
        @if ($item->description)<p>{{ $item->description }}</p>@endif
        @if ($item->tags->isNotEmpty())<div class="pb-chips">@foreach ($item->tags as $t)<span class="pb-chip">#{{ $t->name }}</span>@endforeach</div>@endif
        @if ($item->status === 'sold')<span class="pb-chip pb-sold">Vendida</span>@elseif ($item->status === 'loaned')<span class="pb-chip pb-loan">Emprestada no momento</span>@endif
    </div>
</div>
@endsection
