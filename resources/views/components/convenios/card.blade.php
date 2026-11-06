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

<div class="convenio-card">
  <article class="cv-card">
    @if ($imagen)
      <div class="cv-photo">
        <img
          src="{{ asset($imagen) }}"
          alt="{{ $nombre }}"
          loading="lazy"
          style="object-position: {{ !empty($posicion) ? $posicion : 'center' }}"
        />
      </div>
    @else
      <div class="cv-photo cv-photo--empty"><b>{{ $siglas }}</b></div>
    @endif

    <div class="cv-body">
      <div class="cv-id">
        <span class="cv-logo"><img src="{{ asset($logo) }}" alt="Logo {{ $siglas }}" /></span>
        <span class="cv-sig">{{ $siglas }}</span>
      </div>

      <h3 class="cv-name">{{ $nombre }}</h3>

      <p class="cv-vig">
        <span class="cv-dot" aria-hidden="true"></span>
        <span class="cv-vig-label">Vigente hasta</span>
        <strong>{{ $vigencia }}</strong>
      </p>

      <button type="button" x-on:click="open('{{ $id }}')" aria-haspopup="dialog" class="cv-more">
        <span>Ver convenio</span>
        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
      </button>
    </div>
  </article>
</div>
