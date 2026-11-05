{{-- Soporte y mantenimiento con contenido editorial autorizado. Mismas clases y estructura que el HTML estático. --}}
<section class="carta-soporte-mantenimiento">
        <div class="intro-soporte-mantenimiento">
            <div class="info-soporte-mantenimiento">
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
    </section>

    @if(count($c['tarjetas']) > 0)
    <section class="cartas-section">
        <div class="container">
            @if($c['titulo_tarjetas'])
            <h2>{{ $c['titulo_tarjetas'] }}</h2>
            @endif
            <div class="carta-grid">
                @foreach($c['tarjetas'] as $tarjeta)
                <div class="carta-card">
                    @if($tarjeta['icono'])
                    <div class="carta-icon">
                        <i class="{{ $tarjeta['icono'] }}"></i>
                    </div>
                    @endif
                    @if($tarjeta['imagen_url'])
                    <img src="{{ $tarjeta['imagen_url'] }}" alt="{{ $tarjeta['imagen_alt'] }}" loading="lazy" decoding="async" style="max-width: 100%; height: auto;">
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
                </div>
                @endforeach
            </div>
        </div>
    </section>
    @endif
