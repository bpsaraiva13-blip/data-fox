<x-filament-panels::page>
    <div class="df-cam" x-data="dfCamera($wire)" x-on:keydown.space.window.prevent="shoot()">
        <div class="df-stack">
            <div class="df-cam-view">
                <video x-ref="video" playsinline muted x-show="on"></video>
                <div x-show="! on" class="df-stack" style="align-items:center;text-align:center;padding:24px">
                    <x-filament::icon icon="heroicon-o-camera" class="df-empty-icon"/>
                    <p x-text="error || 'Toque em “Ligar câmera”. No celular, use a câmera traseira.'"></p>
                    <x-filament::button icon="heroicon-m-video-camera" x-on:click="start()">Ligar câmera</x-filament::button>
                    <label class="df-muted" style="cursor:pointer;text-decoration:underline">
                        ou escolha fotos do aparelho
                        <input type="file" accept="image/*" capture="environment" multiple hidden x-on:change="files($event)">
                    </label>
                </div>
                <div class="df-cam-flash" :class="flash && 'is-on'"></div>
                <div class="df-cam-bar" x-show="on">
                    <button type="button" class="df-cam-round" title="Trocar câmera" x-on:click="flip()">⟲</button>
                    <button type="button" class="df-shutter" title="Fotografar (barra de espaço)" x-on:click="shoot()" :disabled="busy > 2"></button>
                    <button type="button" class="df-cam-round" title="Desligar" x-on:click="stop()">✕</button>
                </div>
            </div>
            <p class="df-muted" x-show="busy" x-text="`Enviando ${busy} foto(s)…`"></p>
        </div>

        <div class="df-stack">
            <x-filament::section>
                <div class="df-stack">
                    <label class="df-stack" style="gap:6px">
                        <b>Caixa</b>
                        <select wire:model="boxId" class="df-input">
                            <option value="">Sem caixa</option>
                            @foreach ($this->boxes() as $b)
                                <option value="{{ $b->id }}">{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="df-tabs">
                        <button type="button" class="{{ $mode === 'single' ? 'is-on' : '' }}" wire:click="$set('mode', 'single')">Uma foto por peça</button>
                        <button type="button" class="{{ $mode === 'multi' ? 'is-on' : '' }}" wire:click="$set('mode', 'multi')">Várias fotos por peça</button>
                    </div>
                    @if ($mode === 'multi')
                        <x-filament::button color="gray" icon="heroicon-m-forward" wire:click="nextItem" :disabled="! $currentItem">
                            Próxima peça
                        </x-filament::button>
                        <p class="df-muted">Fotografe frente, verso e detalhes; depois toque em “Próxima peça”.</p>
                    @endif
                    @if (app(\App\Services\CuratorAI::class)->enabled())
                        <label class="df-row"><input type="checkbox" wire:model="ai"> Preencher as fichas com IA</label>
                    @endif
                </div>
            </x-filament::section>

            @php($recent = $this->recent())
            @if ($recent->isNotEmpty())
                <div class="df-row">
                    <h3 class="df-h3">{{ count($made) }} peça(s) nesta sessão</h3>
                    <span style="flex:1"></span>
                    <x-filament::button icon="heroicon-m-check" wire:click="finish">Concluir</x-filament::button>
                </div>
                <div class="df-cam-shots">
                    @foreach ($recent as $it)
                        <a href="{{ $this->itemUrl($it) }}" target="_blank" wire:key="s-{{ $it->id }}-{{ $it->photos_count }}"
                           style="{{ $currentItem === $it->id ? 'outline:3px solid var(--df-green)' : '' }}">
                            @if ($it->cover)<img src="{{ $it->cover->thumbUrl() }}" alt="">@endif
                            <small>{{ $it->photos_count }} foto(s)</small>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    @script
    <script>
        Alpine.data('dfCamera', (wire) => ({
            on: false, error: null, flash: false, busy: 0, facing: 'environment', stream: null, queue: Promise.resolve(),
            async start() {
                this.error = null;
                try {
                    this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: this.facing, width: { ideal: 1920 }, height: { ideal: 1440 } }, audio: false });
                    this.$refs.video.srcObject = this.stream; await this.$refs.video.play(); this.on = true;
                } catch (e) { this.error = 'Não foi possível abrir a câmera: ' + (e.message || e.name) + '. Você pode escolher fotos do aparelho.'; }
            },
            stop() { this.stream?.getTracks().forEach(t => t.stop()); this.on = false; },
            async flip() { this.facing = this.facing === 'environment' ? 'user' : 'environment'; this.stop(); await this.start(); },
            destroy() { this.stop(); },
            shoot() {
                if (! this.on) return;
                const v = this.$refs.video, s = Math.min(1, 1600 / Math.max(v.videoWidth, v.videoHeight));
                const c = document.createElement('canvas'); c.width = v.videoWidth * s; c.height = v.videoHeight * s;
                c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
                this.flash = false; requestAnimationFrame(() => this.flash = true); navigator.vibrate?.(25);
                c.toBlob(b => this.send(new File([b], 'camera.jpg', { type: 'image/jpeg' })), 'image/jpeg', .88);
            },
            files(e) { [...e.target.files].forEach(f => this.send(f)); e.target.value = ''; },
            send(file) {
                this.busy++;
                // envia uma por vez, na ordem, para as fotos caírem na peça certa
                this.queue = this.queue.then(() => new Promise(res => wire.upload('shot', file,
                    () => wire.saveShot().finally(() => { this.busy--; res(); }),
                    () => { this.busy--; res(); })));
            },
        }));
    </script>
    @endscript
</x-filament-panels::page>
