{{-- Pré-visualização do cartão + ações de envio. A imagem é gerada no servidor (ShareCard). --}}
<div class="df-share" x-data="{
        loading: true,
        wa() {
            const text = document.getElementById('df-share-text')?.value || '';
            const d = (document.getElementById('df-share-phone')?.value || '').replace(/\D/g, '');
            const num = d ? (d.length <= 11 ? '55' + d : d) : '';
            window.open(`https://wa.me/${num}?text=${encodeURIComponent(text)}`, '_blank', 'noopener');
        },
        async copyText() {
            const t = document.getElementById('df-share-text');
            try { await navigator.clipboard.writeText(t.value); $tooltip('Texto copiado') } catch (e) { t.select() }
        },
        async copyImage() {
            try {
                const blob = await (await fetch(@js($imageUrl) + '&png=1')).blob();
                await navigator.clipboard.write([new ClipboardItem({ [blob.type]: blob })]);
                $tooltip('Imagem copiada');
            } catch (e) { $tooltip('Este navegador não permite copiar imagens. Use Baixar imagem.') }
        },
    }">
    <div class="df-share-frame" :class="loading && 'is-loading'">
        <img src="{{ $imageUrl }}" alt="Cartão de compartilhamento" x-on:load="loading = false" x-init="$watch('$el.src', () => loading = true)" wire:key="{{ $imageUrl }}">
    </div>
    <div class="df-share-steps">
        <div class="df-share-step">
            <b><span class="df-n">1</span> Leve a imagem</b>
            <p>O WhatsApp não recebe imagens por link. Baixe ou copie a imagem para anexar ou colar na conversa.</p>
            <div class="df-row">
                <x-filament::button tag="a" :href="$imageUrl . '&download=1'" icon="heroicon-m-arrow-down-tray" color="gray">Baixar imagem</x-filament::button>
                <x-filament::button type="button" x-on:click="copyImage()" icon="heroicon-m-clipboard" color="gray">Copiar imagem</x-filament::button>
            </div>
        </div>
        <div class="df-share-step">
            <b><span class="df-n">2</span> Envie o texto</b>
            <p>Abre o WhatsApp com a mensagem abaixo já preenchida.</p>
            <div class="df-row">
                <x-filament::button type="button" x-on:click="wa()" icon="heroicon-m-chat-bubble-left-right" color="success">Abrir no WhatsApp</x-filament::button>
                <x-filament::button type="button" x-on:click="copyText()" icon="heroicon-m-document-duplicate" color="gray">Copiar texto</x-filament::button>
            </div>
        </div>
    </div>
</div>
