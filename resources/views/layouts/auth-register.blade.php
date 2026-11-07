<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('assets/images/index/logos/logo-horus.png') }}">
    <title>@yield('title') | Horus Group</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="registration-scene">
    <div class="registration-decoration" aria-hidden="true">
        <div class="registration-orbit registration-orbit--one"></div>
        <div class="registration-orbit registration-orbit--two"></div>
    </div>
    <main class="registration-card">
        <aside class="registration-art">
            <div class="registration-art-watermark" aria-hidden="true">
                <span class="registration-art-halo"></span>
                <img src="{{ asset('assets/images/index/logos/logo-horus.png') }}" alt="" width="588" height="425" decoding="async">
            </div>
            <div class="registration-art-content">
                <a href="{{ route('home') }}" class="registration-brand" aria-label="Volver a Horus Group">
                    <img src="{{ asset('assets/images/index/logos/logo-horus.png') }}" alt="" class="registration-brand-logo">
                    <span>HORUS <b>GROUP</b><small>TECNOLOGÍA · EDUCACIÓN · FUTURO</small></span>
                </a>
                <div class="registration-art-message">
                    <span class="registration-eyebrow">CRECE CON CONFIANZA</span>
                    <h1>Ideas que<br><span>abren caminos.</span></h1>
                    <p>Un solo lugar para conectar con tecnología, aprendizaje y nuevas oportunidades.</p>
                </div>
                <div class="registration-art-footer"><span class="registration-live-dot"></span> Cajamarca, Perú <span class="registration-footer-line"></span> Horus Group SRL</div>
            </div>
        </aside>
        <section class="registration-form-side">
            <div class="registration-form-inner">
                @yield('form-content')
            </div>
        </section>
    </main>
    <p class="registration-copyright">© {{ date('Y') }} Horus Group SRL <span>·</span> Cajamarca, Perú</p>
</body>
</html>
