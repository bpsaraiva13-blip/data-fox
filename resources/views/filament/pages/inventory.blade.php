<x-filament-panels::page>
    @php($inv = $this->current())

    @if (! $inv)
        <x-filament::section>
            <x-slot name="heading">Começar uma conferência</x-slot>
            <x-slot name="description">Escolha a caixa que você está conferindo. Depois leia as etiquetas QR, digite o tombo ou toque nas peças da lista.</x-slot>
            <div class="df-row" style="gap:12px;flex-wrap:wrap">
                <select wire:model="boxId" class="df-input" style="max-width:360px">
                    <option value="">Escolha a caixa…</option>
                    @foreach ($this->boxes() as $b)
                        <option value="{{ $b->id }}">{{ $b->name }} ({{ $b->items_count }})</option>
                    @endforeach
                </select>
                <x-filament::button icon="heroicon-m-play" wire:click="start">Iniciar</x-filament::button>
            </div>
        </x-filament::section>

        @php($hist = $this->history())
        @if ($hist->isNotEmpty())
            <x-filament::section>
                <x-slot name="heading">Conferências anteriores</x-slot>
                <div class="df-queue">
                    @foreach ($hist as $h)
                        @php($s = $h->summary ?? [])
                        <div>
                            <b style="min-width:140px">{{ $h->box?->name ?? 'Caixa removida' }}</b>
                            <span class="df-pill {{ empty($s['missing']) ? 'df-pill-ok' : 'df-pill-w' }}">{{ $s['found'] ?? 0 }} / {{ $s['total'] ?? 0 }}</span>
                            @if (! empty($s['missing']))<span class="df-muted">{{ count($s['missing']) }} faltando</span>@endif
                            @if (! empty($s['extra']))<span class="df-muted">{{ count($s['extra']) }} de outras caixas</span>@endif
                            <span style="flex:1"></span>
                            <small class="df-muted">{{ $h->user?->name }} · {{ $h->finished_at?->format('d/m/Y H:i') }}</small>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif
    @else
        @php($items = $this->expected())
        @php($found = collect($inv->found ?? []))
        @php($n = $items->filter(fn ($i) => $found->contains($i->id))->count())
        @php($pct = $items->count() ? (int) round($n / $items->count() * 100) : 0)
        @php($extras = $this->extras())

        <div class="df-inv-head">
            <div class="df-big-ring" style="--p: {{ $pct }}"><span>{{ $pct }}%</span></div>
            <div>
                <h2 class="df-h3" style="margin:0">{{ $inv->box?->name }}</h2>
                <p class="df-muted" style="margin:4px 0 10px">{{ $n }} de {{ $items->count() }} encontradas · iniciada {{ $inv->created_at->diffForHumans() }}</p>
                <form wire:submit="scanCode" x-data x-init="$nextTick(() => $refs.code.focus())">
                    <input x-ref="code" wire:model="code" class="df-input" autocomplete="off" autofocus
                           placeholder="Leia a etiqueta ou digite o tombo e tecle Enter" x-on:blur="setTimeout(() => $refs.code.focus(), 400)">
                </form>
            </div>
            <div class="df-stack" style="gap:8px">
                <x-filament::button icon="heroicon-m-check-badge" wire:click="finish" wire:confirm="Concluir a conferência desta caixa?">Concluir</x-filament::button>
                <x-filament::button color="gray" size="sm" wire:click="cancel" wire:confirm="Descartar esta conferência?">Descartar</x-filament::button>
            </div>
        </div>

        @if ($extras->isNotEmpty())
            <x-filament::section>
                <x-slot name="heading">Achadas aqui, mas cadastradas em outra caixa</x-slot>
                <x-slot name="afterHeader">
                    <x-filament::button size="sm" color="warning" wire:click="moveExtras">Mover para {{ $inv->box?->name }}</x-filament::button>
                </x-slot>
                <div>
                    @foreach ($extras as $it)
                        <a class="df-inv-row" href="{{ $this->itemUrl($it) }}" wire:key="x-{{ $it->id }}">
                            <span>↪</span>
                            @if ($it->cover)<img src="{{ $it->cover->thumbUrl() }}" alt="">@else<span class="df-nph"></span>@endif
                            <span><b>{{ $it->title }}</b><br><small class="df-mono">{{ $it->code }}</small></span>
                            <span class="df-pill df-pill-w">{{ $it->box?->name ?? 'Sem caixa' }}</span>
                        </a>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        <div x-data="{ f: 'all' }" class="df-stack">
            <div class="df-tabs">
                <button type="button" :class="f === 'all' && 'is-on'" x-on:click="f = 'all'">Todas ({{ $items->count() }})</button>
                <button type="button" :class="f === 'miss' && 'is-on'" x-on:click="f = 'miss'">Faltando ({{ $items->count() - $n }})</button>
                <button type="button" :class="f === 'ok' && 'is-on'" x-on:click="f = 'ok'">Encontradas ({{ $n }})</button>
            </div>
            <div>
                @forelse ($items as $it)
                    @php($ok = $found->contains($it->id))
                    <div class="df-inv-row {{ $ok ? 'is-found' : '' }} {{ $flash === $it->id ? 'is-flash' : '' }}" wire:key="i-{{ $it->id }}-{{ $ok ? 1 : 0 }}"
                         wire:click="toggle({{ $it->id }})" x-show="f === 'all' || (f === 'ok') === {{ $ok ? 'true' : 'false' }}">
                        <span style="font-size:18px">{{ $ok ? '✓' : '○' }}</span>
                        @if ($it->cover)<img src="{{ $it->cover->thumbUrl() }}" alt="" loading="lazy">@else<span class="df-nph"></span>@endif
                        <span><b>{{ $it->title }}</b><br><small class="df-mono">{{ $it->code }}</small></span>
                        <span class="df-pill {{ $ok ? 'df-pill-ok' : 'df-pill-n' }}">{{ $ok ? 'Encontrada' : 'Pendente' }}</span>
                    </div>
                @empty
                    <div class="df-empty"><p>Esta caixa não tem peças cadastradas.</p></div>
                @endforelse
            </div>
        </div>
    @endif

    @script
    <script>
        $wire.on('df-beep', ({ ok }) => {
            try {
                const a = new (window.AudioContext || window.webkitAudioContext)(), o = a.createOscillator(), g = a.createGain();
                o.frequency.value = ok ? 880 : 220; g.gain.value = .08; o.connect(g); g.connect(a.destination); o.start(); o.stop(a.currentTime + (ok ? .09 : .25));
                navigator.vibrate?.(ok ? 30 : [60, 40, 60]);
            } catch (e) {}
        });
    </script>
    @endscript
</x-filament-panels::page>
