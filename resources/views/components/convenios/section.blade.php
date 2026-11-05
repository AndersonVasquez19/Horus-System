@props(['items' => []])

<div x-data="convenios(@js($items))" class="relative"
  x-init="$watch('active', value => $nextTick(() => {
    const dialog = $refs.convenioDialog;
    if (value && !dialog.open) dialog.showModal();
    else if (!value && dialog.open) dialog.close();
  }))">
  {{-- Contenedor Flex Wrap Centrado: 3 en la 1ra fila y 2 en la 2da fila en desktop, 1 en móvil, 2 en tablet --}}
  <div class="flex flex-wrap justify-center gap-6 lg:gap-8 max-w-7xl mx-auto">
    @foreach ($items as $item)
      <div class="w-full md:w-[calc(50%-1rem)] lg:w-[calc(33.333%-1.5rem)] max-w-md flex">
        <x-convenios.card :item="$item" class="w-full" />
      </div>
    @endforeach
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
          <span class="px-3 py-1 rounded-full text-xs font-bold tracking-wider uppercase bg-slate-100 text-[#0A2540] border border-slate-200" x-text="active ? active.siglas : ''"></span>
          <span class="text-xs font-medium text-slate-500">
            <i class="fa-regular fa-calendar-check text-slate-400 mr-1"></i> Vigente hasta <strong class="text-slate-800" x-text="active ? active.vigencia : ''"></strong>
          </span>
        </div>

        <h3 id="convenio-modal-title" class="text-xl sm:text-2xl font-bold text-slate-900 leading-snug" x-text="active ? active.nombre : ''"></h3>

        {{-- Garantías Destacadas --}}
        <div class="bg-slate-50 rounded-2xl p-4 sm:p-5 border border-slate-200">
          <h4 class="text-xs font-bold uppercase tracking-wider text-slate-900 mb-2.5 flex items-center gap-2">
            <i class="fa-solid fa-award text-[#0A2540]"></i> Beneficios para participantes
          </h4>
          <ul class="text-xs sm:text-sm text-slate-700 space-y-2">
            <li class="flex items-start gap-2">
              <i class="fa-solid fa-circle-check text-emerald-600 mt-0.5 shrink-0"></i>
              <span>Certificados oficiales con firma y sello de Decanatura y representantes de Horus Group.</span>
            </li>
            <li class="flex items-start gap-2">
              <i class="fa-solid fa-circle-check text-emerald-600 mt-0.5 shrink-0"></i>
              <span>Validez acreditada para postulaciones y convocatorias en entidades públicas y privadas a nivel nacional.</span>
            </li>
          </ul>
        </div>

        {{-- Descripción Oficial Completa --}}
        <div>
          <h4 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">Alcance del convenio:</h4>
          <p class="text-xs sm:text-sm leading-relaxed text-slate-600" x-text="active ? active.descripcion : ''"></p>
        </div>

        {{-- Footer de Modal --}}
        <div class="pt-4 border-t border-slate-100 flex items-center justify-between">
          <div class="text-xs text-slate-400">
            Horus Group SRL &copy; Alianzas Estratégicas
          </div>
          <button
            type="button"
            x-on:click="close()"
            class="px-5 py-2.5 rounded-xl text-xs font-bold uppercase tracking-wider bg-[#0A2540] text-white hover:bg-slate-800 transition-colors shadow-sm"
          >
            Entendido
          </button>
        </div>
      </div>
    </div>
  </dialog>
</div>
