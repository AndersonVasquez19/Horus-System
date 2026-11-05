@extends('layouts.app')

@section('title', 'Soporte y Mantenimiento')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/tecnologias/soporte-mantenimiento.css') }}">
@endpush

@section('content')
<div class="legacy-showcase">
@if(!empty($contenidoServicio))
@include('pages.tecnologias.partials.dinamico-soporte', ['c' => $contenidoServicio])
@else
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
@endif
    @if($catalogoServiciosHabilitado ?? false)
    <section id="catalogo-servicios" class="cartas-section" aria-labelledby="titulo-catalogo-servicios">
        <div class="container">
            <h2 id="titulo-catalogo-servicios">Catálogo de servicios tecnológicos</h2>
            @if($catalogoServiciosFallo)
                <p>No podemos mostrar los servicios publicados en este momento. Puede contactarnos para más información.</p>
            @elseif($serviciosTotal === 0)
                <p>No hay fichas de servicios publicadas vigentes.</p>
            @else
                <p>Total de fichas autorizadas vigentes: {{ $serviciosTotal }}. Página {{ $serviciosPagina }} de {{ (int) ceil($serviciosTotal / $serviciosPorPagina) }}.
                    @if(($serviciosPagina - 1) * $serviciosPorPagina < $serviciosTotal)
                        Rango consultado: {{ ($serviciosPagina - 1) * $serviciosPorPagina + 1 }}–{{ min($serviciosPagina * $serviciosPorPagina, $serviciosTotal) }}.
                    @else
                        Página fuera del rango actual.
                    @endif
                    Fichas presentadas: {{ count($serviciosPublicados) }}. Orden de registro.</p>
                @if(count($serviciosPublicados) === 0)
                    <p>No hay fichas disponibles en esta página.</p>
                @endif
                <div class="carta-grid">
                    @foreach($serviciosPublicados as $servicio)
                    <article class="carta-card min-w-0" style="overflow-wrap: anywhere;">
                        <h3>{{ $servicio['titulo'] }}</h3>
                        <p>{{ $servicio['resumen'] }}</p>
                        @if(!empty($servicio['categoria']))
                            <p>Categoría: {{ $servicio['categoria'] }}</p>
                        @endif
                        <p style="white-space: pre-line;">{{ $servicio['descripcion'] }}</p>
                        <a class="inline-flex items-center min-h-[44px] underline" href="{{ route('contactos') }}" aria-label="Consultar servicio: {{ $servicio['titulo'] }}">Consultar servicio</a>
                    </article>
                    @endforeach
                </div>
                <nav aria-label="Páginas del catálogo de servicios" class="flex flex-wrap items-center gap-6 mt-6">
                    @if($serviciosPagina > 1)
                        <a class="inline-flex items-center min-h-[44px] underline" rel="prev" href="{{ route('tecnologias.soporte', ['servicios_pagina' => $serviciosPagina - 1]) }}#catalogo-servicios">Página anterior</a>
                    @endif
                    @if($serviciosPagina * $serviciosPorPagina < $serviciosTotal)
                        <a class="inline-flex items-center min-h-[44px] underline" rel="next" href="{{ route('tecnologias.soporte', ['servicios_pagina' => $serviciosPagina + 1]) }}#catalogo-servicios">Página siguiente</a>
                    @endif
                </nav>
            @endif
            @if($catalogoServiciosFallo || $serviciosTotal === 0)
                <a class="inline-flex items-center min-h-[44px] underline" href="{{ route('contactos') }}">Contactar con Horus Group</a>
            @endif
        </div>
    </section>
    @endif
</div>
@endsection

