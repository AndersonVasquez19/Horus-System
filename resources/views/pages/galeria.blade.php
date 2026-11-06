@extends('layouts.app')

@section('title', 'Galería')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/galeria/galeria.css') }}">
@endpush

@push('scripts')
  <script src="{{ asset('assets/js/galeria.js') }}" defer></script>
@endpush

@section('content')
@php
  $base = 'assets/images/galeria/';
  $categorias = [
    'capacitaciones' => 'Capacitaciones',
    'servicio-tecnico' => 'Servicio técnico',
    'primeros-auxilios' => 'Primeros auxilios',
    'convenios' => 'Convenios',
  ];
  $fotos = [
    ['cat' => 'capacitaciones', 'label' => 'Capacitaciones', 'alt' => 'Capacitación de Horus Group con participantes', 'src' => $base.'capacitaciones/imagen 1.jpg', 'w' => 1600, 'h' => 721],
    ['cat' => 'convenios', 'label' => 'Colegio de Abogados', 'alt' => 'Actividad con el Colegio de Abogados', 'src' => $base.'colegio de abogados/imagen 4-web.jpg', 'w' => 1600, 'h' => 715],
    ['cat' => 'convenios', 'label' => 'Colegio de Abogados', 'alt' => 'Actividad con el Colegio de Abogados', 'src' => $base.'colegio de abogados/imagen 10.jpg', 'w' => 1156, 'h' => 521],
    ['cat' => 'convenios', 'label' => 'Colegio de Enfermeros', 'alt' => 'Actividad con el Colegio de Enfermeros', 'src' => $base.'colegio de enfermeros/imagen 3.jpg', 'w' => 1600, 'h' => 900],
    ['cat' => 'convenios', 'label' => 'Colegio de Enfermeros', 'alt' => 'Actividad con el Colegio de Enfermeros', 'src' => $base.'colegio de enfermeros/imagen 11-web.jpg', 'w' => 1600, 'h' => 1600],
    ['cat' => 'convenios', 'label' => 'ISAM', 'alt' => 'Actividad en el ISAM', 'src' => $base.'ISAM/imagen 1.jpg', 'w' => 1280, 'h' => 960],
    ['cat' => 'convenios', 'label' => 'ISAM', 'alt' => 'Actividad en el ISAM', 'src' => $base.'ISAM/imagen 4.jpg', 'w' => 960, 'h' => 1280, 'pos' => 'center 30%'],
    ['cat' => 'servicio-tecnico', 'label' => 'Servicio técnico', 'alt' => 'Servicio técnico de Horus Group', 'src' => $base.'nuestro servicio tecnico/imagen 5.jpg', 'w' => 1156, 'h' => 521],
    ['cat' => 'servicio-tecnico', 'label' => 'Servicio técnico', 'alt' => 'Servicio técnico de Horus Group', 'src' => $base.'nuestro servicio tecnico/imagen 7.jpg', 'w' => 1280, 'h' => 575],
    ['cat' => 'primeros-auxilios', 'label' => 'Práctica de primeros auxilios', 'alt' => 'Práctica de primeros auxilios', 'src' => $base.'practicas de primeros auxilios/imagen 1.jpg', 'w' => 1280, 'h' => 575],
    ['cat' => 'primeros-auxilios', 'label' => 'Práctica de primeros auxilios', 'alt' => 'Práctica de primeros auxilios', 'src' => $base.'practicas de primeros auxilios/imagen 7.jpg', 'w' => 1156, 'h' => 521],
  ];
@endphp
<div class="legacy-showcase">
<section class="gal-hero">
  <div class="gal-hero-in">
    <div class="gal-orn" aria-hidden="true"><i></i><b></b><i></i></div>
    <h1>Nuestra <span>galería</span></h1>
    <p>Momentos que inspiran</p>
  </div>
</section>

<div class="gal-filters" role="group" aria-label="Filtrar fotos por categoría">
  <div class="gal-filters-in">
    <button type="button" class="gal-chip" data-filter="all" aria-pressed="true">Todas <small>{{ count($fotos) }}</small></button>
    @foreach ($categorias as $clave => $nombre)
      <button type="button" class="gal-chip" data-filter="{{ $clave }}" aria-pressed="false">{{ $nombre }} <small>{{ count(array_filter($fotos, fn ($f) => $f['cat'] === $clave)) }}</small></button>
    @endforeach
  </div>
</div>

<section class="gal-section" id="gallery">
  <div class="gal-grid" id="galleryGrid">
    @foreach ($fotos as $foto)
      <button type="button" class="gal-shot" data-category="{{ $foto['cat'] }}" data-full="{{ asset($foto['src']) }}" data-alt="{{ $foto['alt'] }}" data-label="{{ $foto['label'] }}" aria-label="Ampliar foto: {{ $foto['label'] }}">
        <span class="gal-ph">
          <img src="{{ asset($foto['src']) }}" alt="{{ $foto['alt'] }}" width="{{ $foto['w'] }}" height="{{ $foto['h'] }}" loading="{{ $loop->index < 3 ? 'eager' : 'lazy' }}" decoding="async" style="object-position: {{ $foto['pos'] ?? 'center' }}">
          <span class="gal-zoom" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg></span>
        </span>
        <span class="gal-cap"><span>{{ $foto['label'] }}</span><em>{{ $categorias[$foto['cat']] }}</em></span>
      </button>
    @endforeach
  </div>

  <div class="gal-more">
    <p>¿Quieres ver más?</p>
    <a href="https://www.instagram.com/horusgroupsrl/" target="_blank" rel="noopener noreferrer" aria-label="Visita nuestro Instagram (se abre en una pestaña nueva)">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor"/></svg>
      <span>Visita nuestro Instagram</span>
    </a>
  </div>
</section>

<div class="gal-lb" id="galLightbox" role="dialog" aria-modal="true" aria-label="Visor de fotos" hidden>
  <button type="button" class="gal-lb-x" id="galLbClose" aria-label="Cerrar">&#10005;</button>
  <button type="button" class="gal-lb-nav gal-lb-p" id="galLbPrev" aria-label="Foto anterior">&#8249;</button>
  <button type="button" class="gal-lb-nav gal-lb-n" id="galLbNext" aria-label="Foto siguiente">&#8250;</button>
  <figure>
    <img id="galLbImg" alt="">
    <figcaption><span id="galLbCaption"></span><small id="galLbCount"></small></figcaption>
  </figure>
</div>
</div>
@endsection
