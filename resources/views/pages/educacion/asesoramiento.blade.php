@extends('layouts.app')

@section('title', 'Asesoramiento')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/educacion/asesoramiento.css') }}">
@endpush

@section('content')
<div class="legacy-showcase">
<section class="carta-asesoramiento">
        <div class="intro-asesoramiento">
            <div class="texto-intro">
                <div class="badge-asesoramiento">
                    <i class="fas fa-award"></i>
                    <span>ASESORÍA PROFESIONAL</span>
                </div>
                <h2>ASESORAMIENTO EDUCATIVO, EMPRESARIAL Y LEGAL</h2>
                <p>Contamos con profesionales altamente calificados y con amplia experiencia en asesoramiento,
                    comprometidos en brindarle orientación estratégica para el desarrollo de sus proyectos personales y
                    profesionales, con el objetivo de alcanzar resultados exitosos y sostenibles.</p>
                <div class="stats-asesoramiento">
                    <div class="stat-box">
                        <i class="fas fa-user-tie"></i>
                        <div>
                            <strong>Expertos</strong>
                            <span>Profesionales con amplia experiencia.</span>
                        </div>
                    </div>
                    <div class="stat-box">
                        <i class="fas fa-handshake"></i>
                        <div>
                            <strong>Personalizado</strong>
                            <span>Atención individualizada.</span>
                        </div>
                    </div>
                    <div class="stat-box">
                        <i class="fas fa-chart-line"></i>
                        <div>
                            <strong>Resultados</strong>
                            <span>Garantizamos buenos resultados.</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="carta-centro">
        <div class="intro-centro">
            <div class="texto-introcenter">
                <div class="texto-destacado">
                    <div class="icono-principal">
                        <i class="fas fa-users-cog"></i>
                    </div>
                    <p>Contamos con personal altamente capacitado y con amplia experiencia en diversos rubros, preparado
                        para brindarle el respaldo y la asesoría necesaria en el desarrollo y ejecución de su proyecto,
                        garantizando un trabajo eficiente y de calidad.</p>
                    <div class="stats-rapidos">
                        <div class="stat-item">
                            <i class="fas fa-user-graduate"></i>
                            <span>Profesionales con amplia experiencia.</span>
                        </div>
                    </div>
                </div>
                <div class="imagen-destacada">
                    <img src="{{ asset('assets/images/asesoramiento/asesoramiento2.jpg') }}" alt="asesoramiento2">
                    <div class="overlay-imagen">
                        <i class="fas fa-handshake"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="cartas-contenedor">
            <article class="carta">
                <div class="carta-icono">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <h3>ASESORAMIENTO EDUCATIVO</h3>
                <p>Te ayudamos a estructurar, redactar y finalizar tu tesis o trabajo de grado con rigor académico y
                    claridad.</p>
                <div class="carta-detalles">
                    <span><i class="fas fa-check-circle"></i> Metodología rigurosa.</span>
                    <span><i class="fas fa-check-circle"></i> Acompañamiento continuo.</span>
                </div>
            </article>

            <article class="carta">
                <div class="carta-icono">
                    <i class="fas fa-briefcase"></i>
                </div>
                <h3>ASESORAMIENTO EMPRESARIAL</h3>
                <p>Te brindamos las herramientas necesarias tanto en la parte económica (finanzas y proyectos) como en
                    la parte humana (liderazgo y equipo) para que tu organización sea sólida y exitosa.</p>
                <div class="carta-detalles">
                    <span><i class="fas fa-check-circle"></i> Estrategias efectivas.</span>
                    <span><i class="fas fa-check-circle"></i> Resultados medibles.</span>
                </div>
            </article>

            <article class="carta">
                <div class="carta-icono">
                    <i class="fas fa-balance-scale"></i>
                </div>
                <h3>ASESORAMIENTO LEGAL</h3>
                <p>Contamos con un equipo de expertos comprometidos en brindarte la tranquilidad y seguridad jurídica
                    que
                    necesitas en los momentos más importantes.</p>
                <div class="carta-detalles">
                    <span><i class="fas fa-check-circle"></i> Asesoría especializada.</span>
                    <span><i class="fas fa-check-circle"></i> Respaldo profesional.</span>
                </div>
            </article>
        </div>
    </section>

    <section class="carta-apoyo">
        <div class="intro-apoyo">
            <div class="texto-apoyo">
                <h2>Compromiso y Respaldo Profesional</h2>
                <p>En Horus Group S.R.L., convertimos nuestra experiencia en tu mayor tranquilidad. Te brindamos un
                    acompañamiento técnico y profesional de alto nivel en cada uno de nuestros servicios.</p>

                <div class="beneficios-grid">
                    <div class="beneficio-card">
                        <div class="beneficio-icono">
                            <i class="fas fa-clock"></i>
                        </div>
                        <h3>RESPUESTA ÁGIL</h3>
                        <p>Ante cualquier consulta, nuestro equipo de expertos te brindará orientación clara en un plazo
                            máximo de 48 horas hábiles.</p>
                    </div>

                    <div class="beneficio-card">
                        <div class="beneficio-icono">
                            <i class="fas fa-sync-alt"></i>
                        </div>
                        <h3>FLEXIBILIDAD TOTAL</h3>
                        <p>Nos adaptamos a tu ritmo y ubicación, ofreciendo respaldo continuo tanto en modalidad
                            presencial como online.</p>
                    </div>

                    <div class="beneficio-card">
                        <div class="beneficio-icono">
                            <i class="fas fa-hands-helping"></i>
                        </div>
                        <h3>ACOMPAÑAMIENTO INTEGRAL</h3>
                        <p>Estamos contigo en todo el proceso; desde el nacimiento de tu idea y su implementación, hasta
                            la medición final de los resultados.</p>
                    </div>

                    <div class="beneficio-card">
                        <div class="beneficio-icono">
                            <i class="fas fa-puzzle-piece"></i>
                        </div>
                        <h3>SOLUCIONES A MEDIDA</h3>
                        <p>Personalizamos cada asesoría para asegurar que se ajuste perfectamente a tus necesidades
                            específicas.</p>
                    </div>
                </div>

                <div class="cta-final">
                    <i class="fas fa-quote-left"></i>
                    <p>Elegirnos significa elegir tranquilidad, profesionalismo y un respaldo constante que transforma
                        tus dudas en soluciones concretas.</p>
                    <i class="fas fa-quote-right"></i>
                </div>
            </div>
        </div>
    </section>
</div>
@endsection

