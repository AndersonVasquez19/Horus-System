@extends('layouts.app')

@section('title', 'Soporte y Mantenimiento')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/tecnologias/soporte-mantenimiento.css') }}">
@endpush

@section('content')
<div class="legacy-showcase">
<section class="carta-soporte-mantenimiento">
        <div class="intro-soporte-mantenimiento">
            <div class="info-soporte-mantenimiento">
                <div class="texto-intro">
                    <h2>SOPORTE Y MANTENIMIENTO</h2>
                    <p>Contamos con un gran equipo de trabajo en TI donde te ofrecemos soluciones físicas (hardware) o
                        lógicas (software) de PC, sistemas y accesorios.</p>
                    <p>Te ofrecemos mantenimiento preventivo y correctivo en tus equipos informáticos donde te ayuden a
                        seguir con la productividad de estos.</p>
                </div>
                <div class="imagen-intro">
                    <img src="{{ asset('assets/images/soporte-mantenimiento/img-intro.png') }}" alt="">
                </div>
            </div>
        </div>
    </section>

    <section class="cartas-section">
        <div class="container">
            <h2>Nuestros Servicios</h2>
            <div class="carta-grid">
                <div class="carta-card">
                    <div class="carta-icon">
                        <i class="fas fa-cogs"></i>
                    </div>
                    <h3>CONFIGURACIÓN E INSTALACIÓN</h3>
                    <p>Nos encargamos de dejar todos sus equipos y sistemas correctamente configurados y listos para su
                        uso inmediato, garantizando un funcionamiento óptimo desde el primer día.</p>
                    <ul>
                        <li>Configuración y sincronización de correos electrónicos corporativos.</li>
                        <li>Instalación y configuración de impresoras y optimización de la red Wi-Fi en oficinas.</li>
                        <li>Preparación e instalación de equipos para nuevos colaboradores.</li>
                    </ul>
                </div>

                <div class="carta-card">
                    <div class="carta-icon">
                        <i class="fas fa-tools"></i>
                    </div>
                    <h3>MANTENIMIENTO PREVENTIVO</h3>
                    <p>Implementamos acciones preventivas para evitar fallas técnicas y prolongar la vida útil de sus
                        equipos informáticos.</p>
                    <ul>
                        <li>Limpieza interna y externa de equipos para prevenir daños por polvo y sobrecalentamiento.
                        </li>
                        <li>Actualización de sistema operativo y software antivirus.</li>
                        <li>Verificación y supervisión de copias de seguridad (backups).</li>
                    </ul>
                </div>

                <div class="carta-card">
                    <div class="carta-icon">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <h3>ASESORÍA Y CAPACITACIÓN</h3>
                    <p>Brindamos orientación especializada para que su equipo aproveche al máximo las herramientas
                        tecnológicas disponibles.</p>
                    <ul>
                        <li>Capacitación en el uso de programas y sistemas informáticos.</li>
                        <li>Asesoramiento en la compra de equipos según necesidades y presupuesto.</li>
                        <li>Formación personalizada para optimizar procesos tecnológicos.</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>
</div>
@endsection

