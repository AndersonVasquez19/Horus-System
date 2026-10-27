@extends('layouts.app')

@section('title', 'Cursos')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/educacion/cursos.css') }}">
@endpush

@section('content')
<div class="legacy-showcase">
<section class="carta-cursos">
        <div class="intro-cursos">
            <div class="info-cursos">
                <div class="badge-cursos">
                    <i class="fas fa-certificate"></i>
                    <span>CERTIFICACIÓN OFICIAL</span>
                </div>
                <div class="texto-intro">
                    <h2>CURSOS CON CERTIFICADOS FÍSICOS Y DIGITALES</h2>
                    <p>Te ofrecemos diferentes tipos de cursos según las necesidades del mercado para que puedas seguir
                        mejorando tus conocimientos teórico-práctico donde puedas seguir desarrollándote personal y
                        profesionalmente; para lo cual te damos las facilidades de poder realizar estos cursos a
                        través de las siguientes modalidades según sea el caso.</p>
                </div>
                <div class="stats-cursos">
                    <div class="stat-item">
                        <i class="fas fa-book-open"></i>
                        <div>
                            <strong>+10</strong>
                            <span>Cursos Disponibles.</span>
                        </div>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-clock"></i>
                        <div>
                            <strong>FLEXIBLE</strong>
                            <span>Horarios Adaptables.</span>
                        </div>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-certificate"></i>
                        <div>
                            <strong>DOBLE</strong>
                            <span>Certificación.</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="cartas-section">
        <div class="container">
            <div class="seccion-header">
                <h2>MODALIDADES DE CURSOS</h2>
                <p>Elige la modalidad que mejor se adapte a tu estilo de vida.</p>
            </div>
            <div class="carta-grid">
                <div class="carta-card">
                    <div class="carta-badge">Tradicional</div>
                    <div class="carta-icon">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>
                    <h3>PRESENCIAL</h3>
                    <p>Nuestras sesiones teóricas y prácticas se desarrollan íntegramente de forma presencial. El
                        objetivo es potenciar tus habilidades y destrezas mediante un aprendizaje directo y
                        personalizado.</p>
                    <div class="carta-features">
                        <span><i class="fas fa-check"></i> Interacción directa.</span>
                        <span><i class="fas fa-check"></i> Práctica inmediata.</span>
                        <span><i class="fas fa-check"></i> Networking presencial.</span>
                    </div>
                </div>

                <div class="carta-card destacada">
                    <div class="carta-badge popular">Más Popular</div>
                    <div class="carta-icon">
                        <i class="fas fa-laptop-house"></i>
                    </div>
                    <h3>SEMIPRESENCIAL</h3>
                    <p>Combina la flexibilidad digital con la experiencia práctica. Accede a tus clases teóricas
                        mediante sesiones online en vivo con interacción directa; además, tendrás acceso a las
                        grabaciones para repasar a tu ritmo. Las sesiones prácticas son 100% presenciales y nos
                        adaptamos a
                        tu agenda con horarios semanales o mensuales.</p>
                    <div class="carta-features">
                        <span><i class="fas fa-check"></i> Lo mejor de ambos mundos.</span>
                        <span><i class="fas fa-check"></i> Clases grabadas.</span>
                        <span><i class="fas fa-check"></i> Horarios flexibles.</span>
                    </div>
                </div>

                <div class="carta-card">
                    <div class="carta-badge">100% Online</div>
                    <div class="carta-icon">
                        <i class="fas fa-laptop"></i>
                    </div>
                    <h3>VIRTUAL</h3>
                    <p>Lleva tus clases desde la comodidad de tu hogar, donde puedas seguir aprendiendo y mejorando tus
                        conocimientos teóricos y poder repasar después los puntos que necesites debido a que
                        nuestras clases son grabadas.</p>
                    <div class="carta-features">
                        <span><i class="fas fa-check"></i> Desde cualquier lugar.</span>
                        <span><i class="fas fa-check"></i> Clases grabadas.</span>
                        <span><i class="fas fa-check"></i> Aprende a tu ritmo.</span>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
@endsection

