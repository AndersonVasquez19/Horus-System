@extends('layouts.app')

@section('title', 'Capacitaciones')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/educacion/capacitaciones.css') }}">
@endpush

@section('content')
<div class="legacy-showcase">
<section class="carta-capacitaciones">
        <div class="intro-capacitaciones">
            <div class="info-capacitaciones">
                <div class="badge-destacado">
                    <i class="fas fa-star"></i>
                    <span>FORMACIÓN PROFESIONAL</span>
                </div>
                <div class="texto-intro">
                    <h2>CAPACITACIONES</h2>
                    <p>Impulsamos el talento de su equipo para enfrentar los desafíos de un mercado en constante
                        evolución. Diseñamos programas de formación estratégica que aseguran que sus colaboradores estén
                        siempre a la vanguardia.</p>
                </div>
                <div class="stats-intro">
                    <div class="stat-box">
                        <i class="fas fa-users"></i>
                        <div>
                            <strong>+400</strong>
                            <span>Profesionales Capacitados.</span>
                        </div>
                    </div>
                    <div class="stat-box">
                        <i class="fas fa-clock"></i>
                        <div>
                            <strong>Flexible</strong>
                            <span>Horarios Adaptables.</span>
                        </div>
                    </div>
                    <div class="stat-box">
                        <i class="fas fa-award"></i>
                        <div>
                            <strong>100%</strong>
                            <span>Certificación Garantizada.</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="servicios-capacitaciones">
        <div class="contenedor">
            <div class="seccion-header">
                <h2>NUESTROS PROGRAMAS DE CAPACITACIÓN</h2>
                <p>Soluciones formativas adaptadas a tus necesidades.</p>
            </div>

            <div class="carta-grid">
                <div class="carta-card">
                    <div class="carta-imagen">
                        <img src="{{ asset('assets/images/capacitaciones/capacitaciones-empresariales.jpg') }}" alt="Conferencia con expositor y mesa directiva, representando las capacitaciones empresariales" loading="lazy" decoding="async">
                    </div>
                    <div class="carta-icon">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>
                    <h3>CAPACITACIONES EMPRESARIALES</h3>
                    <p>Programas profesionales diseñados para los colaboradores de tu empresa o institución,
                        manteniéndolos a la vanguardia de las competencias que exige el mercado actual.</p>
                    <div class="carta-features">
                        <span><i class="fas fa-check"></i> Programas personalizados.</span>
                        <span><i class="fas fa-check"></i> Modalidad presencial/online.</span>
                        <span><i class="fas fa-check"></i> Certificación incluida.</span>
                    </div>
                </div>

                <div class="carta-card">
                    <div class="carta-imagen">
                        <img src="{{ asset('assets/images/capacitaciones/formacion-personalizada.jpg') }}" alt="Participantes atendiendo una exposición con proyector, representando la formación personalizada" loading="lazy" decoding="async">
                    </div>
                    <div class="carta-icon">
                        <i class="fas fa-layer-group"></i>
                    </div>
                    <h3>FORMACIÓN PERSONALIZADA</h3>
                    <p>Capacitaciones personales y grupales adaptadas a tus necesidades educativas, profesionales y
                        laborales, con certificación que garantiza la efectividad del aprendizaje.</p>
                    <div class="carta-features">
                        <span><i class="fas fa-check"></i> Atención individualizada.</span>
                        <span><i class="fas fa-check"></i> Horarios flexibles.</span>
                        <span><i class="fas fa-check"></i> Seguimiento continuo.</span>
                    </div>
                </div>

                <div class="carta-card">
                    <div class="carta-imagen">
                        <img src="{{ asset('assets/images/capacitaciones/certificaciones-profesionales.jpg') }}" alt="Entrega de certificados a profesionales en un escenario, representando las certificaciones profesionales" loading="lazy" decoding="async">
                    </div>
                    <div class="carta-icon">
                        <i class="fas fa-certificate"></i>
                    </div>
                    <h3>CERTIFICACIONES PROFESIONALES</h3>
                    <p>Actualiza tus conocimientos teóricos y experiencias laborales mediante exámenes de certificación
                        en diferentes áreas profesionales y laborales reconocidas.</p>
                    <div class="carta-features">
                        <span><i class="fas fa-check"></i> Certificados oficiales.</span>
                        <span><i class="fas fa-check"></i> Reconocimiento internacional.</span>
                        <span><i class="fas fa-check"></i> Validez permanente.</span>
                    </div>
                </div>

                <div class="carta-card">
                    <div class="carta-imagen">
                        <img src="{{ asset('assets/images/capacitaciones/convenios-institucionales.jpg') }}" alt="Firma de convenios interinstitucionales entre representantes" loading="lazy" decoding="async">
                    </div>
                    <div class="carta-icon">
                        <i class="fas fa-handshake"></i>
                    </div>
                    <h3>CONVENIOS INSTITUCIONALES</h3>
                    <p>Establecemos alianzas estratégicas interinstitucionales para avanzar con un trabajo mancomunado,
                        generando beneficios compartidos y oportunidades de crecimiento.</p>
                    <div class="carta-features">
                        <span><i class="fas fa-check"></i> Alianzas estratégicas.</span>
                        <span><i class="fas fa-check"></i> Beneficios exclusivos.</span>
                        <span><i class="fas fa-check"></i> Red de contactos.</span>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
@endsection

