// Los formularios mantienen sus envíos nativos, CSRF y redirecciones de Laravel.
export function esEnlaceFormulario(href, base) {
  const url = new URL(href, base);
  return url.origin === new URL(base).origin
    && /\/admin\/(estudiantes|cursos|matriculas|usuarios)\/(create|\d+\/(edit|publicacion))$/.test(url.pathname);
}

export function sincronizarModal(dialogo, abierto) {
  if (!dialogo || typeof dialogo.showModal !== 'function') return;
  if (abierto && !dialogo.open) dialogo.showModal();
  if (!abierto && dialogo.open) dialogo.close();
  document.documentElement.classList.toggle('admin-modal-open', !!document.querySelector('dialog[data-admin-modal][open]'));
}

export function iniciarModales() {
  if (!document.querySelector('.admin-surface') || typeof HTMLDialogElement.prototype.showModal !== 'function') return;
  let cargando = false;
  let temporal = null;
  let origen = null;
  let disparador = null;

  const preparar = dialogo => {
    if (dialogo.dataset.modalPreparado) return;
    dialogo.dataset.modalPreparado = '1';
    const cerrar = () => {
      if (dialogo.dataset.modalBusy === 'true') return;
      if (dialogo === temporal) history.back();
      else if (dialogo.dataset.returnUrl) window.location.assign(dialogo.dataset.returnUrl);
      else {
        dialogo.dispatchEvent(new CustomEvent('admin-modal-cerrar'));
        sincronizarModal(dialogo, false);
      }
    };
    dialogo.addEventListener('cancel', event => { event.preventDefault(); cerrar(); });
    dialogo.addEventListener('click', event => {
      const boton = event.target.closest('[data-admin-modal-close]');
      // Un clic en el espacio del diálogo no debe cerrar el formulario.
      const rect = dialogo.getBoundingClientRect();
      const fondo = event.target === dialogo && (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom);
      if (boton || fondo) { event.preventDefault(); cerrar(); }
      const enlace = event.target.closest('a[href]');
      if (dialogo === temporal && enlace && enlace.href === dialogo.dataset.returnUrl) { event.preventDefault(); cerrar(); }
    });
    dialogo.addEventListener('close', () => {
      document.documentElement.classList.toggle('admin-modal-open', !!document.querySelector('dialog[data-admin-modal][open]'));
    });
    if (dialogo.hasAttribute('data-auto-open')) sincronizarModal(dialogo, true);
  };
  document.querySelectorAll('dialog[data-admin-modal]').forEach(preparar);

  window.addEventListener('popstate', () => {
    if (!temporal) return;
    sincronizarModal(temporal, false);
    temporal.remove();
    temporal = null;
    document.title = origen.titulo;
    disparador?.focus();
  });
  // Si se vuelve al formulario con Adelante, se recupera su página completa.
  window.addEventListener('popstate', () => {
    if (origen && window.location.href !== origen.url && !temporal) window.location.reload();
  });

  document.addEventListener('click', async event => {
    const boton = event.target.closest('[data-admin-modal-open]');
    if (boton) {
      const dialogo = document.getElementById(boton.dataset.adminModalOpen);
      if (dialogo) { preparar(dialogo); sincronizarModal(dialogo, true); }
      return;
    }
    const enlace = event.target.closest('a[href]');
    if (!enlace || enlace.closest('dialog') || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey
      || enlace.hasAttribute('download') || (enlace.target && enlace.target !== '_self') || !esEnlaceFormulario(enlace.href, window.location.href)) return;
    event.preventDefault();
    if (cargando) return;
    cargando = true;
    enlace.setAttribute('aria-busy', 'true');
    const urlInicial = window.location.href;
    try {
      const respuesta = await fetch(enlace.href, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'text/html' } });
      if (window.location.href !== urlInicial) return;
      if (!respuesta.ok || respuesta.redirected) { window.location.assign(enlace.href); return; }
      const pagina = new DOMParser().parseFromString(await respuesta.text(), 'text/html');
      const formulario = pagina.querySelector('dialog[data-admin-page-form]');
      if (!formulario) { window.location.assign(enlace.href); return; }
      formulario.querySelectorAll('script').forEach(script => script.remove());
      temporal = document.importNode(formulario, true);
      temporal.removeAttribute('data-auto-open');
      origen = { url: urlInicial, titulo: document.title };
      disparador = enlace;
      document.body.append(temporal);
      preparar(temporal);
      // La URL real del formulario permite a Laravel devolver errores e input previo
      // al mismo formulario cuando se envía, sin guardar borradores en el navegador.
      history.pushState({ modalFormulario: true }, '', enlace.href);
      document.title = pagina.title;
      sincronizarModal(temporal, true);
    } catch {
      if (window.location.href === urlInicial) window.location.assign(enlace.href);
    } finally {
      cargando = false;
      enlace.removeAttribute('aria-busy');
    }
  });
}
