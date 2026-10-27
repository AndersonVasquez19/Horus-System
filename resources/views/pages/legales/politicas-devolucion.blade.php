@extends('layouts.app')

@section('title', 'Políticas de Devolución')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/politicas-devolucion/politicas-devolucion.css') }}">
@endpush

@push('scripts')
  <script src="{{ asset('assets/js/politicas-devolucion/politicas-devolucion.js') }}" defer></script>
@endpush

@section('content')
<div class="legacy-showcase legal-showcase">
<section class="hero-politicas">
        <div class="hero-content">
            <h1 class="fade-in">Políticas de Devolución</h1>
            <p class="fade-in">Conoce nuestros términos y condiciones para devoluciones y garantías</p>
        </div>
    </section>

    <section class="politicas-main">
        <div class="politicas-container">

            <div class="politicas-intro fade-in">
                <p>Al acceder y utilizar este sitio web, usted está aceptando todos los términos y condiciones. Si no
                    está de acuerdo con alguna parte, puede desistir de la utilización de este sitio web o de alguno de
                    los servicios que brindamos por la empresa <strong>Horus Group SRL</strong>.</p>
            </div>

            <div class="politicas-seccion fade-in">
                <div class="seccion-header">
                    <div class="seccion-icon">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <h2>A. EDUCACIÓN</h2>
                    <p class="seccion-subtitle">Talleres, capacitaciones, cursos, diplomados</p>
                </div>

                <div class="seccion-content">
                    <p class="intro-text">La empresa de Telecomunicaciones y Capacitaciones Profesionales Horus
                        Group-Cajamarca S.R.L. se compromete en ofrecer una satisfacción garantizada en todos talleres,
                        cursos de actualización, capacitaciones y/o diplomados que se realice, por ello se realizará la
                        devolución del monto pagado solo en el caso de que amerite.</p>

                    <p class="contact-info">Todas las solicitudes serán recibidas y atendidas por nuestro equipo de
                        Asistencia Legal a través de nuestro correo electrónico <a
                            href="mailto:horusgroupcajamarca@gmail.com">horusgroupcajamarca@gmail.com</a></p>

                    <div class="subseccion">
                        <h3><span class="numero">1.</span> Circunstancia para solicitar la devolución de dinero</h3>
                        <p>Casos fortuitos o de fuerza mayor que impidan el asistir a la clase teórica y/o práctica,
                            cursos de actualización, talleres, capacitaciones y otros con características distintas a
                            las ofrecidas, incumplimiento en los días de capacitación.</p>
                    </div>

                    <div class="subseccion">
                        <h3><span class="numero">2.</span> Consideraciones que se debe tener en cuenta</h3>
                        <ul class="lista-puntos">
                            <li>Puedes solicitar la devolución del dinero cancelado en un plazo máximo de <strong>1 día
                                    calendario</strong> desde la fecha en la cual te inscribiste y como máximo hasta la
                                fecha en la cual se dará la clase teórica y/o práctica del taller, curso de
                                actualización, capacitación, diplomado u otros. Pasado este día, ya no tienes opción a
                                solicitar la devolución.</li>
                            <li>Al momento de solicitar la devolución, el correo electrónico debe contener los
                                <strong>medios probatorios</strong> que acrediten la situación expuesta.
                            </li>
                            <li>No se aceptará la devolución de ser que no se adjunten los medios probatorios en el
                                correo electrónico.</li>
                            <li>Si ya asististe a la clase teórica, <strong>no puedes solicitar la devolución</strong>
                                de tu dinero.</li>
                        </ul>
                    </div>

                    <div class="subseccion">
                        <h3><span class="numero">3.</span> Canales para realizar el cambio o devolución</h3>
                        <p>Todas las solicitudes serán recibidas y atendidas por nuestro equipo de Asistencia Legal a
                            través de nuestro correo electrónico <a
                                href="mailto:horusgroupcajamarca@gmail.com">horusgroupcajamarca@gmail.com</a></p>
                    </div>

                    <div class="subseccion">
                        <h3><span class="numero">4.</span> Consideraciones para la solicitud de devolución de dinero
                        </h3>
                        <ul class="lista-puntos">
                            <li>Puedes solicitar la <strong>devolución íntegra</strong> del dinero.</li>
                            <li>En el correo electrónico, debes indicar tus datos personales, DNI, medio de pago y
                                comprobante del mismo (físico o virtual según hayas cancelado) así como el taller, curso
                                de actualización, capacitación, diplomado, etc., al cual te inscribiste y la fecha de
                                este.</li>
                        </ul>
                    </div>

                    <div class="subseccion">
                        <h3><span class="numero">5.</span> Pasos para solicitudes de devolución</h3>
                        <div class="pasos-container">
                            <div class="paso">
                                <div class="paso-numero">1</div>
                                <div class="paso-content">
                                    <h4>Envía tu solicitud</h4>
                                    <p>Comunícate mediante correo electrónico a <a
                                            href="mailto:horusgroupcajamarca@gmail.com">horusgroupcajamarca@gmail.com</a>
                                        y envía tu solicitud adjuntando tus medios probatorios:</p>
                                    <ul>
                                        <li><strong>ASUNTO:</strong> DEVOLUCIÓN DE DINERO</li>
                                        <li><strong>DESTINATARIO:</strong> Abog. Luz Karen Távara Camacho</li>
                                    </ul>
                                </div>
                            </div>
                            <div class="paso">
                                <div class="paso-numero">2</div>
                                <div class="paso-content">
                                    <h4>Espera la respuesta</h4>
                                    <p>En un plazo de <strong>2 días hábiles</strong> recibirás una respuesta por parte
                                        del equipo de Asistencia Legal. En caso ocurriera una demora excepcional, nos
                                        comunicaremos para informar.</p>
                                </div>
                            </div>
                            <div class="paso">
                                <div class="paso-numero">3</div>
                                <div class="paso-content">
                                    <h4>Recibe tu devolución</h4>
                                    <p>De ser que proceda la devolución del dinero, nos pondremos en contacto contigo
                                        para comunicarte el modo de esta.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="subseccion">
                        <h3><span class="numero">6.</span> Tiempo de devolución de dinero</h3>
                        <p>Se efectuará después de haberse aprobado la solicitud de devolución. El tiempo aproximado es
                            <strong>2 (dos) días hábiles</strong> posteriores a la aprobación.
                        </p>
                    </div>

                    <div class="subseccion">
                        <h3><span class="numero">7.</span> Resguardo de los certificados</h3>
                        <ul class="lista-puntos">
                            <li>Los certificados que no se recoja por parte del participante de algún taller, curso o
                                diplomado en la ceremonia de entrega de este quedará bajo resguardo de la empresa HORUS
                                GROUP SRL.</li>
                            <li>El tiempo de resguardo garantizado por parte la empresa es de <strong>1 mes</strong>,
                                luego de ese tiempo la empresa no se responsabiliza por el deterioro o pérdida de este.
                            </li>
                            <li>Para la entrega del certificado pasada la fecha de ceremonia de entrega, el participante
                                deberá ponerse en contacto con la empresa HORUS GROUP SRL a través del número de
                                whatsapp <strong>942 956 207</strong> (coordinadora institucional) para la coordinación
                                de la fecha de entrega o recepción del certificado.</li>
                        </ul>
                    </div>
                </div>
            </div>


            <div class="politicas-seccion fade-in">
                <div class="seccion-header">
                    <div class="seccion-icon">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                    <h2>B. COMPRA Y GARANTÍA DE EQUIPOS</h2>
                </div>

                <div class="seccion-content">

                    <div class="subseccion">
                        <h3><span class="numero">1.</span> Términos de Compra, Prohibiciones y Limitaciones</h3>
                        <ul class="lista-puntos">
                            <li>Toda orden de compra realizada a través de nuestro sitio web, o por cualquier otro medio
                                se rige por estos términos y algún otro comprobante de pago separado que puede incluir
                                otros términos y condiciones consistentes con estos puntos.</li>
                            <li>Para realizar una orden de compra tiene obligatoriamente que registrar o crear su cuenta
                                en nuestro sitio web y ser <strong>mayor de 18 años de edad</strong>.</li>
                            <li>Se puede requerir que usted provea cierta información personal y/o corporativa,
                                incluyendo, pero no limitado a su nombre, nombre de la empresa, número de documento de
                                identidad, dirección de correo electrónico, número de teléfono, dirección, código postal
                                y fecha de nacimiento.</li>
                            <li>El precio de los pedidos podrá cambiar debido cuestiones fuera de nuestro control tales
                                como asuntos normativos, impuestos, cargos de aduana, cambios en costo de envíos y/o
                                condiciones de pago.</li>
                            <li>La empresa Horus Group-Cajamarca se reserva derecho y puede cancelar la cuenta de acceso
                                de cualquier usuario si detectamos algún acto de fraude, especulaciones o actos de mala
                                fe en la utilización de cualquiera de nuestros servicios ofrecidos.</li>
                        </ul>
                    </div>

                    <div class="subseccion">
                        <h3><span class="numero">2.</span> Pasos para la realización de compra</h3>
                        <div class="pasos-compra">
                            <div class="paso-compra"><span>1</span> Regístrese en el sitio web (tiene que ser mayor de
                                18 años)</div>
                            <div class="paso-compra"><span>2</span> Seleccione el producto o servicio que desee adquirir
                            </div>
                            <div class="paso-compra"><span>3</span> Agréguelo al carrito de compras</div>
                            <div class="paso-compra"><span>4</span> Llene los datos necesarios para la compra</div>
                            <div class="paso-compra"><span>5</span> Revise que todos los datos estén correctamente
                                llenos</div>
                            <div class="paso-compra"><span>6</span> Si tiene alguna duda, comuníquese con nosotros</div>
                            <div class="paso-compra"><span>7</span> Realizar el pago correspondiente</div>
                            <div class="paso-compra"><span>8</span> Verificar su correo electrónico</div>
                            <div class="paso-compra"><span>9</span> Esperar los plazos establecidos para que le llegue
                                el producto</div>
                            <div class="paso-compra"><span>10</span> Si no llega su producto, comuníquese con nosotros
                            </div>
                        </div>
                    </div>

                    <div class="subseccion">
                        <h3><span class="numero">3.</span> Precios, impuestos y gastos de envío</h3>
                        <ul class="lista-puntos">
                            <li>Los precios que aparecen detallados en cada producto o servicio incluyen el
                                <strong>Impuesto General a las Ventas (I.G.V.)</strong>
                            </li>
                            <li>El Costo de los gastos de envío será de acuerdo al transportista donde se envíe la
                                mercadería.</li>
                            <li>Los costos de transporte que se genere el envío del producto serán cancelados en su
                                totalidad por el cliente directamente ya sea el recoger (pago contra entrega) o al
                                momento cuando la empresa Horus Group SRL hace el envío de la mercadería.</li>
                            <li>El usuario es responsable de proporcionar una dirección de entrega precisa y completa.
                            </li>
                            <li>Si el paquete es devuelto a nuestra tienda debido a una dirección incorrecta o
                                incompleta, el usuario será responsable de los costos de reenvío.</li>
                            <li>En caso de que no se pueda realizar la entrega del producto por alguna ausencia del
                                destinatario, se sugiere comunicarse directamente con la agencia de transporte.</li>
                            <li>En ningún caso se podrá realizar envíos de productos sin que se realice la verificación
                                del pago realizado por usuario (cliente).</li>
                        </ul>
                    </div>

                    <div class="subseccion">
                        <h3><span class="numero">4.</span> Condiciones de pago</h3>
                        <p>El pago se realizará en el momento de la compra. Una vez que se haya realizado el pago, se
                            enviará un correo electrónico de confirmación al usuario.</p>
                        <p>Aceptamos las siguientes formas de pago:</p>
                        <div class="metodos-pago">
                            <div class="metodo"><i class="fas fa-university"></i> Depósitos</div>
                            <div class="metodo"><i class="fas fa-exchange-alt"></i> Transferencias bancarias</div>
                            <div class="metodo"><i class="fas fa-mobile-alt"></i> Yape</div>
                            <div class="metodo"><i class="fas fa-mobile-alt"></i> Plin</div>
                        </div>
                    </div>

                    <div class="subseccion">
                        <h3><span class="numero">5.</span> Plazo de envío de productos</h3>
                        <ul class="lista-puntos">
                            <li>Los productos se enviarán a la dirección proporcionada por el usuario en el momento de
                                la compra.</li>
                            <li>Para envíos de dentro de la ciudad o región de Cajamarca los plazos estimados de llegada
                                del producto son entre <strong>1 a 5 días hábiles</strong> previa coordinación de la
                                empresa con el cliente.</li>
                            <li>Para envíos fuera de la región de Cajamarca (distintas partes del país) el plazo de
                                llegada del producto es entre <strong>5 a 7 días hábiles</strong> previa coordinación de
                                la empresa con el cliente.</li>
                        </ul>
                    </div>

                    <div class="subseccion">
                        <h3><span class="numero">6.</span> Devoluciones o reposiciones</h3>
                        <div class="alert-box">
                            <i class="fas fa-info-circle"></i>
                            <p><strong>Cambio o sustitución del producto:</strong> Para que se considere un cambio o
                                sustitución por el mismo producto, procederá siempre y cuando exista algún defecto de
                                fabricación determinado por nuestro equipo de soporte HORUS GROUP SRL.</p>
                        </div>
                        <ul class="lista-puntos">
                            <li>El usuario tendrá que enviar el producto sin golpes o rasguños en su empaque original en
                                el cual deberá de incluir todos los accesorios, manuales u otros que hayan sido
                                incluidos en el momento de la compra del producto.</li>
                            <li>El cliente tendrá que enviar un informe detallando la falla del equipo; de no
                                verificarse estas fallas por el equipo de soporte HORUS GROUP SRL, el producto será
                                devuelto y el cliente asumirá todos los costos de envío del producto.</li>
                            <li>El producto será cambiado después de la verificación de las fallas de fabricación, este
                                cambio será realizado en un plazo máximo de <strong>25 días hábiles</strong>.</li>
                            <li>En el caso de no contar con stock disponible de ese producto, la empresa HORUS GROUP SRL
                                emitirá una nota de crédito por el precio del producto comprado en dicha fecha, no se
                                realizará la devolución de dinero ya sea efectivo o digital por ningún motivo.</li>
                            <li>En ningún caso la empresa HORUS GROUP SRL se hace responsable por el software,
                                actualización de software en los equipos y datos almacenados en el producto.</li>
                            <li>No se aceptarán cambios ni devoluciones por incompatibilidad con productos de otros
                                fabricantes y/o proveedores.</li>
                            <li>No se aceptarán devoluciones de productos personalizados o productos que hayan sido
                                usados.</li>
                        </ul>
                    </div>

                    <div class="subseccion">
                        <h3><span class="numero">7.</span> Garantías sobre los productos</h3>
                        <ul class="lista-puntos">
                            <li>La garantía de los productos no se extiende a terceros, es única y exclusivamente al
                                cliente o usuario que realizó la compra.</li>
                            <li>El horario de atención a través de nuestro sitio web o línea telefónica para
                                verificación o atención al cliente es de <strong>lunes a viernes de 9:00 am a 6:00
                                    pm</strong> y los <strong>sábados de 9:00 am – 1:00 pm</strong>.</li>
                            <li>Para efectos de garantía, los productos deben de ser entregados o remitidos con sus
                                respectivas guías de remisión, boleta o factura según corresponda.</li>
                            <li>La garantía no cubre accesorios de los equipos tales como cables, fuentes de poder,
                                puertos de entrada y salida, conectores entre otros, así mismo tampoco cubre consumibles
                                o componentes que se desgasten por el uso.</li>
                            <li>La garantía no incluye ningún tipo de instalación de software o firmware, este servicio
                                tendrá un costo adicional.</li>
                            <li>La garantía no cubre daños producidos por transporte de terceros.</li>
                            <li>Todos los equipos que permanezcan en garantía, que no son reclamados hasta en un máximo
                                de <strong>2 meses</strong> luego de su entrega, serán declarados en abandono y no serán
                                devueltos.</li>
                        </ul>
                    </div>

                    <div class="subseccion">
                        <h3><span class="numero">8.</span> Externalidades</h3>
                        <ul class="lista-puntos">
                            <li>El cumplimiento de la totalidad o parte de sus obligaciones por parte de la empresa
                                HORUS GROUP SRL puede ser suspendida en caso de algún evento fortuito o de fuerza mayor
                                que pueda ocurrir y ocasione un retraso o incumplimiento.</li>
                            <li>Tales externalidades o eventos de fuerza mayor pueden ser: los disturbios sociales,
                                huelgas de cualquier naturaleza, feriados no laborables fortuitos, entre otros que
                                perjudique las actividades normales que realiza la empresa HORUS GROUP SRL.</li>
                            <li>La empresa HORUS GROUP SRL informará al cliente de ser el caso en alguna externalidad,
                                caso fortuito o de fuerza mayor dentro de <strong>1 a 3 días</strong> anteriores o
                                posteriores a su ocurrencia.</li>
                            <li>Si dicha suspensión se prolonga y va más allá de un lapso de <strong>20 días</strong>,
                                el cliente tendrá la posibilidad de poder rescindir el pedido en curso y se procederá a
                                su reembolso con las condiciones previstas en el apartado o numeral 6 de los presentes
                                términos.</li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="politicas-seccion fade-in modificaciones">
                <div class="seccion-header">
                    <div class="seccion-icon">
                        <i class="fas fa-edit"></i>
                    </div>
                    <h2>Modificación en los términos y condiciones de uso</h2>
                </div>
                <div class="seccion-content">
                    <p>Horus Group-Cajamarca SRL podrá modificar, adicionar, eliminar y/o actualizar las políticas de
                        devolución en cualquier momento, por cualquier razón y/o sin previo aviso, los cuales seguirán
                        siendo de obligatoriedad el cumplimiento de estas desde el momento de su publicación. Los
                        Términos y Condiciones pueden ser consultados en cualquier momento.</p>
                </div>
            </div>

            <div class="politicas-seccion fade-in atencion">
                <div class="seccion-header">
                    <div class="seccion-icon">
                        <i class="fas fa-headset"></i>
                    </div>
                    <h2>Atención al usuario</h2>
                </div>
                <div class="seccion-content">
                    <p>Para cualquier aclaración o duda sobre los Términos y Condiciones comunicarse al correo
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
            <h3>¿Tienes alguna <span>consulta?</span></h3>
            <p>Nuestro equipo legal está disponible para resolver cualquier duda sobre nuestras políticas.</p>
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

