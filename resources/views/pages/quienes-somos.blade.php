@extends('layouts.app')

@section('title', 'Quiénes Somos')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/somos/quienes-somos.css') }}">
@endpush

@push('scripts')
  <script src="{{ asset('assets/js/quienes-somos.js') }}" defer></script>
@endpush

@section('content')
<div class="legacy-showcase">
<section class="qs-header">
        <div class="header-overlay"></div>
        <div class="header-content">
            <h1 class="typing">
                <span id="typing-text"></span>
            </h1>
            <h2>Descubre nuestra misión, visión y valores.</h2>
            <img src="{{ asset('assets/images/index/stiker/stiker.png') }}" alt="Decoración" class="header-sticker">
        </div>
    </section>

    <section class="mvv-section nosotros-diagonal">
        <div class="mvv-header">
            <span class="mvv-badge">Nuestra Identidad</span>
            <h2>Misión, Visión y Valores</h2>
            <p>Los pilares que guían cada decisión y acción en Horus Group SRL.</p>
        </div>
        <div class="mvv-grid">
            <div class="mvv-card">
                <div class="mvv-icon">
                    <i class="fas fa-bullseye"></i>
                </div>
                <h3>Misión</h3>
                <p>"Somos una empresa Cajamarquina que busca el desarrollo y crecimiento de la comunidad a través de los diferentes servicios de tecnología y capacitaciones que brindamos, con calidad y vocación para que nuestros clientes se sientan satisfechos y sigan confiando en nosotros."</p>
                <div class="mvv-imagen">
                    <img src="{{ asset('assets/images/somos/mision1.png') }}" alt="Misión Horus Group">
                </div>
            </div>
            <div class="mvv-card">
                <div class="mvv-icon">
                    <i class="fas fa-eye"></i>
                </div>
                <h3>Visión</h3>
                <p>Lograr ser una empresa reconocida en la Región de Cajamarca, posicionándonos como líderes en el desarrollo de competencias tecnológicas y capacitaciones de alto nivel, contando con un equipo de profesionales especializados y adaptándonos a las necesidades de la población.</p>
                <div class="mvv-imagen">
                    <img src="{{ asset('assets/images/somos/visión amarillo.png') }}" alt="Visión Horus Group">
                </div>
            </div>
            <div class="mvv-card">
                <div class="mvv-icon">
                    <i class="fas fa-star"></i>
                </div>
                <h3>Valores</h3>
                <p>En Horus Group SRL nos caracterizamos por los siguientes valores institucionales:</p>
                <ul class="mvv-valores-list">
                    <li>Puntualidad</li>
                    <li>Confianza</li>
                    <li>Calidad</li>
                    <li>Vocación</li>
                    <li>Superación</li>
                    <li>Trabajo en equipo</li>
                    <li>Empatía</li>
                </ul>
                <div class="mvv-imagen">
                    <img src="{{ asset('assets/images/somos/valores4.png') }}" alt="Valores Horus Group">
                </div>
            </div>
        </div>
    </section>

    <section class="equipo-pro">
        <div class="equipo-header">
            <span class="section-badge">NUESTRO EQUIPO</span>
            <h2>PROFESIONALES COMPROMETIDOS</h2>
            <p class="subtitulo">Conoce a las personas que hacen posible nuestro éxito.</p>
        </div>

        <div class="grid-equipo">
            <div class="card-equipo" onclick="toggleCard(this)">
                <img src="{{ asset('assets/images/somos/marco2.jpeg') }}" alt="Marco Alvarez">
                <h3>MARCO ALVAREZ</h3>
                <span class="cargo">GERENTE GENERAL</span>
                <div class="redes">
                    <a href="https://www.facebook.com/share/174BEdCReB/"><i class="fab fa-facebook-f"></i></a>
                    <i class="fab fa-whatsapp"></i>
                </div>
                <div class="descripcion">
                    <p>Profesional de Economía de la Universidad Nacional de Cajamarca. Experiencia en manejo de personal, trabajo en equipo. Docente en institutos de educación superior en los cursos de sociedad y economía, proyectos de investigación, taller de competencias profesionales entre otros.</p>
                </div>
            </div>

            <div class="card-equipo" onclick="toggleCard(this)">
                <img src="{{ asset('assets/images/somos/monica.jpeg') }}" alt="Mónica Monzón">
                <h3>MÓNICA MONZÓN</h3>
                <span class="cargo">COORDINADORA INSTITUCIONAL</span>
                <div class="redes">
                    <a href="https://web.facebook.com/monica.monzongonzales"><i class="fab fa-facebook-f"></i></a>
                    <a href=""><i class="fab fa-whatsapp"></i></a>
                </div>
                <div class="descripcion">
                    <p>Profesional de Enfermería por la Universidad Nacional de Cajamarca. Maestra en Ciencias con mención en Salud Ocupacional y Ambiental por la Universidad Privada Antenor Orrego - Trujillo. Actualmente labora en el Hospital Regional Docente de Cajamarca en la unidad de cuidados intensivos (UCI).</p>
                </div>
            </div>

        </div>
        <div class="grid-fila2">
            <div class="card-equipo" onclick="toggleCard(this)">
                <img src="{{ asset('assets/images/somos/Persona4.jpeg') }}" alt="Karen Távara">
                <h3>KAREN TÁVARA</h3>
                <span class="cargo">ASESORA LEGAL</span>
                <div class="redes">
                    <a href="https://web.facebook.com/karen.tavara1"><i class="fab fa-facebook-f"></i></a>
                    <a href=""><i class="fab fa-whatsapp"></i></a>
                </div>
                <div class="descripcion">
                    <p>Profesional en Derecho por la Universidad Privada Antonio Guillermo Urrelo. Con sólida experiencia en el ejercicio legal y en la docencia. Ha desempeñado funciones como abogada en diversas instituciones públicas.</p>
                </div>
            </div>

            <div class="card-equipo" onclick="toggleCard(this)">
                <img src="{{ asset('assets/images/somos/milagros.jpeg') }}" alt="Milagros Villegas">
                <h3>MILAGROS VILLEGAS</h3>
                <span class="cargo">SECRETARIA</span>
                <div class="redes">
                    <a href="https://web.facebook.com/milagros.villegas.180"><i class="fab fa-facebook-f"></i></a>
                    <a href=""><i class="fab fa-whatsapp"></i></a>
                </div>
                <div class="descripcion">
                    <p>Profesional de enfermería técnica, con experiencia en ventas y trato al cliente entre otros.</p>
                </div>
            </div>
        </div>
    </section>
</div>
@endsection

