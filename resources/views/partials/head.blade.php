<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="Horus Group SRL - Soluciones en tecnología, educación y soporte en Cajamarca, Perú.">
<link rel="icon" type="image/png" href="{{ asset('assets/images/index/logos/logo-horus.png') }}">
<title>@yield('title', 'Horus Group SRL')</title>
@if (request()->routeIs('home'))
  <link rel="preload" as="image" href="{{ asset('assets/images/index/carrusel/Car.jpg') }}" fetchpriority="high">
@endif
@vite(['resources/css/app.css', 'resources/js/app.js'])
@stack('styles')
