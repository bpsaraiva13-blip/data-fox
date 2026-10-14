<x-filament-widgets::widget>
    @php($a = $this->acervo())
    @if ($a)
        <div class="df-backup {{ $this->stale() ? 'is-stale' : '' }}">
            <x-filament::icon icon="heroicon-o-cloud-arrow-down" class="df-backup-icon"/>
            <div style="flex:1;min-width:0">
                <b>{{ $this->stale() ? 'Faça um backup do acervo' : 'Backup em dia' }}</b>
                <p class="df-muted">
                    {{ $a->last_backup_at ? 'Último backup '.$a->last_backup_at->diffForHumans().'.' : 'Este acervo ainda não tem backup.' }}
                    O backup diário roda sozinho de madrugada quando o agendador está ligado.
                </p>
                @php($files = $this->files())
                @if ($files)
                    <p class="df-muted" style="margin-top:4px">
                        @foreach ($files as $f)
                            @if ($f['url'])<a href="{{ $f['url'] }}" class="df-link">{{ $f['name'] }}</a>@else{{ $f['name'] }}@endif{{ $loop->last ? '' : ' · ' }}
                        @endforeach
                    </p>
                @endif
            </div>
            <x-filament::button icon="heroicon-m-arrow-down-tray" wire:click="backupNow" wire:loading.attr="disabled">Gerar backup agora</x-filament::button>
        </div>
    @endif
</x-filament-widgets::widget>
