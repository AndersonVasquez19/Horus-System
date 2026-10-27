@extends('layouts.app')

@section('title', 'Cableado Estructurado')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/tecnologias/cableado-estructurado.css') }}">
@endpush

@section('content')
<div class="legacy-showcase">
<section class="cartas-tecnologias">
        <div class="intro-cableado">
            <div class="info-cableado">
                <div class="texto-intro">
                    <h2>CABLEADO ESTRUCTURADO</h2>
                    <p>Diseñamos e implementamos sistemas de cableado estructurado (UTP/STP) y redes LAN bajo estrictas
                        normas técnicas para garantizar una interconectividad eficiente y de alta velocidad entre todas
                        las áreas de su empresa.</p>
                </div>
                <div class="imagen-intro">
                    <img src="{{ asset('assets/images/tecnologias/cableado.jpg') }}" alt="Instalacion de cableado ">
                </div>
            </div>
        </div>

        <div class="titulo-categorias">
            <h1>CATEGORÍAS DE CABLEADO</h1>
        </div>

        <div class="cartas-contenedor">
            <article class="carta">
                <div class="carta-imagen">
                    <img src="{{ asset('assets/images/tecnologias/categoria1.jpg') }}" alt="Categoría 1" loading="lazy" decoding="async">
                </div>
                <div class="carta-body">
                    <h3>Categoría 1</h3>
                    <div class="carta-divider"></div>
                    <p>Diseñada para comunicaciones telefónicas analógicas. No es apta para transmisión de datos modernos debido a sus limitadas capacidades de frecuencia.</p>
                    <div class="carta-spec"><i class="fas fa-bolt"></i> Velocidad máx: 512 kbit/s</div>
                </div>
            </article>

            <article class="carta">
                <div class="carta-imagen">
                    <img src="{{ asset('assets/images/tecnologias/categoria2.webp') }}" alt="Categoría 2" loading="lazy" decoding="async">
                </div>
                <div class="carta-body">
                    <h3>Categoría 2</h3>
                    <div class="carta-divider"></div>
                    <p>Utilizada en redes Token Ring de primera generación. Soporta transmisión de voz y datos básicos en entornos de baja demanda.</p>
                    <div class="carta-spec"><i class="fas fa-bolt"></i> Velocidad máx: 4 Mbit/s</div>
                </div>
            </article>

            <article class="carta">
                <div class="carta-imagen">
                    <img src="{{ asset('assets/images/tecnologias/categoria3.webp') }}" alt="Categoría 3" loading="lazy" decoding="async">
                </div>
                <div class="carta-body">
                    <h3>Categoría 3</h3>
                    <div class="carta-divider"></div>
                    <p>Estándar para redes Ethernet 10BaseT. Ampliamente utilizada en instalaciones de voz y datos en edificios de oficinas durante los años 90.</p>
                    <div class="carta-spec"><i class="fas fa-bolt"></i> Velocidad máx: 10 Mbit/s</div>
                </div>
            </article>

            <article class="carta">
                <div class="carta-imagen">
                    <img src="{{ asset('assets/images/tecnologias/categoria4.jpg') }}" alt="Categoría 4" loading="lazy" decoding="async">
                </div>
                <div class="carta-body">
                    <h3>Categoría 4</h3>
                    <div class="carta-divider"></div>
                    <p>Optimizada para redes Token Ring de alta velocidad. Ofrece mayor ancho de banda que su predecesora, con mejor rendimiento en entornos corporativos.</p>
                    <div class="carta-spec"><i class="fas fa-bolt"></i> Velocidad máx: 16 Mbit/s</div>
                </div>
            </article>

            <article class="carta">
                <div class="carta-imagen">
                    <img src="{{ asset('assets/images/tecnologias/categoria5.jpeg') }}" alt="Categoría 5" loading="lazy" decoding="async">
                </div>
                <div class="carta-body">
                    <h3>Categoría 5</h3>
                    <div class="carta-divider"></div>
                    <p>Estándar para redes Fast Ethernet y Gigabit. Ideal para infraestructuras de red modernas que requieren alta velocidad y confiabilidad en la transmisión.</p>
                    <div class="carta-spec"><i class="fas fa-bolt"></i> Velocidad máx: 100 Mbit/s</div>
                </div>
            </article>

            <article class="carta">
                <div class="carta-imagen">
                    <img src="{{ asset('assets/images/tecnologias/categoria6.jpg') }}" alt="Categoría 6" loading="lazy" decoding="async">
                </div>
                <div class="carta-body">
                    <h3>Categoría 6</h3>
                    <div class="carta-divider"></div>
                    <p>Solución de alto rendimiento para centros de datos y redes empresariales. Soporta aplicaciones de video en alta definición y comunicaciones críticas.</p>
                    <div class="carta-spec"><i class="fas fa-bolt"></i> Velocidad máx: 10 Gbit/s</div>
                </div>
            </article>
        </div>
    </section>
</div>
@endsection

