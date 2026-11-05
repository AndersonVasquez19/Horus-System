{{-- Videovigilancia con contenido editorial autorizado. Mismas clases y estructura que el HTML estático. --}}
<div class="legacy-showcase">
<section class="carta-videovigilancia">
        <div class="intro-videovigilancia">
            <div class="info-videovigilancia">
                <div class="texto-intro">
                    <h2>{{ $c['titulo'] }}</h2>
                    @foreach($c['parrafos'] as $parrafo)
                    <p>{{ $parrafo }}</p>
                    @endforeach
                </div>
                @if($c['imagen_url'])
                <div class="imagen-intro">
                    <img src="{{ $c['imagen_url'] }}" alt="{{ $c['imagen_alt'] }}" fetchpriority="high" decoding="async">
                </div>
                @endif
            </div>
        </div>

        @if($c['titulo_tarjetas'])
        <div class="titulo-categorias">
            <h1>{{ $c['titulo_tarjetas'] }}</h1>
        </div>
        @endif

        @if(count($c['tarjetas']) > 0)
        <div class="cartas-contenedor">
            @foreach($c['tarjetas'] as $tarjeta)
            <article class="carta">
                @if($tarjeta['imagen_url'])
                <div class="carta-imagen{{ $tarjeta['variante'] ? ' carta-imagen--'.$tarjeta['variante'] : '' }}">
                    <img src="{{ $tarjeta['imagen_url'] }}" alt="{{ $tarjeta['imagen_alt'] }}" loading="lazy" decoding="async">
                </div>
                @endif
                @if($tarjeta['icono'])
                <div class="carta-icon">
                    <i class="{{ $tarjeta['icono'] }}"></i>
                </div>
                @endif
                <h3>{{ $tarjeta['titulo'] }}</h3>
                <p>{{ $tarjeta['descripcion'] }}</p>
                @if(count($tarjeta['caracteristicas']) > 0)
                <ul>
                    @foreach($tarjeta['caracteristicas'] as $caracteristica)
                    <li>{{ $caracteristica }}</li>
                    @endforeach
                </ul>
                @endif
            </article>
            @endforeach
        </div>
        @endif
    </section>
</div>
