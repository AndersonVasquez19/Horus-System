@extends('layouts.app')

@section('title', 'Libro de Reclamaciones')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/libro/libro-reclamaciones.css') }}">
@endpush

@push('scripts')
  <script src="{{ asset('assets/js/libro-reclamaciones.js') }}" defer></script>
@endpush

@section('content')
<div class="legacy-showcase legal-showcase">
<section class="libro-hero">
        <div class="libro-hero-bg"></div>
        <div class="libro-hero-contenido">
            <div class="libro-hero-badge">
                <i class="fas fa-shield-alt"></i>
                <span>Código de Protección al Consumidor – Ley N° 29571</span>
            </div>
            <h1>LIBRO DE<br><span class="hero-highlight">RECLAMACIONES</span></h1>
            <p>Tu opinión es importante para nosotros. Registra aquí tu queja o reclamo y nos comprometemos a responderte en un plazo máximo de <strong>15 días hábiles</strong>.</p>
        </div>
    </section>

    <section class="aviso-legal">
        <div class="aviso-contenedor">
            <div class="aviso-icono">
                <i class="fas fa-info-circle"></i>
            </div>
            <div class="aviso-texto">
                <strong>Información importante:</strong> La formulación de un reclamo no impide acudir a otras vías de solución de controversias ni es requisito previo para interponer una denuncia ante el INDECOPI. Conforme al Código de Protección y Defensa del Consumidor (Ley N° 29571).
            </div>
        </div>
    </section>

    {{--
        Opción A: el formulario se muestra siempre. Solo el envío depende de Reclamaciones::habilitado().
        Apagado: sin marca ni token, botón deshabilitado desde el servidor y sin confirmación; el POST sigue
        respondiendo 404 en el servidor (middleware RecepcionReclamacionesHabilitada), no por este botón.
    --}}
    @php($habilitado = (bool) ($habilitado ?? false))
    <section class="diferencia-section" id="seleccion-tipo">
        <div class="diferencia-contenedor">
            <h2 class="seccion-titulo-libro">¿Queja o Reclamo?</h2>
            <p class="seccion-subtitulo">Selecciona el tipo de registro que corresponde a tu situación</p>
            <div class="diferencia-cards">
                <div class="diferencia-card reclamo-card" id="btn-reclamo" role="button" tabindex="0" aria-pressed="false">
                    <div class="diferencia-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <h3>RECLAMO</h3>
                    <p>Disconformidad relacionada con los <strong>productos o servicios</strong> adquiridos. Implica una insatisfacción directa con lo recibido.</p>
                    <div class="diferencia-ejemplos">
                        <span><i class="fas fa-dot-circle"></i> Producto defectuoso</span>
                        <span><i class="fas fa-dot-circle"></i> Servicio no prestado</span>
                        <span><i class="fas fa-dot-circle"></i> Incumplimiento de garantía</span>
                    </div>
                    <div class="diferencia-seleccionado">
                        <i class="fas fa-check"></i> Seleccionado
                    </div>
                </div>
                <div class="diferencia-card queja-card" id="btn-queja" role="button" tabindex="0" aria-pressed="false">
                    <div class="diferencia-icon">
                        <i class="fas fa-comment-alt"></i>
                    </div>
                    <h3>QUEJA</h3>
                    <p>Disconformidad relacionada con la <strong>atención al cliente</strong> o el trato recibido, sin estar vinculada a un producto o servicio específico.</p>
                    <div class="diferencia-ejemplos">
                        <span><i class="fas fa-dot-circle"></i> Mala atención</span>
                        <span><i class="fas fa-dot-circle"></i> Demora en respuesta</span>
                        <span><i class="fas fa-dot-circle"></i> Trato inadecuado</span>
                    </div>
                    <div class="diferencia-seleccionado">
                        <i class="fas fa-check"></i> Seleccionado
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="formulario-section" id="formulario-section" hidden>
        <div class="formulario-contenedor">

            @unless ($habilitado)
            <p class="modo-consulta" id="modo-consulta">
                <i class="fas fa-eye" aria-hidden="true"></i>
                <span>Modo consulta: puedes revisar el formulario; el envío en línea aún no está habilitado.</span>
            </p>
            @endunless

            <div class="pasos-indicador">
                <div class="paso activo" data-paso="1">
                    <div class="paso-numero">1</div>
                    <span>Datos personales</span>
                </div>
                <div class="paso-linea"></div>
                <div class="paso" data-paso="2">
                    <div class="paso-numero">2</div>
                    <span data-reclamo="Detalle del reclamo" data-queja="Detalle de la queja">Detalle del reclamo</span>
                </div>
                <div class="paso-linea"></div>
                <div class="paso" data-paso="3">
                    <div class="paso-numero">3</div>
                    <span>Confirmación</span>
                </div>
            </div>

            @php($honeypot = (string) config('reclamaciones.antispam.honeypot', 'sitio_web'))
            <form id="libroForm" action="{{ route('legales.reclamaciones.store') }}" method="POST" data-habilitado="{{ $habilitado ? '1' : '0' }}" novalidate>
                @csrf
                @if ($habilitado)
                <input type="hidden" name="marca" value="{{ $marca }}">
                <input type="hidden" name="token_envio" value="{{ $tokenEnvio }}">
                @endif
                <input type="hidden" name="tipo" id="tipo" value="">
                {{-- Campo trampa: invisible para las personas; si llega con contenido, el servidor rechaza el envío. --}}
                <div class="campo-trampa" aria-hidden="true">
                    <label for="{{ $honeypot }}">No completes este campo</label>
                    <input type="text" id="{{ $honeypot }}" name="{{ $honeypot }}" value="" tabindex="-1" autocomplete="off" data-ignorar>
                </div>

                <div class="form-alerta" id="form-alerta" role="alert" tabindex="-1" hidden></div>

                <div class="form-paso activo" id="paso-1">
                    <div class="form-paso-header">
                        <div class="form-paso-icon">
                            <i class="fas fa-user"></i>
                        </div>
                        <div>
                            <h2>Datos del Consumidor</h2>
                            <p>Ingresa tu información personal para poder contactarte</p>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-grupo">
                            <label for="nombres">Nombres <span class="requerido">*</span></label>
                            <div class="input-wrapper">
                                <i class="fas fa-user input-icon"></i>
                                <input type="text" id="nombres" name="nombres" placeholder="Ingresa tus nombres" required maxlength="120" autocomplete="given-name">
                            </div>
                            <span class="error-msg" id="error-nombres"></span>
                        </div>

                        <div class="form-grupo">
                            <label for="apellidos">Apellidos <span class="requerido">*</span></label>
                            <div class="input-wrapper">
                                <i class="fas fa-user input-icon"></i>
                                <input type="text" id="apellidos" name="apellidos" placeholder="Ingresa tus apellidos" required maxlength="120" autocomplete="family-name">
                            </div>
                            <span class="error-msg" id="error-apellidos"></span>
                        </div>

                        <div class="form-grupo">
                            <label for="tipo_documento">Tipo de Documento <span class="requerido">*</span></label>
                            <div class="input-wrapper">
                                <i class="fas fa-id-card input-icon"></i>
                                <select id="tipo_documento" name="tipo_documento" required>
                                    <option value="">Selecciona tipo</option>
                                    <option value="dni">DNI</option>
                                    <option value="ce">Carnet de Extranjería</option>
                                    <option value="pasaporte">Pasaporte</option>
                                    <option value="ruc">RUC</option>
                                </select>
                            </div>
                            <span class="error-msg" id="error-tipo_documento"></span>
                        </div>

                        <div class="form-grupo">
                            <label for="numero_documento">Número de Documento <span class="requerido">*</span></label>
                            <div class="input-wrapper">
                                <i class="fas fa-hashtag input-icon"></i>
                                <input type="text" id="numero_documento" name="numero_documento" placeholder="Ej: 12345678" required maxlength="20" autocomplete="off">
                            </div>
                            <span class="error-msg" id="error-numero_documento"></span>
                        </div>

                        <div class="form-grupo">
                            <label for="email">Correo Electrónico <span class="requerido">*</span></label>
                            <div class="input-wrapper">
                                <i class="fas fa-envelope input-icon"></i>
                                <input type="email" id="email" name="email" placeholder="correo@ejemplo.com" required maxlength="254" autocomplete="email">
                            </div>
                            <span class="error-msg" id="error-email"></span>
                        </div>

                        <div class="form-grupo">
                            <label for="telefono">Teléfono <span class="requerido">*</span></label>
                            <div class="input-wrapper">
                                <i class="fas fa-phone input-icon"></i>
                                <input type="tel" id="telefono" name="telefono" placeholder="Ej: 987654321" required maxlength="30" autocomplete="tel">
                            </div>
                            <span class="error-msg" id="error-telefono"></span>
                        </div>

                        <div class="form-grupo form-grupo-full">
                            <label for="domicilio">Domicilio <span class="requerido">*</span></label>
                            <div class="input-wrapper">
                                <i class="fas fa-map-marker-alt input-icon"></i>
                                <input type="text" id="domicilio" name="domicilio" placeholder="Calle, número, distrito, ciudad" required maxlength="255" autocomplete="street-address">
                            </div>
                            <span class="error-msg" id="error-domicilio"></span>
                        </div>

                        <div class="form-grupo form-grupo-full">
                            <label class="check-inline" for="es_menor">
                                <input type="checkbox" id="es_menor" name="es_menor" value="1">
                                <span>El consumidor es menor de edad</span>
                            </label>
                        </div>

                        <div class="form-grupo" data-condicion="menor" hidden>
                            <label for="representante_nombre">Padre, madre o representante <span class="requerido">*</span></label>
                            <div class="input-wrapper">
                                <i class="fas fa-user-shield input-icon"></i>
                                <input type="text" id="representante_nombre" name="representante_nombre" placeholder="Nombre completo" required maxlength="120" disabled>
                            </div>
                            <span class="error-msg" id="error-representante_nombre"></span>
                        </div>

                        <div class="form-grupo" data-condicion="menor" hidden>
                            <label for="representante_telefono">Teléfono del representante <span class="requerido">*</span></label>
                            <div class="input-wrapper">
                                <i class="fas fa-phone input-icon"></i>
                                <input type="tel" id="representante_telefono" name="representante_telefono" placeholder="Ej: 987654321" required maxlength="30" disabled>
                            </div>
                            <span class="error-msg" id="error-representante_telefono"></span>
                        </div>

                        <div class="form-grupo" data-condicion="menor" hidden>
                            <label for="representante_email">Correo del representante <span class="requerido">*</span></label>
                            <div class="input-wrapper">
                                <i class="fas fa-envelope input-icon"></i>
                                <input type="email" id="representante_email" name="representante_email" placeholder="correo@ejemplo.com" required maxlength="254" disabled>
                            </div>
                            <span class="error-msg" id="error-representante_email"></span>
                        </div>

                        <div class="form-grupo" data-condicion="menor" hidden>
                            <label for="representante_domicilio">Domicilio del representante <span class="requerido">*</span></label>
                            <div class="input-wrapper">
                                <i class="fas fa-map-marker-alt input-icon"></i>
                                <input type="text" id="representante_domicilio" name="representante_domicilio" placeholder="Calle, número, distrito, ciudad" required maxlength="255" disabled>
                            </div>
                            <span class="error-msg" id="error-representante_domicilio"></span>
                        </div>
                    </div>

                    <div class="form-navegacion">
                        <div></div>
                        <button type="button" class="btn-siguiente" id="btn-paso1-siguiente">
                            Siguiente <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>

                <div class="form-paso" id="paso-2">
                    <div class="form-paso-header">
                        <div class="form-paso-icon">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                        <div>
                            <h2 data-reclamo="Detalle del Reclamo" data-queja="Detalle de la Queja">Detalle del Reclamo / Queja</h2>
                            <p>Describe con precisión lo ocurrido para que podamos atenderte mejor</p>
                        </div>
                    </div>

                    <div class="form-grid">

                        {{-- Solo para reclamos: datos del producto o servicio contratado. --}}
                        <fieldset class="form-grupo" data-condicion="reclamo" hidden>
                            <legend class="form-legend">Producto o servicio <span class="requerido">*</span></legend>
                            <div class="opciones-radio">
                                <label class="opcion-radio">
                                    <input type="radio" name="bien_tipo" value="producto" required disabled>
                                    <span><i class="fas fa-box" aria-hidden="true"></i> Producto</span>
                                </label>
                                <label class="opcion-radio">
                                    <input type="radio" name="bien_tipo" value="servicio" required disabled>
                                    <span><i class="fas fa-concierge-bell" aria-hidden="true"></i> Servicio</span>
                                </label>
                            </div>
                            <span class="error-msg" id="error-bien_tipo"></span>
                        </fieldset>

                        <div class="form-grupo" data-condicion="reclamo" hidden>
                            <label for="monto_bien">Monto del producto o servicio (S/) <span class="requerido">*</span></label>
                            <div class="input-wrapper">
                                <i class="fas fa-coins input-icon"></i>
                                <input type="text" id="monto_bien" name="monto_bien" inputmode="decimal" placeholder="Ej: 150.00" required maxlength="13" autocomplete="off" disabled>
                            </div>
                            <label class="check-inline check-inline-sm" for="monto_no_aplica">
                                <input type="checkbox" id="monto_no_aplica" name="monto_no_aplica" value="1" disabled>
                                <span>No aplica o no lo conozco</span>
                            </label>
                            <span class="error-msg" id="error-monto_bien"></span>
                        </div>

                        <div class="form-grupo form-grupo-full" data-condicion="reclamo" hidden>
                            <label for="bien_descripcion">Descripción del producto o servicio <span class="requerido">*</span></label>
                            <div class="input-wrapper">
                                <i class="fas fa-box input-icon input-icon-top"></i>
                                <textarea id="bien_descripcion" name="bien_descripcion" rows="3" placeholder="Describe brevemente el producto o servicio que adquiriste..." required maxlength="500" disabled></textarea>
                            </div>
                            <span class="error-msg" id="error-bien_descripcion"></span>
                        </div>

                        <div class="form-grupo form-grupo-full">
                            <label for="detalle"><span data-reclamo="Detalle del Reclamo" data-queja="Detalle de la Queja">Detalle del Reclamo / Queja</span> <span class="requerido">*</span></label>
                            <div class="input-wrapper">
                                <i class="fas fa-pen input-icon input-icon-top"></i>
                                <textarea id="detalle" name="detalle" rows="5" placeholder="Describe lo ocurrido: qué pasó, cuándo y cualquier información relevante..." required maxlength="3000"></textarea>
                            </div>
                            <div class="contador-caracteres">
                                <span id="contador">0</span> / 3000 caracteres
                            </div>
                            <span class="error-msg" id="error-detalle"></span>
                        </div>

                        <div class="form-grupo form-grupo-full">
                            <label for="pedido">¿Qué solicitas? <span class="requerido">*</span></label>
                            <div class="input-wrapper">
                                <i class="fas fa-hand-holding input-icon input-icon-top"></i>
                                <textarea id="pedido" name="pedido" rows="3" placeholder="Indica qué esperas que hagamos para solucionarlo..." required maxlength="1500"></textarea>
                            </div>
                            <span class="error-msg" id="error-pedido"></span>
                        </div>


                    </div>

                    <div class="form-navegacion">
                        <button type="button" class="btn-anterior" id="btn-paso2-anterior">
                            <i class="fas fa-arrow-left"></i> Anterior
                        </button>
                        <button type="button" class="btn-siguiente" id="btn-paso2-siguiente">
                            Siguiente <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>

                <div class="form-paso" id="paso-3">
                    <div class="form-paso-header">
                        <div class="form-paso-icon">
                            <i class="fas fa-check-double"></i>
                        </div>
                        <div>
                            <h2>Confirmación y Envío</h2>
                            <p data-reclamo="Revisa tu información antes de enviar el reclamo" data-queja="Revisa tu información antes de enviar la queja">Revisa tu información antes de enviar el reclamo</p>
                        </div>
                    </div>

                    <div class="resumen-container">
                        <div class="resumen-bloque">
                            <h3><i class="fas fa-user"></i> Datos Personales</h3>
                            <div class="resumen-grid" id="resumen-personal"></div>
                        </div>
                        <div class="resumen-bloque">
                            <h3><i class="fas fa-clipboard-list"></i> <span data-reclamo="Detalle del Reclamo" data-queja="Detalle de la Queja">Detalle del Reclamo</span></h3>
                            <div class="resumen-grid" id="resumen-reclamo"></div>
                        </div>
                    </div>

                    <div class="terminos-container">
                        <label class="terminos-label">
                            {{-- Sin name: es una declaración en el navegador; el servidor registra la versión de privacidad informada. --}}
                            <input type="checkbox" id="acepta-terminos" required data-ignorar>
                            <span class="terminos-custom"></span>
                            <span>Declaro que la información proporcionada es verídica y acepto que Horus Group SRL procese mis datos personales conforme a su <a href="{{ route('legales.privacidad') }}" target="_blank">Política de Privacidad</a> para gestionar <span data-reclamo="este reclamo" data-queja="esta queja">este reclamo</span>.</span>
                        </label>
                        <span class="error-msg" id="error-terminos"></span>
                    </div>

                    @unless ($habilitado)
                    <div class="envio-deshabilitado" id="aviso-envio-deshabilitado" role="note">
                        <div class="envio-deshabilitado-icono">
                            <i class="fas fa-info-circle" aria-hidden="true"></i>
                        </div>
                        <p>El formulario está disponible para su consulta, pero el registro en línea todavía no se encuentra habilitado. Próximamente podrás enviar tu queja o reclamo a través de esta plataforma.</p>
                    </div>
                    @endunless

                    <div class="form-navegacion">
                        <button type="button" class="btn-anterior" id="btn-paso3-anterior">
                            <i class="fas fa-arrow-left"></i> Anterior
                        </button>
                        @if ($habilitado)
                        <button type="submit" class="btn-enviar" id="btn-enviar">
                            <i class="fas fa-paper-plane"></i> <span data-reclamo="Enviar Reclamo" data-queja="Enviar Queja">Enviar Reclamo</span>
                        </button>
                        @else
                        {{-- Deshabilitado en el HTML: sin JavaScript tampoco hay envío (ni con Enter). --}}
                        <button type="submit" class="btn-enviar" id="btn-enviar" disabled aria-disabled="true" aria-describedby="aviso-envio-deshabilitado">
                            <i class="fas fa-lock" aria-hidden="true"></i> <span>Envío temporalmente deshabilitado</span>
                        </button>
                        @endif
                    </div>
                </div>

            </form>

            @if ($habilitado)
            {{-- Se rellena solo con la respuesta real del servidor (número, tipo y fecha límite). --}}
            <div class="exito-container" id="exito-container" tabindex="-1" hidden>
                <div class="exito-icono">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h2 data-reclamo="¡Reclamo Registrado Exitosamente!" data-queja="¡Queja Registrada Exitosamente!">¡Reclamo Registrado Exitosamente!</h2>
                <p id="exito-mensaje" data-reclamo="Gracias por comunicarte con Horus Group SRL. Tu reclamo fue registrado correctamente." data-queja="Gracias por comunicarte con Horus Group SRL. Tu queja fue registrada correctamente.">Gracias por comunicarte con Horus Group SRL. Tu reclamo fue registrado correctamente.</p>
                <div class="exito-numero">
                    <span data-reclamo="Número de Reclamo:" data-queja="Número de Queja:">Número de Reclamo:</span>
                    <strong id="numero-reclamo"></strong>
                </div>
                <div class="exito-info" id="exito-info">
                    <div class="exito-item">
                        <i class="fas fa-tag"></i>
                        <span>Tipo de registro: <strong id="exito-tipo"></strong></span>
                    </div>
                    <div class="exito-item">
                        <i class="fas fa-calendar-check"></i>
                        <span>Fecha límite de respuesta: <strong id="exito-fecha-limite"></strong></span>
                    </div>
                    <div class="exito-item">
                        <i class="fas fa-bookmark"></i>
                        <span>Conserva este número para cualquier consulta sobre tu registro.</span>
                    </div>
                    @if ($correoActivo ?? false)
                    <div class="exito-item" id="exito-correo">
                        <i class="fas fa-envelope"></i>
                        <span>Enviaremos una copia de tu registro al correo electrónico que indicaste.</span>
                    </div>
                    @endif
                </div>
                <a href="{{ route('home') }}" class="btn-volver">
                    <i class="fas fa-home"></i> Volver al Inicio
                </a>
            </div>
            @endif

        </div>
    </section>
    </div>
@endsection

