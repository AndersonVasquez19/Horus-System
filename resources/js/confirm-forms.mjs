// Confirmación previa al envío de un formulario: `<form data-confirm="¿Seguro?">`.
// Sustituye al atributo en línea `onsubmit="return confirm(...)"`, que una Content-Security-Policy sin
// 'unsafe-inline' en scripts no ejecutaría (y el formulario se enviaría sin pedir confirmación).
export function mensajeDeConfirmacion(destino) {
  const form = destino?.closest?.('form[data-confirm]');
  const mensaje = form?.dataset?.confirm;
  return typeof mensaje === 'string' && mensaje.trim() !== '' ? mensaje : null;
}

export function iniciarConfirmaciones(documento = document, confirmar = (mensaje) => window.confirm(mensaje)) {
  // Un único escuchador delegado: también cubre formularios insertados después (modales del panel).
  documento.addEventListener('submit', (evento) => {
    const mensaje = mensajeDeConfirmacion(evento.target);
    if (mensaje !== null && !confirmar(mensaje)) evento.preventDefault();
  });
}
