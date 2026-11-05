{{-- Cableado estructurado con contenido editorial autorizado. Mismas clases y estructura que el HTML estático. --}}
<div class="legacy-showcase">
<section class="cartas-tecnologias">
        <div class="intro-cableado">
            <div class="info-cableado">
                <div class="texto-intro">
                    <h2>{{ $c['titulo'] }}</h2>
                    @foreach($c['parrafos'] as $parrafo)
                    <p>{{ $parrafo }}</p>
                    @endforeach
                </div>
                @if($c['imagen_url'])
                <div class="imagen-intro">
                    <img src="{{ $c['imagen_url'] }}" alt="{{ $c['imagen_alt'] }}">
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
                <div class="carta-imagen">
                    <img src="{{ $tarjeta['imagen_url'] }}" alt="{{ $tarjeta['imagen_alt'] }}" loading="lazy" decoding="async">
                </div>
                @endif
                <div class="carta-body">
                    <h3>{{ $tarjeta['titulo'] }}</h3>
                    <div class="carta-divider"></div>
                    <p>{{ $tarjeta['descripcion'] }}</p>
                    @foreach($tarjeta['caracteristicas'] as $especificacion)
                    <div class="carta-spec"><i class="{{ $tarjeta['icono'] ?? 'fas fa-bolt' }}"></i> {{ $especificacion }}</div>
                    @endforeach
                </div>
            </article>
            @endforeach
        </div>
        @endif
    </section>
</div>
