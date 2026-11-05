<div
    x-data="{ abierto: false }"
    @keydown.escape.window="if (abierto) { abierto = false; $nextTick(() => $refs.launcher.focus()) }"
    class="horus-chat-widget"
>
    <button
        x-ref="launcher"
        type="button"
        @click="abierto = true; $nextTick(() => $refs.closeButton.focus())"
        :aria-expanded="abierto.toString()"
        aria-label="Abrir chat con el asistente Horus"
        aria-haspopup="dialog"
        aria-controls="horus-chat-panel"
        class="hc-launcher"
    >
        <span class="hc-launcher-hint" aria-hidden="true">¿En qué te ayudo?</span>
        <span class="hc-launcher-orbit" aria-hidden="true"></span>
        <x-public.robot-mascot class="hc-launcher-robot" />
        <span class="hc-launcher-badge" aria-hidden="true"><i class="fa-regular fa-comment-dots"></i></span>
    </button>

    <section
        id="horus-chat-panel"
        x-cloak
        x-show="abierto"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="translate-y-3 opacity-0"
        x-transition:enter-end="translate-y-0 opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="translate-y-0 opacity-100"
        x-transition:leave-end="translate-y-3 opacity-0"
        class="hc-dialog"
        role="dialog"
        aria-modal="false"
        aria-labelledby="hc-title"
    >
        <div class="hc-shell">
            <header class="hc-header">
                <span class="hc-mark">
                    <x-public.robot-mascot :portrait="true" />
                </span>
                <div class="hc-header-copy">
                    <span class="hc-header-brand">HORUS GROUP</span>
                    <h2 id="hc-title">Asistente Horus</h2>
                    <p>Cursos, tecnología y orientación</p>
                </div>
                <button x-ref="closeButton" type="button" @click="abierto = false; $nextTick(() => $refs.launcher.focus())" class="hc-close" aria-label="Cerrar chat">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </header>
            <div class="hc-toolbar">
                <span><i aria-hidden="true"></i>Información del catálogo</span>
                <span class="hc-toolbar-caption">Asistente virtual</span>
            </div>
            <x-chat.conversation :endpoint="route('chat.mensaje')" :public="true" />
        </div>
    </section>
</div>
