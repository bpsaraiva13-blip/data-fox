@php($thumbs = $getRecord()->items()->with('cover')->limit(3)->get()->map(fn ($i) => $i->cover?->thumbUrl())->filter()->values())
<div class="df-mosaic">
    @foreach ($thumbs as $t)<img src="{{ $t }}" alt="" loading="lazy">@endforeach
</div>
