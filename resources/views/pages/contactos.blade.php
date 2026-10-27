@extends('layouts.app')

@section('title', 'Contáctanos')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/contactos/contactos.css') }}">
@endpush

@section('content')
<div class="legacy-showcase">
<section class="contactos-hero">
        <div class="contactos-hero-contenido">
            <h1>CONTÁCTANOS</h1>
            <p>Estamos aquí para ayudarte. Envíanos tu consulta y te responderemos a la brevedad</p>
        </div>
    </section>

    <section class="contactos-principal">
        <div class="contactos-contenedor">

            <div class="contactos-info">
                <h2>INFORMACIÓN DE CONTACTO</h2>
                <p>Puedes comunicarte con nosotros a través de los siguientes medios</p>

                <div class="info-items">
                    <div class="info-item">
                        <div class="info-icono">
                            <i class="fas fa-phone"></i>
                        </div>
                        <div class="info-texto">
                            <h3>TELÉFONO</h3>
                            <p>+51 927 582 305</p>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-icono">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <div class="info-texto">
                            <h3>EMAIL</h3>
                            <p>horusgroupcajamarca@gmail.com</p>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-icono">
                            <i class="fas fa-map-marker-alt"></i>
                        </div>
                        <div class="info-texto">
                            <h3>UBICACIÓN</h3>
                            <p>Jr. José Gálvez #322</p>
                            <p>Cajamarca, Perú</p>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-icono">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="info-texto">
                            <h3>HORARIO DE ATENCIÓN</h3>
                            <p>Lunes - Viernes: 9:00 AM - 6:00 PM</p>
                            <p>Sábados: 9:00 AM - 1:00 PM</p>
                        </div>
                    </div>
                </div>

                <div class="redes-sociales">
                    <h3>SÍGUENOS EN REDES SOCIALES</h3>
                    <div class="social-links">
                        <a href="https://www.facebook.com/share/174BEdCReB/" class="social-link facebook"><i
                                class="fab fa-facebook-f"></i></a>
                        <a href="https://www.youtube.com/watch?v=mf2sVtzRAlI" class="social-link whatsapp"><i
                                class="fab fa-whatsapp"></i></a>
                        <a href="#" class="social-link youtube"><i class="fab fa-youtube"></i></a>
                    </div>
                    <a href="libro-reclamaciones.html" class="footer-libro-link">
                        <div class="libro-link-icon"><i class="fas fa-book-open"></i></div>
                        <div class="libro-link-texto">
                            <strong>Libro de Reclamaciones</strong>
                            <span>Ley N° 29571 – INDECOPI</span>
                        </div>
                    </a>
                </div>
            </div>

            <div class="contactos-formulario">
                <h2>ENVÍANOS UN MENSAJE</h2>
                <form id="contactForm" x-data="contactForm()" @submit.prevent="send($event)">
                    <div class="form-grupo">
                        <label for="nombre">Nombre Completo:</label>
                        <input type="text" id="nombre" name="nombre" required>
                    </div>

                    <div class="form-grupo">
                        <label for="email">Correo Electrónico:</label>
                        <input type="email" id="email" name="email" required>
                    </div>

                    <div class="form-grupo">
                        <label for="telefono">Teléfono:</label>
                        <input type="tel" id="telefono" name="telefono" required>
                    </div>

                    <div class="form-grupo">
                        <label for="asunto">Asunto:</label>
                        <select id="asunto" name="asunto" required>
                            <option value="">Selecciona un asunto</option>
                            <option value="tecnologias">Tecnologías</option>
                            <option value="educacion">Educación</option>
                            <option value="asesoramiento">Asesoramiento</option>
                            <option value="capacitaciones">Capacitaciones</option>
                            <option value="cursos">Cursos</option>
                            <option value="otro">Otro</option>
                        </select>
                    </div>

                    <div class="form-grupo">
                        <label for="mensaje">Mensaje:</label>
                        <textarea id="mensaje" name="mensaje" rows="5" required></textarea>
                    </div>

                    <button type="submit" class="btn-enviar">
                        <i class="fas fa-paper-plane"></i> Enviar Mensaje
                    </button>
                </form>
            </div>

        </div>
    </section>
</div>
@endsection

