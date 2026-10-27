@extends('layouts.app')

@section('title', 'Políticas de Privacidad')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/politicas-privacidad/politicas-privacidad.css') }}">
@endpush

@push('scripts')
  <script src="{{ asset('assets/js/politicas-privacidad/politicas-privacidad.js') }}" defer></script>
@endpush

@section('content')
<div class="legacy-showcase legal-showcase">
<section class="hero-politicas">
        <div class="hero-content">
            <h1 class="fade-in">Políticas de Privacidad</h1>
            <p class="fade-in">Protegemos tu información personal con los más altos estándares de seguridad</p>
        </div>
    </section>

    <section class="politicas-main">
        <div class="politicas-container">

            <div class="politicas-intro fade-in">
                <p>La presente política de privacidad a quien de ahora en adelante denominaremos <strong>"POLÍTICA DE
                        PRIVACIDAD"</strong> tiene por finalidad informar como la página web <strong>www……..</strong>,
                    en adelante <strong>LA PÁGINA</strong> trata la información personal de todos los usuarios que
                    visiten e interactúen en nuestro sitio web o al utilizar los diferentes servicios que brindamos.</p>
                <p>LA POLÍTICA describe todo el tipo de información personal que recaba de todos los usuarios y los
                    tratamientos sobre sus datos que se realizan. El usuario declara haber leído y aceptado de manera
                    previa y expresa LA POLÍTICA sujetándose a todas sus disposiciones.</p>
            </div>

            <div class="politicas-seccion fade-in">
                <div class="seccion-header">
                    <div class="seccion-icon">
                        <i class="fas fa-database"></i>
                    </div>
                    <h2>¿Qué información recolectamos o recopilamos?</h2>
                </div>

                <div class="seccion-content">
                    <p class="intro-text">El usuario puede navegar en y por LA PÁGINA de manera libre sin alguna
                        necesidad de registrarse y/o suscribirse dentro de LA PÁGINA; según el caso se va a requerir del
                        registro y/o suscripción del usuario para acceder a algunos servicios dentro de la misma PÁGINA
                        a través de un formulario que el usuario deberá completar proporcionando sus datos personales
                        solicitados.</p>

                    <div class="alert-box">
                        <i class="fas fa-info-circle"></i>
                        <div>
                            <p><strong>Los datos personales del usuario que se solicita son:</strong></p>
                            <ul class="lista-datos">
                                <li>Nombres</li>
                                <li>Apellidos</li>
                                <li>Correo Electrónico</li>
                                <li>Número de Celular</li>
                                <li>Otros necesarios para el registro o servicio que corresponda</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <div class="politicas-seccion fade-in">
                <div class="seccion-header">
                    <div class="seccion-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <h2>¿Qué hacemos con tu información?</h2>
                </div>

                <div class="seccion-content">
                    <p class="intro-text">Informamos que los datos personales proporcionados a través de LA PÁGINA,
                        quedan incorporados en nuestro banco de datos y podrán ser utilizados para tratamientos
                        (actividades) que supongan:</p>

                    <ul class="lista-puntos">
                        <li>Desarrollo de acciones comerciales</li>
                        <li>La remisión vía correo electrónico de publicidad</li>
                        <li>Información (personalizadas o generales) de cursos, talleres, diplomados y/o servicios DE LA
                            PÁGINA</li>
                    </ul>

                    <div class="contact-info">
                        <p>Por lo que a través de la presente POLÍTICA DE PRIVACIDAD, el usuario da su consentimiento
                            expreso para la inclusión de sus datos personales en el mencionado banco de datos.</p>
                    </div>
                </div>
            </div>

            <div class="politicas-seccion fade-in">
                <div class="seccion-header">
                    <div class="seccion-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h2>Consentimiento</h2>
                </div>

                <div class="seccion-content">
                    <p class="intro-text">Los usuarios darán su consentimiento explícito del tratamiento de sus datos
                        personales cuando seleccionen el botón y/o enlace de <strong>"Registrarse"</strong> o
                        <strong>"Suscribirse"</strong>.
                    </p>

                    <div class="alert-box">
                        <i class="fas fa-hand-paper"></i>
                        <p>Si luego de haber aceptado cambias de opinión, puedes anular tu consentimiento, por la
                            recolección, uso o divulgación de tu información, en cualquier momento, contactándonos a <a
                                href="mailto:horusgroupcajamarca@gmail.com">horusgroupcajamarca@gmail.com</a></p>
                    </div>
                </div>
            </div>

            <div class="politicas-seccion fade-in">
                <div class="seccion-header">
                    <div class="seccion-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <h2>¿Cuánto tiempo conservamos tus datos?</h2>
                </div>

                <div class="seccion-content">
                    <p class="intro-text">Los Datos Personales que son almacenados, utilizados o transmitidos
                        permanecerán en el banco de datos de LA PÁGINA durante el tiempo que sea necesario para cumplir
                        los fines previstos en la presente Política o hasta el momento en que usted decida eliminarlo.
                    </p>
                </div>
            </div>

            <div class="politicas-seccion fade-in">
                <div class="seccion-header">
                    <div class="seccion-icon">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <h2>Derechos de acceso, rectificación, cancelación y oposición de datos personales</h2>
                </div>

                <div class="seccion-content">
                    <p class="intro-text">El Usuario tiene derecho de acceso, rectificación, oposición y/o cancelación
                        de su información personal comunicándose con LA PÁGINA al correo electrónico:</p>

                    <div class="contact-box">
                        <i class="fas fa-envelope"></i>
                        <a href="mailto:horusgroupcajamarca@gmail.com">horusgroupcajamarca@gmail.com</a>
                    </div>

                    <div class="derechos-grid">
                        <div class="derecho-item">
                            <div class="derecho-icon">
                                <i class="fas fa-eye"></i>
                            </div>
                            <h4>Acceso</h4>
                            <p>Conocer qué datos personales tenemos sobre ti</p>
                        </div>
                        <div class="derecho-item">
                            <div class="derecho-icon">
                                <i class="fas fa-edit"></i>
                            </div>
                            <h4>Rectificación</h4>
                            <p>Corregir datos inexactos o incompletos</p>
                        </div>
                        <div class="derecho-item">
                            <div class="derecho-icon">
                                <i class="fas fa-trash-alt"></i>
                            </div>
                            <h4>Cancelación</h4>
                            <p>Eliminar tus datos de nuestros registros</p>
                        </div>
                        <div class="derecho-item">
                            <div class="derecho-icon">
                                <i class="fas fa-ban"></i>
                            </div>
                            <h4>Oposición</h4>
                            <p>Oponerte al tratamiento de tus datos</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="politicas-seccion fade-in">
                <div class="seccion-header">
                    <div class="seccion-icon">
                        <i class="fas fa-sync-alt"></i>
                    </div>
                    <h2>Modificaciones a nuestras Políticas de Privacidad</h2>
                </div>

                <div class="seccion-content">
                    <p class="intro-text">LA PÁGINA se reserva el derecho de modificar, rectificar, alterar, agregar o
                        eliminar cualquier punto del presente escrito en cualquier momento y sin previo aviso, siendo su
                        responsabilidad el mantenerse informado del mismo para una adecuada administración de su
                        información.</p>
                </div>
            </div>

            <div class="politicas-seccion fade-in">
                <div class="seccion-header">
                    <div class="seccion-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <h2>¿Compartiremos tus datos con terceros?</h2>
                </div>

                <div class="seccion-content">
                    <div class="alert-box seguridad">
                        <i class="fas fa-lock"></i>
                        <p><strong>Protegemos la información que recabamos</strong> estableciendo las medidas de
                            seguridad técnicas y administrativas señaladas en las normas legales, de forma que la
                            información queda protegida contra el acceso, la indisponibilidad, la divulgación o el uso
                            no autorizado.</p>
                    </div>

                    <ul class="lista-puntos">
                        <li>Para poder ofrecerte nuestros servicios de comparación los datos que compartimos son siempre
                            <strong>datos anonimizados</strong>, es decir, que para esta finalidad no compartiremos
                            datos de contacto, como los datos personales, correo electrónico o número de teléfono.
                        </li>
                        <li>Si en alguna parte de nuestro negocio entra en una empresa conjunta, compra otra empresa o
                            se vende o se fusiona con otra entidad comercial, tus datos personales podrían ser
                            divulgados o transferidos a la empresa de destino o a nuevos socios comerciales o
                            propietarios o sus agentes y asesores.</li>
                        <li>En estas circunstancias, siempre informaremos a las entidades correspondientes de que solo
                            deben usar tus datos personales para los fines descritos en esta Política de Privacidad.
                        </li>
                    </ul>
                </div>
            </div>

            <div class="politicas-seccion fade-in atencion">
                <div class="seccion-header">
                    <div class="seccion-icon">
                        <i class="fas fa-headset"></i>
                    </div>
                    <h2>Contacto</h2>
                </div>
                <div class="seccion-content">
                    <p>Para cualquier consulta sobre nuestras Políticas de Privacidad, puedes contactarnos al correo
                        electrónico <a href="mailto:horusgroupcajamarca@gmail.com">horusgroupcajamarca@gmail.com</a></p>
                    <div class="firma">
                        <img src="{{ asset('assets/images/index/logos/logo-horus.png') }}" alt="Horus Group Logo" class="firma-logo">
                        <div>
                            <p><strong>Asesoría Legal</strong></p>
                            <p>Horus Group-Cajamarca SRL</p>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </section>

    <section class="politicas-cta">
        <div class="cta-content fade-in">
            <h3>¿Tienes dudas sobre tu <span>privacidad?</span></h3>
            <p>Estamos comprometidos con la protección de tus datos personales. Contáctanos para cualquier consulta.</p>
            <div class="cta-buttons">
                <a href="mailto:horusgroupcajamarca@gmail.com" class="btn-gold">
                    <i class="fas fa-envelope"></i>
                    Contactar Asesoría Legal
                </a>
                <a href="{{ route('legales.faq') }}" class="btn-outline">
                    <i class="fas fa-question-circle"></i>
                    Ver Preguntas Frecuentes
                </a>
            </div>
        </div>
    </section>

    




    <button class="back-to-top" id="backToTop">
        <i class="fas fa-arrow-up"></i>
    </button>
    </div>
@endsection

