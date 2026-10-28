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
    ];

    $heroHighlights = [
      ['icon' => 'fa-solid fa-handshake', 'title' => 'Convenios institucionales', 'text' => 'Con colegios profesionales e instituciones educativas.'],
      ['icon' => 'fa-solid fa-certificate', 'title' => 'Certificados con respaldo', 'text' => 'Válidos para postulaciones a entidades públicas y privadas.'],
    ];

    $stats = [
      ['icon' => 'fa-solid fa-user-check', 'value' => '+400', 'label' => 'Personas capacitadas y certificadas entre estudiantes y profesionales a lo largo del país.'],
      ['icon' => 'fa-solid fa-star', 'value' => '4.8/5', 'label' => 'Calificación promedio en nuestros cursos y capacitaciones.'],
      ['icon' => 'fa-solid fa-building', 'value' => '+200', 'label' => 'Trabajos realizados a hogares y empresas en diversas áreas.'],
      ['icon' => 'fa-solid fa-laptop', 'value' => '98%', 'label' => 'De nuestros participantes mencionan haber fortalecido sus habilidades y conocimientos.'],
    ];

    $convenios = [
      [
        'id' => 'cep-cr13',
        'nombre' => 'Colegio de Enfermeros de Cajamarca - CR XIII',
        'siglas' => 'CEP / CR XIII',
        'vigencia' => '18 de julio del 2027',
        'descripcion' => 'Convenio Interinstitucional con Colegio de Enfermeros de Cajamarca. La empresa Horus Group SRL firma convenio con el Colegio de Enfermeros de Cajamarca - CR XIII a través de su representante el Decano Mg. Manuel Hoyos Torres para la realización de talleres, charlas, cursos y diplomados en las distintas ramas de la Enfermería. Dicho convenio beneficia a los participantes en los certificados emitidos de dichos eventos con firma del Decano del Colegio de Enfermeros de Cajamarca, así como representantes de la Empresa. Dichos certificados son válidos para las postulaciones a entidades públicas y privadas según los requisitos establecidos de las mismas.',
        'imagen' => 'assets/images/index/convenios-fotos/convenio-cep-cr13.jpg',
        'posicion' => 'center 40%',
        'logo' => 'assets/images/index/logos/logo-convenio-enfermeros.png',
      ],
      [
        'id' => 'corlad',
        'nombre' => 'Colegio Regional de Licenciados en Administración de Cajamarca',
        'siglas' => 'CORLAD',
        'vigencia' => '17 de febrero del 2027',
        'descripcion' => 'Convenio Interinstitucional con Colegio Regional de Licenciados en Administración de Cajamarca. La empresa Horus Group SRL firma convenio con el Colegio Regional de Licenciados en Administración de Cajamarca - CORLAD - a través de su representante el Decano Dr. José Luis Medina para la realización de talleres, charlas, cursos y diplomados en las distintas ramas de las Ciencias Administrativas. Dicho convenio beneficia a los participantes en los certificados emitidos de dichos eventos con firma del Decano del Colegio Regional de Licenciados en Administración de Cajamarca, así como representantes de la Empresa. Dichos certificados son válidos para las postulaciones a entidades públicas y privadas según los requisitos establecidos de las mismas.',
        'imagen' => 'assets/images/index/convenios-fotos/convenio-corlad.jpg',
        'posicion' => 'center 45%',
        'logo' => 'assets/images/index/logos/logo-convenio-administracion.jpg',
      ],
      [
        'id' => 'economistas',
        'nombre' => 'Colegio de Economistas de Cajamarca',
        'siglas' => 'CEC',
        'vigencia' => '6 de febrero del 2027',
        'descripcion' => 'Convenio Interinstitucional con Colegio de Economistas de Cajamarca. La empresa Horus Group SRL firma convenio con el Colegio de Economistas de Cajamarca a través de su representante el Decano Dr. José Luis Medina para la realización de talleres, charlas, cursos y diplomados en las distintas ramas de la Ciencia Económica. Dicho convenio beneficia a los participantes en los certificados emitidos de dichos eventos con firma del Decano del Colegio de Economistas, así como representantes de la Empresa. Dichos certificados son válidos para las postulaciones a entidades públicas y privadas según los requisitos establecidos de las mismas.',
        'imagen' => 'assets/images/index/convenios-fotos/convenio-economistas.jpg',
        'posicion' => 'center 35%',
        'logo' => 'assets/images/index/logos/logo-convenio-economistas.jpg',
      ],
      [
        'id' => 'isam',
        'nombre' => 'Instituto de Educación Superior ISAM',
        'siglas' => 'ISAM',
        'vigencia' => '6 de enero del 2027',
        // El HTML original asignaba a ISAM el texto del Colegio de Economistas; se reemplazó por una descripción propia. Confirmar con la empresa.
        'descripcion' => 'Convenio Interinstitucional con el Instituto de Educación Superior ISAM. La empresa Horus Group SRL firma convenio con el instituto para la realización de talleres, charlas y cursos, con entrega de certificados a los participantes.',
        'imagen' => 'assets/images/index/convenios-fotos/convenio-isam.jpg',
        'posicion' => 'center',
        'logo' => 'assets/images/index/logos/logo-convenio-isam.png',
      ],
      [
        'id' => 'cep-cr2',
        'nombre' => 'Colegio de Enfermeros del Perú - CR II La Libertad',
        'siglas' => 'CEP / CR II',
        'vigencia' => '4 de junio del 2029',
        'descripcion' => 'Convenio Interinstitucional con el Colegio de Enfermeros del Perú - Consejo Regional II La Libertad. La empresa Horus Group SRL firma convenio con el CEP / CR II para la realización de talleres, charlas, cursos y diplomados en las distintas ramas de la Enfermería, en beneficio de los participantes.',
        'imagen' => null,
        'posicion' => null,
        'logo' => 'assets/images/index/logos/logo-convenio-enfermeros.png',
      ],
    ];
  @endphp

  <section id="inicio" class="home-hero">
    <x-carousel :slides="$slides" :stats="$heroStats" :highlights="$heroHighlights" />
  </section>

  <section class="home-stats py-12 md:py-16">
    <div class="section-shell"><x-stats-grid :stats="$stats" /></div>
  </section>

  <section id="convenios" class="home-convenios scroll-mt-6 py-12 md:py-16">
    <div class="section-shell">
    <div class="mb-8 text-center">
      <x-ui.section-title>Nuestros Convenios Estratégicos</x-ui.section-title>
    </div>
    <x-convenios.section :items="$convenios" />
    </div>
  </section>

  <section class="home-location py-12 md:py-16">
    <div class="section-shell"><x-schedule-location title="Horarios y Ubicación" /></div>
  </section>
@endsection
