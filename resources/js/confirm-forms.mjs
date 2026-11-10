// Confirmación previa al envío de un formulario: `<form data-confirm="¿Seguro?">`.
// Sustituye al atributo en línea `onsubmit="return confirm(...)"`, que una Content-Security-Policy sin
// 'unsafe-inline' en scripts no ejecutaría (y el formulario se enviaría sin pedir confirmación).
export function mensajeDeConfirmacion(destino) {
  const form = destino?.closest?.('form[data-confirm]');
  const mensaje = form?.dataset?.confirm;
  return typeof mensaje === 'string' && mensaje.trim() !== '' ? mensaje : null;
}

export function esMutacionSincrona(destino) {
  const form = destino?.closest?.('form') ?? (destino?.tagName === 'FORM' ? destino : null);
  if (!form) return null;
  const metodo = String(form.getAttribute?.('method') ?? form.method ?? 'GET').toUpperCase();
  return metodo === 'POST' ? form : null;
}

export function iniciarConfirmaciones(documento = document, confirmar = (mensaje) => window.confirm(mensaje)) {
  // Un único escuchador delegado: también cubre formularios insertados después (modales del panel).
  documento.addEventListener('submit', (evento) => {
    if (evento.defaultPrevented) return;
    const formMutacion = esMutacionSincrona(evento.target);
    if (formMutacion?.dataset?.enviando === '1') {
      evento.preventDefault();
      return;
    }
    const mensaje = mensajeDeConfirmacion(evento.target);
    if (mensaje !== null && !confirmar(mensaje)) {
      evento.preventDefault();
      return;
    }
    if (formMutacion && !evento.defaultPrevented && formMutacion.dataset) {
      formMutacion.dataset.enviando = '1';
      formMutacion.setAttribute?.('aria-busy', 'true');
      const boton = evento.submitter ?? formMutacion.querySelector?.('button[type="submit"], input[type="submit"]');
      if (boton) {
        boton.disabled = true;
        boton.setAttribute?.('aria-busy', 'true');
      }
    }
  });
}
