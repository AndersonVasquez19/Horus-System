@props(['items' => [], 'title' => 'Convenios estratégicos', 'intro' => null])

<div x-data="convenios(@js($items))" class="relative"
  x-init="$watch('active', value => $nextTick(() => {
    const dialog = $refs.convenioDialog;
    if (value && !dialog.open) dialog.showModal();
    else if (!value && dialog.open) dialog.close();
  }))">
  {{-- Carrusel con scroll-snap: 3 tarjetas en escritorio, 2 en tablet y 1 con asomo en móvil --}}
  <div
    x-data="conveniosCarousel({{ count($items) }})"
    x-on:mouseenter="hovering = true"
    x-on:mouseleave="hovering = false"
    x-on:focusin="hovering = true"
    x-on:focusout="hovering = false"
    x-on:resize.window.debounce.150ms="measure()"
    :class="{ 'is-in': inView }"
    class="convenios-carousel mx-auto max-w-7xl"
    role="region"
    aria-roledescription="carrusel"
    aria-label="{{ $title }}"
  >
    <div class="cv-head">
      <div>
        <h2 class="cv-title">{{ $title }}</h2>
        @if ($intro)
          <p class="cv-intro">{{ $intro }}</p>
        @endif
      </div>

      <div class="cv-ctrls" x-show="pages > 1">
        <span class="cv-count" x-text="range" aria-live="polite"></span>
        <button type="button" x-on:click="prev()" class="cv-ctrl" aria-label="Convenios anteriores">
          <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
        </button>
        <button type="button" x-on:click="next()" class="cv-ctrl" aria-label="Convenios siguientes">
          <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
        </button>
        <button
          type="button"
          x-show="autoplay"
          x-on:click="toggleAutoplay()"
          :aria-label="userPaused ? 'Reanudar rotación automática' : 'Pausar rotación automática'"
          class="cv-ctrl"
        >
          <i class="fa-solid" :class="userPaused ? 'fa-play' : 'fa-pause'" aria-hidden="true"></i>
        </button>
      </div>
    </div>

    <div
      x-ref="track"
      x-on:scroll.passive="onScroll()"
      :aria-live="running ? 'off' : 'polite'"
      class="convenios-track"
    >
      @foreach ($items as $item)
        <div
          class="convenios-slide"
          style="--i: {{ $loop->index }}"
          :class="{ 'is-dim': !inWindow({{ $loop->index }}) }"
          role="group"
          aria-roledescription="diapositiva"
          aria-label="{{ $loop->iteration }} de {{ $loop->count }}"
        >
          <x-convenios.card :item="$item" />
        </div>
      @endforeach
    </div>
  </div>

  {{-- Modal de Detalle Completo --}}
  <dialog
    x-ref="convenioDialog"
    x-show="active !== null"
    x-cloak
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-950/75 backdrop-blur-sm"
    style="width: 100%; height: 100%; max-width: none; max-height: none; margin: 0; border: 0; color: inherit;"
    x-on:cancel.prevent="close()"
    x-on:close="if (!$el.open) close()"
    x-on:keydown.tab="
      const controls = [...$el.querySelectorAll('button:not(:disabled), a[href], [tabindex=&quot;0&quot;]')].filter(control => control.getClientRects().length);
      const first = controls[0], last = controls[controls.length - 1];
      if ($event.shiftKey && document.activeElement === first) { $event.preventDefault(); last.focus(); }
      else if (!$event.shiftKey && document.activeElement === last) { $event.preventDefault(); first.focus(); }
    "
    role="dialog"
    aria-modal="true"
    aria-labelledby="convenio-modal-title"
  >
    {{-- Caja del Modal --}}
    <div
      x-show="active !== null"
      x-transition:enter="transition ease-out duration-300 transform"
      x-transition:enter-start="opacity-0 scale-95 translate-y-4"
      x-transition:enter-end="opacity-100 scale-100 translate-y-0"
      x-transition:leave="transition ease-in duration-200 transform"
      x-transition:leave-start="opacity-100 scale-100 translate-y-0"
      x-transition:leave-end="opacity-0 scale-95 translate-y-4"
      x-on:click.outside="close()"
      class="relative w-full max-w-2xl max-h-[90vh] flex flex-col bg-white rounded-3xl shadow-2xl border border-slate-100 overflow-hidden"
    >
      {{-- Botón de Cierre --}}
      <button
        type="button"
        x-on:click="close()"
        class="absolute top-4 right-4 z-20 w-10 h-10 rounded-full bg-white/90 hover:bg-white text-slate-700 hover:text-slate-900 flex items-center justify-center shadow-md border border-slate-200/80 transition-all hover:rotate-90 focus:outline-hidden focus:ring-2 focus:ring-[#0A2540]"
        aria-label="Cerrar detalle del convenio"
      >
        <i class="fa-solid fa-xmark text-lg"></i>
      </button>

      {{-- Cabecera con Foto o Banner Institucional --}}
      <div class="relative w-full shrink-0 overflow-hidden">
        <template x-if="active && active.imagen">
          <div class="w-full h-56 sm:h-72 bg-slate-900">
            <img
              :src="'{{ url('/') }}/' + active.imagen"
              :alt="active ? active.nombre : ''"
              class="w-full h-full object-cover"
              :style="active && active.posicion ? 'object-position: ' + active.posicion : 'object-position: center'"
            />
            <div class="absolute inset-0 bg-gradient-to-t from-slate-950/60 via-transparent to-transparent"></div>
          </div>
        </template>

        <template x-if="active && !active.imagen">
          <div class="w-full h-44 sm:h-52 bg-gradient-to-br from-[#0A2540] via-[#102d49] to-[#153a59] flex items-center justify-center p-6 relative overflow-hidden">
            <div class="absolute -right-8 -top-8 w-36 h-36 rounded-full border border-white/10 pointer-events-none"></div>
            <div class="absolute -left-8 -bottom-8 w-36 h-36 rounded-full border border-white/5 pointer-events-none"></div>
            <div class="w-20 h-20 rounded-2xl bg-white p-3 shadow-xl flex items-center justify-center">
              <img :src="active ? '{{ url('/') }}/' + active.logo : ''" :alt="active ? active.nombre : ''" class="w-full h-full object-contain" />
            </div>
          </div>
        </template>
      </div>

      {{-- Cuerpo Desplazable del Modal --}}
      <div class="p-6 sm:p-8 overflow-y-auto space-y-4" tabindex="0" role="region" aria-label="Detalle del convenio, desplazable">
        <div class="flex flex-wrap items-center gap-3">
          <span class="px-3 py-1 rounded-full text-sm font-bold tracking-wide bg-slate-100 text-[#0A2540] border border-slate-200" x-text="active ? active.siglas : ''"></span>
          <span class="text-sm font-medium text-slate-600">
            <i class="fa-regular fa-calendar-check text-slate-500 mr-1"></i> Vigente hasta <strong class="text-slate-800" x-text="active ? active.vigencia : ''"></strong>
          </span>
        </div>

        <h3 id="convenio-modal-title" class="text-xl sm:text-2xl font-bold text-slate-900 leading-snug" x-text="active ? active.nombre : ''"></h3>

        {{-- Garantías Destacadas --}}
        <div class="bg-slate-50 rounded-2xl p-4 sm:p-5 border border-slate-200">
          <h4 class="text-base font-semibold text-slate-900 mb-2.5 flex items-center gap-2">
            <i class="fa-solid fa-award text-[#0A2540]"></i> Beneficios para participantes
          </h4>
          <ul class="text-sm text-slate-700 space-y-2">
            <li class="flex items-start gap-2">
              <i class="fa-solid fa-circle-check text-[#0A2540] mt-0.5 shrink-0"></i>
              <span>Certificados oficiales con firma y sello de Decanatura y representantes de Horus Group.</span>
            </li>
            <li class="flex items-start gap-2">
              <i class="fa-solid fa-circle-check text-[#0A2540] mt-0.5 shrink-0"></i>
              <span>Validez acreditada para postulaciones y convocatorias en entidades públicas y privadas a nivel nacional.</span>
            </li>
          </ul>
        </div>

        {{-- Descripción Oficial Completa --}}
        <div>
          <h4 class="text-base font-semibold text-slate-900 mb-2">Alcance del convenio</h4>
          <p class="text-sm leading-relaxed text-slate-600" x-text="active ? active.descripcion : ''"></p>
        </div>

        {{-- Footer de Modal --}}
        <div class="pt-4 border-t border-slate-100 flex items-center justify-between">
          <div class="text-sm text-slate-500">
            Horus Group SRL &copy; Alianzas Estratégicas
          </div>
          <button
            type="button"
            x-on:click="close()"
            class="px-5 py-2.5 rounded-xl text-sm font-semibold bg-[#0A2540] text-white hover:bg-slate-800 transition-colors shadow-sm"
          >
            Entendido
          </button>
        </div>
      </div>
    </div>
  </dialog>
</div>
