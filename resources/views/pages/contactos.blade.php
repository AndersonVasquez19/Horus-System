@extends('layouts.app')

@section('title', 'Contáctanos')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/contactos/contactos.css') }}">
@endpush

@push('scripts')
  <script src="{{ asset('assets/js/contactos-form.js') }}" defer></script>
@endpush

@section('content')
@php
  $telefono = '+51 927 582 305';
  $correo = 'horusgroupcajamarca@gmail.com';
  $mapaUrl = 'https://www.google.com/maps/search/?api=1&query='.rawurlencode('Jr. José Gálvez 322 Cajamarca');
@endphp
<div class="legacy-showcase">
<section class="ct-hero">
  <div class="ct-wrap ct-hero-in">
    <div>
      <h1>Contáctanos</h1>
      <p>Estamos aquí para ayudarte. Envíanos tu consulta y te responderemos a la brevedad.</p>
    </div>
    <div class="ct-quick">
      <a class="ct-q ct-q-main" href="https://wa.me/51927582305" target="_blank" rel="noopener noreferrer">
        <i class="fa-brands fa-whatsapp" aria-hidden="true"></i>
        <span>Escribir por WhatsApp<small>927 582 305</small></span>
      </a>
      <a class="ct-q" href="tel:+51927582305">
        <i class="fa-solid fa-phone" aria-hidden="true"></i>
        <span>Llamar ahora<small>Lunes a sábado</small></span>
      </a>
      <a class="ct-q" href="#formulario">
        <i class="fa-solid fa-envelope" aria-hidden="true"></i>
        <span>Enviar un mensaje<small>Completa el formulario</small></span>
      </a>
    </div>
  </div>
</section>

<section class="ct-main">
  <div class="ct-wrap">
    <div class="ct-body">
      <section class="ct-data" aria-labelledby="ct-datos">
        <div>
          <h2 class="ct-title" id="ct-datos">Información de contacto</h2>
          <p class="ct-sub">Puedes comunicarte con nosotros a través de los siguientes medios.</p>
        </div>
        <div class="ct-rows">
          <div class="ct-row">
            <i class="fa-solid fa-phone" aria-hidden="true"></i>
            <div><h3>Teléfono y WhatsApp</h3><p>{{ $telefono }}</p></div>
            <button class="ct-copy" type="button" data-copy="{{ $telefono }}">Copiar</button>
          </div>
          <div class="ct-row">
            <i class="fa-solid fa-envelope" aria-hidden="true"></i>
            <div><h3>Correo</h3><p>{{ $correo }}</p></div>
            <button class="ct-copy" type="button" data-copy="{{ $correo }}">Copiar</button>
          </div>
          <div class="ct-row">
            <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
            <div><h3>Ubicación</h3><p>Jr. José Gálvez #322<span>Cajamarca, Perú</span></p></div>
            <a class="ct-lnk" href="{{ $mapaUrl }}" target="_blank" rel="noopener noreferrer">Cómo llegar</a>
          </div>
          <div class="ct-row">
            <i class="fa-solid fa-clock" aria-hidden="true"></i>
            <div><h3>Horario de atención</h3><p>Lunes - Viernes: 9:00 AM - 6:00 PM<span>Sábados: 9:00 AM - 1:00 PM</span></p></div>
            <span></span>
          </div>
        </div>
      </section>

      <section class="ct-form" id="formulario" aria-labelledby="ct-form-title">
        <h2 class="ct-title" id="ct-form-title">Envíanos un mensaje</h2>
        <p class="ct-sub">Elige cómo enviarlo: por WhatsApp o desde tu correo. El mensaje se arma con lo que escribas aquí.</p>
        <form id="contactForm" novalidate>
          <div class="ct-field">
            <label for="nombre">Nombre completo</label>
            <input type="text" id="nombre" name="nombre" autocomplete="name" placeholder="Ej. María Pérez">
          </div>
          <div class="ct-field">
            <label for="telefono">Teléfono <span>(opcional)</span></label>
            <input type="tel" id="telefono" name="telefono" autocomplete="tel" placeholder="Ej. 927 000 000">
          </div>
          <div class="ct-field">
            <label for="email">Correo <span>(opcional)</span></label>
            <input type="email" id="email" name="email" autocomplete="email" placeholder="nombre@correo.com">
          </div>
          <div class="ct-field">
            <label for="asunto">Asunto</label>
            <select id="asunto" name="asunto">
              <option value="">Selecciona un asunto</option>
              <option value="Tecnologías">Tecnologías</option>
              <option value="Educación">Educación</option>
              <option value="Asesoramiento">Asesoramiento</option>
              <option value="Capacitaciones">Capacitaciones</option>
              <option value="Cursos">Cursos</option>
              <option value="Otro">Otro</option>
            </select>
          </div>
          <div class="ct-field ct-full">
            <label for="mensaje">Mensaje</label>
            <textarea id="mensaje" name="mensaje" rows="5" placeholder="Cuéntanos qué necesitas"></textarea>
          </div>
          <div class="ct-send">
            <a class="ct-btn ct-btn-wa" id="enviarWhatsapp" href="https://wa.me/51927582305" target="_blank" rel="noopener noreferrer">
              <i class="fa-brands fa-whatsapp" aria-hidden="true"></i> Enviar por WhatsApp
            </a>
            <a class="ct-btn ct-btn-mail" id="enviarCorreo" href="mailto:{{ $correo }}">
              <i class="fa-solid fa-envelope" aria-hidden="true"></i> Enviar por correo
            </a>
          </div>
          <p class="ct-hint">Se abre WhatsApp o tu aplicación de correo con el mensaje listo. No guardamos lo que escribes aquí.</p>
        </form>
      </section>
    </div>

    <div class="ct-map">
      <iframe title="Mapa de la ubicación de Horus Group en Cajamarca" src="https://www.google.com/maps/embed?pb=!1m14!1m8!1m3!1d989.6682159535815!2d-78.5107197!3d-7.1637652!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x91b25b809bf9b7e3%3A0x70d5a58b14aa7eff!2sPlazuela%20Bolognesi!5e0!3m2!1ses-419!2spe!4v1772667902105!5m2!1ses-419!2spe" loading="lazy" allowfullscreen referrerpolicy="no-referrer-when-downgrade"></iframe>
      <div class="ct-map-label">
        <p><strong>Horus Group SRL</strong>Jr. José Gálvez N° 322, Cajamarca</p>
        <a href="{{ $mapaUrl }}" target="_blank" rel="noopener noreferrer">Abrir en Google Maps</a>
      </div>
    </div>
  </div>
</section>
</div>
@endsection
