<x-filament-panels::page>
    @php($cur = $results[$pick] ?? null)
    @php($labels = ['same' => 'Mesma peça', 'similar' => 'Semelhante', 'diff' => 'Diferente'])

    <div class="df-cmp">
        {{-- Origem + comparação deslizante --}}
        <div class="df-stack">
            <x-filament::section>
                {{ $this->form }}
            </x-filament::section>

            @if ($sourceUrl && $cur)
                <div class="df-slider" wire:key="slider-{{ $cur['id'] }}"
                     x-data="{ pos: 50, drag: false, set(e) { const r = $el.getBoundingClientRect(); this.pos = Math.max(0, Math.min(100, (e.clientX - r.left) / r.width * 100)) } }"
                     x-on:pointerdown="drag = true; $el.setPointerCapture($event.pointerId); set($event)"
                     x-on:pointermove="drag && set($event)" x-on:pointerup="drag = false"
                     x-on:keydown.left.prevent="pos = Math.max(0, pos - 5)" x-on:keydown.right.prevent="pos = Math.min(100, pos + 5)"
                     tabindex="0" role="slider" :aria-valuenow="Math.round(pos)" aria-label="Comparação deslizante">
                    <img src="{{ $cur['full'] }}" alt="">
                    <img src="{{ $sourceUrl }}" alt="" :style="`clip-path: inset(0 ${100 - pos}% 0 0)`">
                    <div class="df-slider-handle" :style="`left:${pos}%`"><span>⟷</span></div>
                    <span class="df-slider-label" style="left:8px">Referência</span>
                    <span class="df-slider-label df-mono" style="right:8px">{{ $cur['code'] }}</span>
                </div>
                <p class="df-muted">Arraste a divisória para sobrepor as duas imagens.</p>
            @elseif ($sourceUrl)
                <img src="{{ $sourceUrl }}" class="df-rounded" alt="">
            @endif
        </div>

        {{-- Resultados --}}
        <div class="df-stack">
            @if ($sourceUrl)
                <div class="df-row">
                    <h3 class="df-h3">{{ $results ? 'Mais parecidas no acervo' : 'Nenhuma peça com foto para comparar' }}</h3>
                    <span style="flex:1"></span>
                    @if ($results && $this->aiEnabled())
                        <x-filament::button color="warning" icon="heroicon-m-sparkles" wire:click="verifyWithAi" wire:target="verifyWithAi">
                            Conferir com IA
                        </x-filament::button>
                    @endif
                </div>

                <div class="df-cands" wire:loading.class="df-scanning" wire:target="verifyWithAi">
                    @foreach ($results as $i => $r)
                        @php($v = $verdicts[$r['id']] ?? null)
                        @php($pct = (int) round($r['score'] * 100))
                        <button type="button" wire:click="select({{ $i }})" wire:key="r-{{ $r['id'] }}"
                                class="df-cand {{ $i === $pick ? 'is-on' : '' }}" style="animation-delay: {{ $i * 45 }}ms">
                            <img src="{{ $r['thumb'] }}" alt="">
                            <div class="df-cand-body">
                                <b>{{ $r['title'] }}</b>
                                <small><span class="df-mono">{{ $r['code'] }}</span> · {{ $r['box'] ?? 'Sem caixa' }} · <a href="{{ $r['url'] }}" x-on:click.stop>abrir ficha</a></small>
                                @if ($v)
                                    <span class="df-verdict df-verdict-{{ $v['v'] }}">{{ $labels[$v['v']] }} · {{ $v['c'] }}%</span>
                                    @if ($v['n'])<small>{{ $v['n'] }}</small>@endif
                                @endif
                            </div>
                            <span class="df-ring" style="--p: {{ $r['score'] }}; --rc: {{ $pct >= 85 ? 'var(--df-ok)' : ($pct >= 70 ? 'var(--df-warn)' : 'var(--df-muted)') }}"
                                  x-data x-init="requestAnimationFrame(() => $el.classList.add('is-in'))">
                                <svg viewBox="0 0 52 52"><circle cx="26" cy="26" r="22" class="bg"/><circle cx="26" cy="26" r="22" class="fg"/></svg>
                                <span>{{ $pct }}%</span>
                            </span>
                        </button>
                    @endforeach
                </div>
                @if ($results)
                    <p class="df-muted">A porcentagem compara a assinatura visual (formas e cores). Ângulo e luz alteram o valor; a conferência com IA avalia se é o mesmo objeto.</p>
                @endif
            @else
                <div class="df-empty">
                    <x-filament::icon icon="heroicon-o-photo" class="df-empty-icon"/>
                    <p>Envie a foto de uma peça. O sistema procura as mais parecidas e, se você pedir, a IA confere se é o mesmo objeto.</p>
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
