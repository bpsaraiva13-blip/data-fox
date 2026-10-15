<x-filament-panels::page>
    @php($tabs = $this->tabs())
    <div class="df-row">
        <div class="df-tabs" style="flex-wrap:wrap">
            @foreach ($tabs as $k => $t)
                <button type="button" class="{{ $tab === $k ? 'is-on' : '' }}" wire:click="$set('tab', '{{ $k }}')">
                    {{ $t['label'] }} <span class="df-mono" style="opacity:.75">{{ $t['count'] }}</span>
                </button>
            @endforeach
        </div>
        <span style="flex:1"></span>
        @if (collect($tabs)->sum('count') && auth()->user()->isAcervoAdmin())
            <x-filament::button color="danger" icon="heroicon-m-trash" wire:click="emptyTrash"
                wire:confirm="Apagar de vez tudo o que está na lixeira, com as fotos? Não dá para desfazer.">Esvaziar lixeira</x-filament::button>
        @endif
    </div>
    <p class="df-muted">O que é excluído fica aqui por {{ \App\Filament\Pages\Trash::DAYS }} dias. Depois é apagado de vez, com os arquivos.</p>

    @php($rows = $this->rows())
    @if ($rows->isEmpty())
        <div class="df-empty"><x-filament::icon icon="heroicon-o-trash" class="df-empty-icon"/><p>Nada aqui.</p></div>
    @else
        <div>
            @foreach ($rows as $r)
                <div class="df-inv-row" style="cursor:default" wire:key="t-{{ $tab }}-{{ $r['id'] }}">
                    <span></span>
                    @if ($r['thumb'])<img src="{{ $r['thumb'] }}" alt="" loading="lazy">@else<span class="df-nph"></span>@endif
                    <span style="min-width:0"><b>{{ $r['title'] }}</b><br>
                        <small class="df-muted">{{ $r['sub'] }}{{ $r['sub'] ? ' · ' : '' }}excluído {{ $r['deleted']->diffForHumans() }}</small></span>
                    <span class="df-row" style="justify-content:flex-end">
                        <span class="df-pill {{ $r['left'] <= 5 ? 'df-pill-w' : 'df-pill-n' }}">{{ $r['left'] }} dia(s)</span>
                        <x-filament::button size="sm" icon="heroicon-m-arrow-uturn-left" wire:click="restore({{ $r['id'] }})">Restaurar</x-filament::button>
                        <x-filament::icon-button icon="heroicon-m-trash" color="danger" label="Apagar de vez" wire:click="forceDelete({{ $r['id'] }})"
                            wire:confirm="Apagar de vez? Não dá para desfazer."/>
                    </span>
                </div>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
