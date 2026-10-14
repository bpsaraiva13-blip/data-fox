<x-filament-panels::page>
    <form wire:submit="submit" class="df-stack">
        {{ $this->form }}

        <div class="df-row">
            <x-filament::button type="submit" icon="heroicon-m-check" wire:loading.attr="disabled" wire:target="submit">
                <span wire:loading.remove wire:target="submit">Cadastrar peças</span>
                <span wire:loading wire:target="submit">Criando fichas…</span>
            </x-filament::button>
            <span class="df-muted">Depois do lote, use <b>Unir peças</b> na listagem para juntar frente e verso que tenham ficado separados.</span>
        </div>
    </form>
</x-filament-panels::page>
