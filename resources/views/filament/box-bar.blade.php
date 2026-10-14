@php
    $max = once(fn () => max(1, (int) \App\Models\Item::query()->whereNotNull('box_id')->selectRaw('count(*) c')->groupBy('box_id')->orderByDesc('c')->value('c')));
    $pct = round(($getState() ?? 0) / $max * 100);
@endphp
<div class="df-box-bar" style="--c: {{ $getRecord()->color ?: '#1F4D36' }}"><i style="width: {{ $pct }}%"></i></div>
