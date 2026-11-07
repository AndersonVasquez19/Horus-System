@extends('layouts.app')

@section('title', 'Horus Group SRL | Tecnología, capacitaciones y soporte en Cajamarca')
@section('meta_description', 'Horus Group SRL: cableado estructurado, cámaras de seguridad, soporte técnico, capacitaciones y asesoramiento para hogares y empresas en Cajamarca, Perú.')

@push('head')
  <link rel="preload" as="image" href="{{ asset('assets/images/index/hero/hero-tecnologia.jpg') }}" fetchpriority="high">
  <script type="application/ld+json">
    {!! json_encode([
      '@context' => 'https://schema.org',
      '@type' => 'LocalBusiness',
      'name' => 'Horus Group SRL',
      'url' => url('/'),
      'logo' => asset('assets/images/index/logos/logo-horus.png'),
      'image' => asset('assets/images/index/hero/hero-tecnologia.jpg'),
      'description' => 'Soluciones tecnológicas, capacitaciones y soporte para hogares, empresas y profesionales en Cajamarca, Perú.',
      'telephone' => '+51927582305',
      'areaServed' => ['@type' => 'City', 'name' => 'Cajamarca'],
      'hasOfferCatalog' => [
        '@type' => 'OfferCatalog',
        'name' => 'Servicios de Horus Group SRL',
        'itemListElement' => collect([
          'Cámaras de seguridad', 'Cableado estructurado', 'Soporte y mantenimiento', 'Cursos con certificado', 'Capacitaciones', 'Asesoramiento',
        ])->map(fn ($servicio) => ['@type' => 'Offer', 'itemOffered' => ['@type' => 'Service', 'name' => $servicio]])->all(),
      ],
      'email' => 'horusgroupcajamarca@gmail.com',
      'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Cajamarca', 'addressCountry' => 'PE'],
      'sameAs' => [
        'https://www.facebook.com/share/174BEdCReB/',
        'https://www.instagram.com/horusgroupsrl/',
        'https://www.tiktok.com/@horus.group.srl',
        'https://www.youtube.com/@HORUSGROUPSRL',
      ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}
  </script>
@endpush

@section('content')
  @php
    $slides = [
      [
        'image' => 'assets/images/index/hero/hero-tecnologia.jpg',
        'position' => 'center 38%',
        'icon' => 'fa-solid fa-tower-broadcast',
        'eyebrow' => 'Telecomunicaciones y tecnología',
        'title' => 'Soluciones tecnológicas',
        'highlight' => 'para tu hogar y empresa',
        'text' => 'Instalación de cámaras de videovigilancia, cableado estructurado y soporte técnico de equipos informáticos en Cajamarca.',
        'cta' => ['label' => 'Conoce nuestros servicios', 'url' => route('tecnologias.camaras')],
        'cta2' => ['label' => 'Contáctanos', 'url' => route('contactos')],
      ],
      [
        'image' => 'assets/images/index/hero/hero-capacitaciones.jpg',
        'position' => 'center 68%',
        'icon' => 'fa-solid fa-graduation-cap',
        'eyebrow' => 'Educación y capacitación',
        'title' => 'Capacítate y certifica',
        'highlight' => 'tus habilidades profesionales',
        'text' => 'Cursos, talleres y capacitaciones para estudiantes y profesionales, con certificados válidos para postulaciones a entidades públicas y privadas.',
        'cta' => ['label' => 'Ver cursos', 'url' => route('educacion.cursos')],
        'cta2' => ['label' => 'Capacitaciones', 'url' => route('educacion.capacitaciones')],
      ],
      [
        'image' => 'assets/images/index/hero/hero-convenios.jpg',
        'position' => 'center 55%',
        'icon' => 'fa-solid fa-handshake',
        'eyebrow' => 'Convenios estratégicos',
        'title' => 'Respaldados por',
        'highlight' => 'colegios profesionales',
        'text' => 'Trabajamos con los Colegios de Enfermeros de Cajamarca y La Libertad, Economistas y Licenciados en Administración, además del instituto ISAM.',
        'cta' => ['label' => 'Ver convenios', 'url' => '#convenios'],
        'cta2' => ['label' => 'Quiénes somos', 'url' => route('quienes-somos')],
      ],
      [
        'image' => 'assets/images/index/hero/hero-asesoramiento.jpg',
        'position' => 'center 62%',
        'icon' => 'fa-solid fa-user-tie',
        'eyebrow' => 'Asesoría profesional',
        'title' => 'Asesoramiento',
        'highlight' => 'educativo, empresarial y legal',
        'text' => 'Profesionales con amplia experiencia que te orientan en el desarrollo de tus proyectos personales y profesionales.',
        'cta' => ['label' => 'Solicitar asesoramiento', 'url' => route('educacion.asesoramiento')],
        'cta2' => ['label' => 'Contáctanos', 'url' => route('contactos')],
      ],
    ];

    $heroStats = [
      ['value' => '+400', 'label' => 'Personas capacitadas'],
      ['value' => '4.8/5', 'label' => 'Calificación promedio'],
      ['value' => '+200', 'label' => 'Trabajos realizados'],
      ['value' => '98%', 'label' => 'Participantes que fortalecieron sus habilidades'],
    ];

    $convenios = app(\App\Services\ConveniosPublicos::class)->tarjetas();
  @endphp

  <section id="inicio" class="home-hero">
    <x-carousel :slides="$slides" :stats="$heroStats" />
  </section>

  <section id="convenios" class="home-convenios scroll-mt-6 py-16 md:py-20">
    <div class="section-shell">
      <x-convenios.section
        :items="$convenios"
        title="Convenios estratégicos"
        intro="Alianzas con colegios profesionales e instituciones que respaldan la validez de nuestras certificaciones a nivel nacional."
      />
    </div>
  </section>

  <section class="home-location py-12 md:py-16">
    <div class="section-shell"><x-schedule-location title="Horarios y Ubicación" /></div>
  </section>
@endsection
