@extends('layouts.app')

@section('title', 'Preguntas Frecuentes')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/preguntas-frecuentes/preguntas-frecuentes.css') }}">
@endpush

@push('scripts')
  <script src="{{ asset('assets/js/preguntas-frecuentes/preguntas-frecuentes.js') }}" defer></script>
@endpush

@section('content')
<div class="legacy-showcase legal-showcase">
<section class="faq-main">
        <div class="faq-container">

            <div class="no-results" id="noResults">
                <i class="fas fa-search"></i>
                No encontramos resultados para tu búsqueda.<br>
                Prueba con otros términos o <a href="{{ route('contactos') }}">contáctanos
                    directamente</a>.
            </div>

            <div class="faq-category-title fade-in" data-category="certificados">
                <i><i class="fas fa-certificate"></i></i>
                <h2>Certificados y Diplomas</h2>
            </div>

            <div class="faq-item fade-in" data-category="certificados"
                data-question="qué pasa si perdí mi certificado y/o diploma y quiero solicitar uno nuevo">
                <div class="faq-question">
                    <div class="faq-question-number">01</div>
                    <div class="faq-question-text">¿Qué pasa si perdí mi certificado y/o diploma y quiero solicitar uno
                        nuevo?</div>
                    <div class="faq-toggle-icon"><i class="fas fa-plus"></i></div>
                </div>
                <div class="faq-answer">
                    <div class="faq-answer-inner">
                        Puedes solicitar una reimpresión de tu certificado o diploma contactándonos directamente por
                        <strong>correo electrónico</strong> o <strong>WhatsApp</strong>. Es posible que apliquen costos
                        administrativos según el tipo de documento.
                    </div>
                </div>
            </div>

            <div class="faq-category-title fade-in" data-category="cursos">
                <i><i class="fas fa-book-open"></i></i>
                <h2>Cursos y Evaluaciones</h2>
            </div>

            <div class="faq-item fade-in" data-category="cursos"
                data-question="cuáles son las fechas para rendir las evaluaciones de cursos asincrónicos">
                <div class="faq-question">
                    <div class="faq-question-number">02</div>
                    <div class="faq-question-text">¿Cuáles son las fechas para rendir las evaluaciones de cursos
                        asincrónicos?</div>
                    <div class="faq-toggle-icon"><i class="fas fa-plus"></i></div>
                </div>
                <div class="faq-answer">
                    <div class="faq-answer-inner">
                        Las evaluaciones de cursos grabados se rinden en <strong>fechas fijas</strong> que comunicamos
                        mensualmente por correo y redes sociales. Contáctanos para conocer el próximo calendario.
                    </div>
                </div>
            </div>

            <div class="faq-item fade-in" data-category="cursos"
                data-question="si el curso es presencial o semipresencial y no fui a la parte práctica qué hago">
                <div class="faq-question">
                    <div class="faq-question-number">03</div>
                    <div class="faq-question-text">Si el curso es presencial o semipresencial y no fui a la parte
                        práctica, ¿qué hago?</div>
                    <div class="faq-toggle-icon"><i class="fas fa-plus"></i></div>
                </div>
                <div class="faq-answer">
                    <div class="faq-answer-inner">
                        Debes comunicarte con nosotros <strong>antes de la siguiente sesión práctica</strong> para
                        coordinar un proceso de recuperación o reprogramación, sujeto a disponibilidad.
                    </div>
                </div>
            </div>

            <div class="faq-category-title fade-in" data-category="compras">
                <i><i class="fas fa-shopping-bag"></i></i>
                <h2>Compras y Pagos</h2>
            </div>

            <div class="faq-item fade-in" data-category="compras"
                data-question="compré un producto en la web y todavía no me llega qué debo hacer">
                <div class="faq-question">
                    <div class="faq-question-number">04</div>
                    <div class="faq-question-text">Compré un producto en la web y todavía no me llega, ¿qué debo hacer?
                    </div>
                    <div class="faq-toggle-icon"><i class="fas fa-plus"></i></div>
                </div>
                <div class="faq-answer">
                    <div class="faq-answer-inner">
                        Escríbenos con tu <strong>número de pedido</strong> al correo o WhatsApp. Verificaremos el
                        estado de tu envío y te daremos una solución en menos de <strong>24 horas hábiles</strong>.
                    </div>
                </div>
            </div>

            <div class="faq-item fade-in" data-category="compras"
                data-question="emiten comprobantes de pago factura boleta">
                <div class="faq-question">
                    <div class="faq-question-number">05</div>
                    <div class="faq-question-text">¿Emiten comprobantes de pago?</div>
                    <div class="faq-toggle-icon"><i class="fas fa-plus"></i></div>
                </div>
                <div class="faq-answer">
                    <div class="faq-answer-inner">
                        Sí, emitimos <strong>boletas y facturas electrónicas</strong>. Al momento de tu compra puedes
                        indicar si necesitas factura con los datos de tu empresa.
                    </div>
                </div>
            </div>

            <div class="faq-item fade-in" data-category="compras"
                data-question="qué medios de pago aceptan yape plin tarjeta transferencia">
                <div class="faq-question">
                    <div class="faq-question-number">06</div>
                    <div class="faq-question-text">¿Qué medios de pago aceptan?</div>
                    <div class="faq-toggle-icon"><i class="fas fa-plus"></i></div>
                </div>
                <div class="faq-answer">
                    <div class="faq-answer-inner">
                        Aceptamos <strong>transferencias bancarias, Yape, Plin, tarjetas de crédito/débito</strong> y
                        pago en efectivo en nuestra sede de Cajamarca.
                    </div>
                </div>
            </div>

            <div class="faq-category-title fade-in" data-category="atencion">
                <i><i class="fas fa-headset"></i></i>
                <h2>Atención Personalizada</h2>
            </div>

            <div class="faq-item fade-in" data-category="atencion"
                data-question="deseas una atención más personalizada asesor reunión virtual">
                <div class="faq-question">
                    <div class="faq-question-number">07</div>
                    <div class="faq-question-text">¿Deseas una atención más personalizada?</div>
                    <div class="faq-toggle-icon"><i class="fas fa-plus"></i></div>
                </div>
                <div class="faq-answer">
                    <div class="faq-answer-inner">
                        Contáctanos directamente por <strong>WhatsApp</strong> o agenda una reunión virtual con uno de
                        nuestros asesores. Estamos disponibles de <strong>lunes a viernes de 8am a 6pm</strong>.
                    </div>
                </div>
            </div>

        </div>
    </section>

    <section class="faq-cta">
        <div class="faq-cta-content fade-in">
            <h3>¿No encontraste lo que <span>buscabas?</span></h3>
            <p>Nuestro equipo está disponible para resolver cualquier consulta de forma personalizada. No dudes en
                contactarnos.</p>
            <div class="faq-cta-buttons">
                <a href="https://wa.me/51909999999" class="btn-gold" target="_blank">
                    <i class="fab fa-whatsapp"></i>
                    Escribir por WhatsApp
                </a>
                <a href="{{ route('contactos') }}" class="btn-outline">
                    <i class="fas fa-envelope"></i>
                    Enviar un mensaje
                </a>
            </div>
        </div>
    </section>

    



    <button class="back-to-top" id="backToTop">
        <i class="fas fa-arrow-up"></i>
    </button>
    </div>
@endsection

