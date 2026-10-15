<x-filament-panels::page>
    @php($tabs = $this->tabs())

    <div class="df-tabs" style="flex-wrap:wrap">
        @foreach ($tabs as $k => $t)
            <button type="button" class="{{ $tab === $k ? 'is-on' : '' }}" wire:click="$set('tab', '{{ $k }}')">
                {{ $t['label'] }} <span class="df-mono" style="opacity:.75">{{ $t['count'] }}</span>
            </button>
        @endforeach
    </div>

    @if ($tab !== 'dup' && auth()->user()->canEdit())
        <div class="df-row">
            @if ($tab === 'failed' && ! $showDismissed && ($tabs['failed']['count'] ?? 0))
                <div class="df-backup is-stale" style="flex:1 1 100%">
                    <x-filament::icon icon="heroicon-o-sparkles" class="df-backup-icon"/>
                    <div style="flex:1;min-width:0"><b>{{ $tabs['failed']['count'] }} peça(s) sem análise da IA.</b>
                        <p class="df-muted">A IA não respondeu no cadastro (limite de uso, conexão ou foto ilegível). Fotos e fichas estão salvas; reprocesse quando quiser.</p></div>
                    <x-filament::button icon="heroicon-m-arrow-path" wire:click="retryFailed">Reprocessar com IA ({{ $tabs['failed']['count'] }})</x-filament::button>
                </div>
            @endif
            @if (! $showDismissed && ($tabs[$tab]['count'] ?? 0))
                <x-filament::button size="sm" color="gray" icon="heroicon-m-check" wire:click="dismissAll">Dispensar todas ({{ $tabs[$tab]['count'] }})</x-filament::button>
                <x-filament::button size="sm" color="danger" outlined icon="heroicon-m-trash" wire:click="deleteAll"
                    wire:confirm="Mover as {{ $tabs[$tab]['count'] }} peça(s) desta aba para a Lixeira? Ficam 30 dias para restaurar. Se a ideia é só limpar a lista, use Dispensar.">Excluir essas peças</x-filament::button>
            @elseif ($showDismissed && ($tabs[$tab]['count'] ?? 0))
                <x-filament::button size="sm" wire:click="dismissAll(true)">Voltar a mostrar ({{ $tabs[$tab]['count'] }})</x-filament::button>
            @endif
            <span style="flex:1"></span>
            <label class="df-row" style="gap:6px;font-size:13px"><input type="checkbox" wire:model.live="showDismissed"> Ver dispensadas</label>
        </div>
    @endif

    @if ($tab === 'dup')
        @php($dups = $this->duplicates())
        @php($map = $this->dupItems())
        @if (! $dups)
            <div class="df-empty"><x-filament::icon icon="heroicon-o-check-circle" class="df-empty-icon"/><p>Nenhuma foto quase idêntica entre peças diferentes.</p></div>
        @else
            <div class="df-row"><p class="df-muted" style="flex:1">Peças diferentes com fotos quase iguais. Confira e una as que forem a mesma peça, ou marque como diferentes para não aparecerem mais.</p>
                <x-filament::button size="sm" color="gray" wire:click="ignoreAllDups" wire:confirm="Marcar todos os {{ count($dups) }} pares como peças diferentes?">Marcar todas como diferentes</x-filament::button></div>
            <div>
                @foreach ($dups as $d)
                    @php($a = $map[$d['a']] ?? null)
                    @php($b = $map[$d['b']] ?? null)
                    @continue(! $a || ! $b)
                    <div class="df-dup" wire:key="d-{{ $d['a'] }}-{{ $d['b'] }}">
                        <a href="{{ $this->itemUrl($a) }}" target="_blank">
                            @if ($a->cover)<img src="{{ $a->cover->thumbUrl() }}" alt="" loading="lazy">@endif
                            <span style="min-width:0"><b>{{ $a->title }}</b><br><small class="df-mono">{{ $a->code }}</small> <small class="df-muted">· {{ $a->box?->name ?? 'Sem caixa' }}</small></span>
                        </a>
                        <span class="df-pill df-pill-w">{{ (int) round($d['score'] * 100) }}%</span>
                        <a href="{{ $this->itemUrl($b) }}" target="_blank">
                            @if ($b->cover)<img src="{{ $b->cover->thumbUrl() }}" alt="" loading="lazy">@endif
                            <span style="min-width:0"><b>{{ $b->title }}</b><br><small class="df-mono">{{ $b->code }}</small> <small class="df-muted">· {{ $b->box?->name ?? 'Sem caixa' }}</small></span>
                        </a>
                        <div class="df-row" style="justify-content:flex-end">
                            <x-filament::button size="sm" wire:click="merge({{ $a->id }}, {{ $b->id }})"
                                wire:confirm="Unir {{ $b->code }} em {{ $a->code }}? As fotos e tags passam para {{ $a->code }}.">Unir</x-filament::button>
                            <x-filament::button size="sm" color="gray" wire:click="ignore({{ $a->id }}, {{ $b->id }})">São diferentes</x-filament::button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @else
        @php($rows = $this->list())

        @if ($rows->isEmpty())
            <div class="df-empty"><x-filament::icon icon="heroicon-o-check-circle" class="df-empty-icon"/><p>Nada pendente aqui.</p></div>
        @else
            <div>
                @foreach ($rows as $it)
                    <a class="df-inv-row" href="{{ $this->editUrl($it) }}" wire:key="r-{{ $it->id }}">
                        <span>›</span>
                        @if ($it->cover)<img src="{{ $it->cover->thumbUrl() }}" alt="" loading="lazy">@else<span class="df-nph"></span>@endif
                        <span style="min-width:0"><b>{{ $it->title }}</b><br><small class="df-mono">{{ $it->code }}</small> <small class="df-muted">· {{ $it->box?->name ?? 'Sem caixa' }}</small>
                            @if ($tab === 'failed' && $it->ai_error)<br><small class="df-muted">{{ $it->ai_error }}</small>@endif
                        </span>
                        <span class="df-pill df-pill-n">{{ $it->created_at->format('d/m/Y') }}</span>
                    </a>
                @endforeach
            </div>
            @if ($rows->count() === 200)<p class="df-muted">Mostrando as 200 mais recentes.</p>@endif
        @endif
    @endif
</x-filament-panels::page>
