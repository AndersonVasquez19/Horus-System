@extends('layouts.app')

@section('title', 'Horus Group SRL')

@section('content')
  @php
    $slides = [
      ['image' => 'assets/images/index/carrusel/Car.jpg', 'alt' => 'Horus Group'],
      ['image' => 'assets/images/index/carrusel/car1.jpg', 'alt' => 'Horus Group'],
      ['image' => 'assets/images/index/carrusel/Car2.jpg', 'alt' => 'Horus Group'],
      ['image' => 'assets/images/index/carrusel/Car4.jpg', 'alt' => 'Horus Group'],
      ['image' => 'assets/images/index/carrusel/Car5.jpg', 'alt' => 'Horus Group'],
    ];

    $stats = [
      ['icon' => 'fa-solid fa-user-check', 'value' => '+400', 'label' => 'Personas capacitadas y certificadas entre estudiantes y profesionales a lo largo del país.'],
      ['icon' => 'fa-solid fa-star', 'value' => '4.8/5', 'label' => 'Calificación promedio en nuestros cursos y capacitaciones.'],
      ['icon' => 'fa-solid fa-building', 'value' => '+200', 'label' => 'Trabajos realizados a hogares y empresas en diversas áreas.'],
      ['icon' => 'fa-solid fa-laptop', 'value' => '98%', 'label' => 'De nuestros participantes mencionan haber fortalecido sus habilidades y conocimientos.'],
    ];

    $convenios = [
      [
        'id' => 'abogados',
        'nombre' => 'Ilustre Colegio de Abogados de Cajamarca',
        'descripcion' => 'Convenio Interinstitucional con el Ilustre Colegio de Abogados de Cajamarca. La empresa Horus Group SRL firma convenio con el Ilustre Colegio de Abogados de Cajamarca - ICAC - a través de su representante el Decano Dr. Hugo Holden Díaz León para la realización de talleres, charlas, cursos y diplomados en las distintas ramas del Derecho. Dicho convenio beneficia a los participantes en los certificados emitidos de dichos eventos con firma del Decano del ICAC, así como representantes de la Empresa. Dichos certificados son válidos para las postulaciones a entidades públicas y privadas según los requisitos establecidos de las mismas.',
        'imagen' => 'assets/images/index/convenios-fotos/abogados.jpg',
        'logo' => 'assets/images/index/logos/logo-convenio-abogados.png',
        'siglas' => 'ICAC',
      ],
      [
        'id' => 'admin',
        'nombre' => 'Colegio Regional de Licenciados en Administración de Cajamarca',
        'descripcion' => 'Convenio Interinstitucional con Colegio Regional de Licenciados en Administración de Cajamarca. La empresa Horus Group SRL firma convenio con el Colegio Regional de Licenciados en Administración de Cajamarca - CORLAD - a través de su representante el Decano Dr. José Luis Medina para la realización de talleres, charlas, cursos y diplomados en las distintas ramas de las Ciencias Administrativas. Dicho convenio beneficia a los participantes en los certificados emitidos de dichos eventos con firma del Decano del Colegio Regional de Licenciados en Administración de Cajamarca, así como representantes de la Empresa. Dichos certificados son válidos para las postulaciones a entidades públicas y privadas según los requisitos establecidos de las mismas.',
        'imagen' => 'assets/images/index/convenios-fotos/admin.jpg',
        'logo' => 'assets/images/index/logos/logo-convenio-administracion.jpg',
        'siglas' => 'CORLAD',
      ],
      [
        'id' => 'economistas',
        'nombre' => 'Colegio de Economistas de Cajamarca',
        'descripcion' => 'Convenio Interinstitucional con Colegio de Economistas de Cajamarca. La empresa Horus Group SRL firma convenio con el Colegio de Economistas de Cajamarca a través de su representante el Decano Dr. José Luis Medina para la realización de talleres, charlas, cursos y diplomados en las distintas ramas de la Ciencia Económica. Dicho convenio beneficia a los participantes en los certificados emitidos de dichos eventos con firma del Decano del Colegio de Economistas, así como representantes de la Empresa. Dichos certificados son válidos para las postulaciones a entidades públicas y privadas según los requisitos establecidos de las mismas.',
        'imagen' => 'assets/images/index/convenios-fotos/economía.jpg',
        'logo' => 'assets/images/index/logos/logo-convenio-economistas.jpg',
        'siglas' => 'CEC',
      ],
      [
        'id' => 'enfermeros',
        'nombre' => 'Colegio de Enfermeros de Cajamarca - CR XIII',
        'descripcion' => 'Convenio Interinstitucional con Colegio de Enfermeros de Cajamarca. La empresa Horus Group SRL firma convenio con el Colegio de Enfermeros de Cajamarca - CR XIII a través de su representante el Decano Mg. Manuel Hoyos Torres para la realización de talleres, charlas, cursos y diplomados en las distintas ramas de la Enfermería. Dicho convenio beneficia a los participantes en los certificados emitidos de dichos eventos con firma del Decano del Colegio de Enfermeros de Cajamarca, así como representantes de la Empresa. Dichos certificados son válidos para las postulaciones a entidades públicas y privadas según los requisitos establecidos de las mismas.',
        'imagen' => 'assets/images/index/convenios-fotos/admin.jpg',
        'logo' => 'assets/images/index/logos/logo-convenio-enfermeros.png',
        'siglas' => 'CR XIII',
      ],
      [
        'id' => 'obstetras',
        'nombre' => 'Colegio Regional de Obstetras de Cajamarca - CRO XIV',
        'descripcion' => 'Convenio Interinstitucional con el Colegio Regional de Obstetras de Cajamarca. La empresa Horus Group SRL firma convenio con el Colegio Regional de Obstetras de Cajamarca - CRO XIV - a través de su representante la Decana Obsta. Ena del Carmen Balarezo Ramírez para la realización de talleres, charlas, cursos y diplomados en las distintas ramas de la Ciencia Obstétrica. Dicho convenio beneficia a los participantes en los certificados emitidos de dichos eventos con firma de la Decana del CRO XIV, así como representantes de la Empresa. Dichos certificados son válidos para las postulaciones a entidades públicas y privadas según los requisitos establecidos de las mismas.',
        'imagen' => 'assets/images/index/convenios-fotos/obstetricia.jpeg',
        'logo' => 'assets/images/index/logos/logo-convenio-obstetras.jpg',
        'siglas' => 'CRO XIV',
      ],
      [
        'id' => 'isam',
        'nombre' => 'Instituto de Educación Superior ISAM',
        // TODO: el HTML original asigna al ISAM texto de Economistas; confirmar la descripción real.
        'descripcion' => 'Convenio Interinstitucional con Colegio de Economistas de Cajamarca. La empresa Horus Group SRL firma convenio con el Colegio de Economistas de Cajamarca a través de su representante el Decano Dr. José Luis Medina para la realización de talleres, charlas, cursos y diplomados en las distintas ramas de la Ciencia Económica.',
        'imagen' => 'assets/images/index/convenios-fotos/economía.jpg',
        'logo' => 'assets/images/index/logos/logo-convenio-isam.png',
        'siglas' => 'ISAM',
      ],
    ];
  @endphp

  <section id="inicio" class="home-hero py-8 md:py-10">
    <div class="section-shell"><x-carousel :slides="$slides" /></div>
  </section>

  <section class="home-stats py-12 md:py-16">
    <div class="section-shell"><x-stats-grid :stats="$stats" /></div>
  </section>

  <section class="home-convenios py-12 md:py-16">
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
