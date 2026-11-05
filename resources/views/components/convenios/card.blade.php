@props([
  'item' => [],
  'id' => null,
  'nombre' => null,
  'logo' => null,
  'siglas' => null,
  'vigencia' => null,
  'imagen' => null,
  'posicion' => null,
  'descripcion' => null,
])

@php
  $id = $item['id'] ?? $id;
  $nombre = $item['nombre'] ?? $nombre;
  $logo = $item['logo'] ?? $logo;
  $siglas = $item['siglas'] ?? $siglas;
  $vigencia = $item['vigencia'] ?? $vigencia;
  $imagen = $item['imagen'] ?? $imagen;
  $posicion = $item['posicion'] ?? $posicion;
  $descripcion = $item['descripcion'] ?? $descripcion;
@endphp

<div class="convenio-card" style="display: flex; flex-direction: column; width: 100%;">
  <article class="group relative flex flex-col h-full bg-white rounded-3xl border border-slate-200/90 shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all duration-300 overflow-hidden text-left">
    {{-- Cabecera con Imagen o Fondo Institucional --}}
    <div class="relative w-full h-48 sm:h-52 bg-slate-900 overflow-hidden">
      @if ($imagen)
        <img
          src="{{ asset($imagen) }}"
          alt="{{ $nombre }}"
          loading="lazy"
          style="{{ !empty($posicion) ? 'object-position: ' . $posicion : 'object-position: center' }}"
          class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105"
        />
        <div class="absolute inset-0 bg-gradient-to-t from-slate-950/70 via-slate-950/20 to-transparent"></div>
      @else
        {{-- Cabecera elegante cuando no hay foto disponible --}}
        <div class="w-full h-full bg-gradient-to-br from-[#0A2540] via-[#102d49] to-[#153a59] flex items-center justify-center p-6 relative overflow-hidden">
          <div class="absolute -right-8 -top-8 w-32 h-32 rounded-full border border-white/10 pointer-events-none"></div>
          <div class="absolute -left-8 -bottom-8 w-32 h-32 rounded-full border border-white/5 pointer-events-none"></div>
          <div class="text-center z-10">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold tracking-wider uppercase bg-white/10 text-white border border-white/20">
              <i class="fa-solid fa-handshake"></i> Alianza Estratégica
            </span>
          </div>
        </div>
      @endif

      {{-- Badge de Vigencia Flotante --}}
      <div class="absolute top-3 right-3 z-10">
        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-slate-950/75 text-white backdrop-blur-md border border-white/15 shadow-sm">
          <i class="fa-regular fa-calendar-check text-slate-300"></i>
          <span>Vigente hasta {{ $vigencia }}</span>
        </span>
      </div>
    </div>

    {{-- Contenedor del Cuerpo con Logo Flotante --}}
    <div class="relative flex-1 flex flex-col p-6 pt-0">
      {{-- Logo Institucional con solape (Overlap) --}}
      <div class="-mt-8 mb-4 flex items-center justify-between">
        <div class="w-16 h-16 rounded-2xl bg-white p-2.5 shadow-lg border border-slate-100 flex items-center justify-center overflow-hidden">
          <img src="{{ asset($logo) }}" alt="Logo {{ $siglas }}" class="w-full h-full object-contain" />
        </div>
        <span class="text-xs font-bold tracking-widest uppercase text-[#0A2540] px-2.5 py-1 bg-slate-100 rounded-lg border border-slate-200 shadow-xs">
          {{ $siglas }}
        </span>
      </div>

      {{-- Título --}}
      <h3 class="text-base sm:text-lg font-bold text-slate-900 group-hover:text-[#0A2540] leading-snug transition-colors min-h-[2.75rem]">
        {{ $nombre }}
      </h3>

      {{-- Beneficios Destacados en Viñetas (Escaneables) --}}
      <ul class="mt-4 space-y-2 text-xs text-slate-600 flex-1">
        <li class="flex items-start gap-2">
          <i class="fa-solid fa-circle-check text-emerald-500 mt-0.5 text-sm shrink-0"></i>
          <span>Certificados con firma oficial de Decanato y validez curricular</span>
        </li>
        <li class="flex items-start gap-2">
          <i class="fa-solid fa-circle-check text-emerald-500 mt-0.5 text-sm shrink-0"></i>
          <span>Válidos para convocatorias públicas y privadas a nivel nacional</span>
        </li>
        <li class="flex items-start gap-2">
          <i class="fa-solid fa-circle-check text-emerald-500 mt-0.5 text-sm shrink-0"></i>
          <span>Talleres, charlas, capacitaciones y diplomados conjuntos</span>
        </li>
      </ul>

      {{-- Botón de Acción para Abrir Detalles --}}
      <div class="mt-6 pt-4 border-t border-slate-100">
        <button
          type="button"
          x-on:click="open('{{ $id }}')"
          class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 text-xs font-semibold tracking-wide text-[#0A2540] bg-slate-50 hover:bg-[#0A2540] hover:text-white rounded-xl border border-slate-200 hover:border-transparent transition-all duration-200 shadow-xs group/btn"
        >
          <span>Ver detalles del convenio</span>
          <i class="fa-solid fa-arrow-right text-[10px] transition-transform group-hover/btn:translate-x-1"></i>
        </button>
      </div>
    </div>
  </article>
</div>
