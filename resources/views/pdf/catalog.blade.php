<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8">@include('pdf._style')</head>
<body>
@if ($pages->count() > 1)
    <div class="page" style="background:#F3F2EC;padding-top:30mm">
        <div style="width:40mm;height:40mm;border-radius:8mm;background:#1F4D36;color:#DCEAD6;text-align:center;line-height:40mm;font-size:20pt;font-weight:bold">DF</div>
        <h1 style="font-size:30pt;margin-top:30mm">{{ $title }}</h1>
        <p style="color:#6D756F;font-size:12pt">{{ $pages->count() }} peça(s) · {{ $acervo?->name }} · {{ now()->format('d/m/Y') }}</p>
    </div>
@endif
@foreach ($pages as $p)
    @php($i = $p['item'])
    @php($st = ['loaned' => 'Emprestada', 'sold' => 'Vendida'][$i->status] ?? 'Disponível')
    <div class="page">
        <div class="band"><b>DATA FOX</b> &nbsp; Ficha de catalogação · {{ $i->acervo?->name }} <span class="code">{{ $i->code }}</span></div>
        @if ($p['cover'])<div class="cover"><img src="{{ $p['cover'] }}"></div>@endif
        @if ($p['thumbs']->isNotEmpty())<div class="thumbs">@foreach ($p['thumbs'] as $t)<img src="{{ $t }}">@endforeach</div>@endif
        <h1>{{ $i->title }}</h1>
        @php($fields = collect(['Categoria' => $i->category, 'Época' => $i->era, 'Origem' => $i->origin, 'Material' => $i->material, 'Dimensões' => $i->dimensions, 'Estado' => $i->condition,
            'Caixa' => $i->box ? $i->box->name.($i->box->location ? ' · '.$i->box->location : '') : 'Sem caixa', 'Situação' => $st.($i->status_person ? ' · '.$i->status_person : ''),
            'Procedência' => $i->provenance, 'Aquisição' => $i->acquired_at?->format('d/m/Y')])->filter())
        <table class="f">
            @foreach ($fields->chunk(2) as $row)
                <tr>@foreach ($row as $k => $v)<td><div class="lbl">{{ $k }}</div>{{ $v }}</td>@endforeach</tr>
            @endforeach
        </table>
        @if ($i->description)<div class="lbl" style="margin-top:3mm">Descrição</div><div class="desc" style="margin-top:1mm;margin-right:32mm">{{ \Illuminate\Support\Str::limit($i->description, 1400) }}</div>@endif
        @if ($i->tags->isNotEmpty())<div class="tags">{{ $i->tags->map(fn ($t) => '#'.$t->name)->implode('  ') }}</div>@endif
        <div class="qr"><img src="{{ $p['qr'] }}"><br>Abrir no Data Fox</div>
        <div class="foot">Cadastrado por {{ $i->creator?->name ?? '—' }} em {{ $i->created_at?->format('d/m/Y H:i') }} · gerado em {{ now()->format('d/m/Y H:i') }} por {{ auth()->user()?->name }}</div>
    </div>
@endforeach
</body></html>
