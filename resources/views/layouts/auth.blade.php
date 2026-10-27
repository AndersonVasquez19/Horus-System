<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') | Horus Group</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="auth-scene">
    <div class="auth-orbit auth-orbit--one" aria-hidden="true"></div>
    <div class="auth-orbit auth-orbit--two" aria-hidden="true"></div>
    <main class="auth-card">
        <aside class="auth-art">
            <img src="{{ asset('assets/images/index/carrusel/Car4.jpg') }}" alt="" class="auth-art-photo">
            <div class="auth-art-overlay"></div>
            <div class="auth-art-content">
                <a href="{{ route('home') }}" class="auth-brand" aria-label="Volver a Horus Group">
                    <img src="{{ asset('assets/images/index/logos/logo-horus.png') }}" alt="" class="auth-brand-logo">
                    <span>HORUS <b>GROUP</b><small>TECNOLOGÍA · EDUCACIÓN · FUTURO</small></span>
                </a>
                <div class="auth-art-message">
                    <span class="auth-eyebrow">CRECE CON CONFIANZA</span>
                    <h1>Ideas que<br><span>abren caminos.</span></h1>
                    <p>Un solo lugar para conectar con tecnología, aprendizaje y nuevas oportunidades.</p>
                </div>
                <div class="auth-art-footer"><span class="auth-live-dot"></span> Cajamarca, Perú <span class="auth-footer-line"></span> Horus Group SRL</div>
            </div>
        </aside>
        <section class="auth-form-side">
            <div class="auth-form-inner">
                @yield('form-content')
            </div>
        </section>
    </main>
    <p class="auth-copyright">© {{ date('Y') }} Horus Group SRL <span>·</span> Cajamarca, Perú</p>
</body>
</html>
