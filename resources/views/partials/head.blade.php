<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="@yield('meta_description', 'Horus Group SRL - Soluciones en tecnología, educación y soporte en Cajamarca, Perú.')">
<link rel="canonical" href="{{ url()->current() }}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Horus Group SRL">
<meta property="og:locale" content="es_PE">
<meta property="og:title" content="@yield('title', 'Horus Group SRL')">
<meta property="og:description" content="@yield('meta_description', 'Horus Group SRL - Soluciones en tecnología, educación y soporte en Cajamarca, Perú.')">
<meta property="og:url" content="{{ url()->current() }}">
<meta property="og:image" content="@yield('og_image', asset('assets/images/index/logos/logo-horus.png'))">
<link rel="icon" type="image/png" href="{{ asset('assets/images/index/logos/logo-horus.png') }}">
<title>@yield('title', 'Horus Group SRL')</title>
@stack('head')
@vite(['resources/css/app.css', 'resources/js/app.js'])
@stack('styles')
