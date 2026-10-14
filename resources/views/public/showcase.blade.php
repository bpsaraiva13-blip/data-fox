@extends('public.layout')
@section('title', $acervo->name.' · Vitrine')
@section('brand', $acervo->name)
@section('brandSub', 'Vitrine')
@section('home', route('showcase', $acervo->slug))
@section('content')
<section class="pb-hero">
    <p class="pb-eyebrow">Acervo de antiguidades e raridades</p>
    <h1>{{ $acervo->name }}</h1>
    <p class="pb-muted">{{ $acervo->showcase_intro ?: $items->total().' peça(s) em exposição.' }}</p>
</section>
@if ($collections->isNotEmpty())
    <nav class="pb-chips">
        <a href="{{ route('showcase', $acervo->slug) }}" class="pb-chip {{ request('colecao') ? '' : 'on' }}">Todas</a>
        @foreach ($collections as $c)
            <a href="{{ route('showcase', [$acervo->slug, 'colecao' => $c->id]) }}" class="pb-chip {{ (int) request('colecao') === $c->id ? 'on' : '' }}">{{ $c->name }}</a>
        @endforeach
    </nav>
@endif
@if ($items->isEmpty())
    <div class="pb-card pb-narrow"><h2>A vitrine está vazia</h2><p class="pb-muted">Nenhuma peça foi selecionada para exposição ainda.</p></div>
@else
    <div class="pb-grid">
        @foreach ($items as $i => $it)
            <a class="pb-item" href="{{ route('showcase.item', [$acervo->slug, $it->id]) }}" style="animation-delay: {{ min($i, 14) * 40 }}ms">
                @if ($it->cover)<img src="{{ $it->cover->url() }}" alt="{{ $it->title }}" loading="lazy">@endif
                <span><b>{{ $it->title }}</b><small>{{ collect([$it->era, $it->origin])->filter()->implode(' · ') }}</small></span>
            </a>
        @endforeach
    </div>
    {{ $items->links() }}
@endif
@endsection
