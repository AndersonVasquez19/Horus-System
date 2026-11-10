@if (session('success') || session('error'))
    @php($isSuccess = session()->has('success'))
    @php($mensajeAviso = (string) (session('success') ?? session('error')))
    {{-- Éxito: se cierra solo a los 6 s (pausa con puntero o foco). Error: permanece hasta cerrarlo.
         El texto se repone tras iniciar Alpine para que la región viva lo anuncie; sin JavaScript se ve igual. --}}
    <div
        x-data="avisoPanel"
        x-show="visible"
        x-transition.opacity
        data-aviso
        data-tipo="{{ $isSuccess ? 'success' : 'error' }}"
        data-mensaje="{{ $mensajeAviso }}"
        @mouseenter="pausar()"
        @mouseleave="reanudar()"
        @focusin="pausar()"
        @focusout="reanudar()"
        role="{{ $isSuccess ? 'status' : 'alert' }}"
        @class([
            'fixed right-4 top-20 z-50 flex max-w-sm items-start gap-3 rounded-2xl border border-l-4 bg-white p-4 shadow-xl sm:right-6',
            'border-[#E2E8F0] border-l-emerald-500' => $isSuccess,
            'border-[#E2E8F0] border-l-red-500' => ! $isSuccess,
        ])
    >
        <i @class([
            'fa-solid mt-0.5 text-base',
            'fa-circle-check text-emerald-600' => $isSuccess,
            'fa-circle-exclamation text-red-600' => ! $isSuccess,
        ]) aria-hidden="true"></i>
        <p class="text-sm font-medium leading-5 text-[#172B3A]" x-text="mensaje">{{ $mensajeAviso }}</p>
        <button type="button" @click="cerrar()" class="ml-auto inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-[#64748B] transition hover:bg-[#F4F7FB] hover:text-[#172B3A] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#176B9A]" aria-label="Cerrar aviso">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </button>
    </div>
@endif
