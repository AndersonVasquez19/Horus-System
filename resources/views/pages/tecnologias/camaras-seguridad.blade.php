@extends('layouts.app')

@section('title', 'Cámaras de Seguridad')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/tecnologias/camaras-seguridad.css') }}">
@endpush

@section('content')
<div class="legacy-showcase">
<section class="carta-videovigilancia">
        <div class="intro-videovigilancia">
            <div class="info-videovigilancia">
                <div class="texto-intro">
                    <h2>SISTEMA DE VIDEOVIGILANCIA</h2>
                    <p>Desarrollamos proyectos de seguridad integral diseñados para proteger su hogar, empresa o
                        institución. Nuestras soluciones avanzadas le permiten mantener el control absoluto de sus
                        espacios, sin importar dónde se encuentre.</p>

                </div>
                <div class="imagen-intro">
                    <img src="{{ asset('assets/images/videovigilancia/camera.webp') }}" alt="Sistema de videovigilancia" fetchpriority="high" decoding="async">
                </div>
            </div>
        </div>

        <div class="cartas-contenedor">
            <article class="carta">
                <div class="carta-imagen carta-imagen--producto">
                    <img src="{{ asset('assets/images/videovigilancia/camara-ip.jpg') }}" alt="Cámara IP giratoria con conexión Wi-Fi" loading="lazy" decoding="async">
                </div>
                <div class="carta-icon">
                    <i class="fas fa-video"></i>
                </div>
                <h3>CÁMARAS IP</h3>
                <p>Garantizamos la seguridad de sus espacios con tecnología de última generación.</p>
            </article>

            <article class="carta">
                <div class="carta-imagen carta-imagen--producto">
                    <img src="{{ asset('assets/images/videovigilancia/camara-analogica.jpg') }}" alt="Cámara analógica HD tipo bala" loading="lazy" decoding="async">
                </div>
                <div class="carta-icon">
                    <i class="fas fa-video"></i>
                </div>
                <h3>CÁMARAS ANALÓGICAS</h3>
                <p>Es la solución ideal para quienes buscan un sistema de vigilancia estable, económico y de larga
                    duración.</p>
            </article>

            <article class="carta">
                <div class="carta-imagen carta-imagen--producto">
                    <img src="{{ asset('assets/images/videovigilancia/camara-full-hd.jpg') }}" alt="Cámara Full HD con visión infrarroja" loading="lazy" decoding="async">
                </div>
                <div class="carta-icon">
                    <i class="fas fa-video"></i>
                </div>
                <h3>CÁMARAS FULL HD</h3>
                <p>Si lo que buscas es que la imagen no se "pixele" al hacer zoom, la tecnología Full HD es la opción
                    indispensable.</p>
            </article>

            <article class="carta">
                <div class="carta-imagen carta-imagen--producto">
                    <img src="{{ asset('assets/images/videovigilancia/camara-domo.jpg') }}" alt="Cámara domo de vigilancia para interiores" loading="lazy" decoding="async">
                </div>
                <div class="carta-icon">
                    <i class="fas fa-video"></i>
                </div>
                <h3>CÁMARAS DOMO</h3>
                <p>Si necesitas vigilar interiores (oficinas, tiendas o casas) de forma elegante y resistente, la Cámara
                    Domo es la elección profesional.</p>
            </article>

            <article class="carta">
                <div class="carta-imagen carta-imagen--escena">
                    <img src="{{ asset('assets/images/videovigilancia/camara-360.jpg') }}" alt="Cámara de seguridad de 360 grados montada en el techo" loading="lazy" decoding="async">
                </div>
                <div class="carta-icon">
                    <i class="fas fa-video"></i>
                </div>
                <h3>CÁMARAS DE 360°</h3>
                <p>Si quieres ver todo el lugar al mismo tiempo con un diseño discreto en el techo, la Cámara de 360° es
                    la opción más eficiente.</p>
            </article>

            <article class="carta">
                <div class="carta-imagen carta-imagen--producto">
                    <img src="{{ asset('assets/images/videovigilancia/camara-tubo-bala.jpg') }}" alt="Cámara fija tipo bala para exteriores" loading="lazy" decoding="async">
                </div>
                <div class="carta-icon">
                    <i class="fas fa-video"></i>
                </div>
                <h3>CÁMARAS FIJAS (TUBO O BALA)</h3>
                <p>Si necesitas que la gente sepa que la estás vigilando y quieres proteger exteriores o pasillos
                    largos, la Cámara de Bala es la mejor herramienta.</p>
            </article>

            <article class="carta">
                <div class="carta-imagen carta-imagen--escena">
                    <img src="{{ asset('assets/images/videovigilancia/sensor-movimiento.jpg') }}" alt="Sensor de movimiento instalado en la pared" loading="lazy" decoding="async">
                </div>
                <div class="carta-icon">
                    <i class="fas fa-wifi"></i>
                </div>
                <h3>Sensores de Movimiento</h3>
                <p>Añaden una capa de inteligencia a su sistema de seguridad, permitiendo que la tecnología actúe por
                    usted.</p>
            </article>

            <article class="carta">
                <div class="carta-imagen carta-imagen--escena">
                    <img src="{{ asset('assets/images/videovigilancia/alarma-email.jpg') }}" alt="Ilustración de una alarma con aviso remoto" loading="lazy" decoding="async">
                </div>
                <div class="carta-icon">
                    <i class="fas fa-envelope"></i>
                </div>
                <h3>Alarma vía Email</h3>
                <p>Transforme su sistema de vigilancia en un centro de respuesta rápida.</p>
            </article>

            <article class="carta">
                <div class="carta-imagen carta-imagen--producto">
                    <img src="{{ asset('assets/images/videovigilancia/vision-nocturna.jpg') }}" alt="Cámara tipo bala con visión nocturna" loading="lazy" decoding="async">
                </div>
                <div class="carta-icon">
                    <i class="fas fa-moon"></i>
                </div>
                <h3>Visión Nocturna</h3>
                <p>Garantiza que la oscuridad no sea un obstáculo para la seguridad de sus espacios.</p>
            </article>

            <article class="carta">
                <div class="carta-imagen carta-imagen--escena">
                    <img src="{{ asset('assets/images/videovigilancia/visualizacion-dispositivos.jpg') }}" alt="Tableta mostrando varias cámaras de vigilancia en vivo" loading="lazy" decoding="async">
                </div>
                <div class="carta-icon">
                    <i class="fas fa-mobile-alt"></i>
                </div>
                <h3>Visualización desde tu Computadora o Celular</h3>
                <p>Manténgase conectado con lo que más le importa a través de sus dispositivos personales.</p>
            </article>
        </div>
    </section>
</div>
@endsection

