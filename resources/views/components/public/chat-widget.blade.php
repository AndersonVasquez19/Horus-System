<div
    x-data="{ abierto: false, mostrarSaludo: true }"
    @keydown.escape.window="if (abierto) { abierto = false; $nextTick(() => $refs.launcher.focus()) }"
    class="horus-chat-widget fixed bottom-4 right-3 z-50 flex flex-col items-end gap-3 sm:bottom-6 sm:right-6"
>
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
        class="horus-chat-panel flex min-h-0 flex-col overflow-hidden rounded-[1.5rem] border border-[#D9E2EB] bg-white shadow-[0_24px_72px_rgba(10,37,64,0.26)]"
        aria-label="Asistente virtual de Horus Group"
    >
        <header class="relative flex items-center gap-3 overflow-hidden bg-gradient-to-r from-[#0A2540] via-[#123A59] to-[#0B5664] px-4 py-3 text-white">
            <span aria-hidden="true" class="pointer-events-none absolute -right-8 -top-14 h-32 w-32 rounded-full border border-white/10"></span>
            <span class="relative flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-[#E9D8AC]/50 bg-[#E9D8AC]/15">
                <img src="{{ asset('assets/images/index/stiker/stiker.png') }}" alt="" width="56" height="56" class="h-full w-full object-contain" draggable="false">
            </span>
            <span class="relative min-w-0 flex-1">
                <span class="block text-base font-bold leading-tight">Horus</span>
                <span class="mt-0.5 block text-xs text-[#E9E9E3]">Tu asistente virtual</span>
            </span>
            <button x-ref="closeButton" type="button" @click="abierto = false; $nextTick(() => $refs.launcher.focus())" aria-label="Cerrar chat" class="relative flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-white/20 bg-white/10 text-white transition hover:bg-white/20 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E8DCC4]">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        </header>
        <div class="border-b border-[#E5EBEF] bg-[#F8F7F1] px-5 py-2.5 text-[11px] font-medium text-[#486075]">
            Pregúntame por cursos, servicios, horarios o ubicación.
        </div>
        <x-chat.conversation :endpoint="route('chat.mensaje')" />
    </section>

    <div
        x-cloak
        x-show="!abierto && mostrarSaludo"
        x-transition.opacity.duration.200ms
        class="horus-chat-greeting relative mr-1 max-w-[min(19rem,calc(100vw-2rem))] rounded-2xl border border-[#E5D2A0] bg-white px-4 py-3 pr-10 shadow-[0_14px_38px_rgba(10,37,64,0.16)]"
    >
        <span class="block text-[11px] font-bold uppercase tracking-[0.13em] text-[#846218]">¡Hola! Soy Horus</span>
        <span class="mt-1 block text-sm font-semibold leading-snug text-[#0A2540]">¿En qué te puedo ayudar?</span>
        <button type="button" @click="mostrarSaludo = false" aria-label="Ocultar saludo de Horus" class="absolute right-1.5 top-1.5 flex h-8 w-8 items-center justify-center rounded-full text-[#64748B] transition hover:bg-[#F1F5F9] hover:text-[#0A2540] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#B8956A]">
            <i class="fa-solid fa-xmark text-xs" aria-hidden="true"></i>
        </button>
    </div>

    <button
        x-ref="launcher"
        type="button"
        @click="abierto = !abierto; mostrarSaludo = false; if (abierto) $nextTick(() => $refs.closeButton.focus())"
        :aria-expanded="abierto.toString()"
        :aria-label="abierto ? 'Cerrar asistente virtual' : 'Abrir asistente virtual'"
        aria-controls="horus-chat-panel"
        aria-label="Abrir asistente virtual"
        class="horus-chat-launcher group relative flex h-[4.5rem] w-[13.5rem] items-center overflow-visible rounded-full border-2 border-[#E6C878] bg-gradient-to-r from-[#0A2540] to-[#124C60] pl-[5.25rem] pr-4 text-left text-white shadow-[0_12px_32px_rgba(10,37,64,0.32)] transition duration-200 hover:-translate-y-1 hover:shadow-[0_16px_38px_rgba(10,37,64,0.38)] focus-visible:outline focus-visible:outline-4 focus-visible:outline-offset-2 focus-visible:outline-[#C9A961]"
    >
        <span aria-hidden="true" class="absolute -left-1 bottom-0 h-[5.9rem] w-[6.2rem] overflow-hidden rounded-b-full">
            <img src="{{ asset('assets/images/index/stiker/stiker.png') }}" alt="" width="98" height="94" class="horus-chat-mascot h-full w-full object-contain" draggable="false">
        </span>
        <span class="relative block leading-tight">
            <span class="block text-[10px] font-semibold uppercase tracking-[0.18em] text-[#E9D8AC]">Horus te saluda</span>
            <span class="mt-1 block text-sm font-bold" x-text="abierto ? 'Cerrar chat' : 'Hablemos'">Hablemos</span>
        </span>
        <span aria-hidden="true" class="ml-auto text-[11px] text-[#E9D8AC] transition group-hover:translate-x-0.5">
            <i class="fa-solid fa-arrow-up-right"></i>
        </span>
    </button>
</div>
