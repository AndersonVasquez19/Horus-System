const camposEditoriales = ['titulo', 'resumen', 'descripcion', 'categoria', 'vigente_desde', 'vigente_hasta'];
const copiar = (origen, campos) => Object.fromEntries(campos.map(campo => [campo, origen?.[campo] ?? '']));
const estados = { sin_ficha: 'Sin ficha', borrador: 'Borrador', publicada: 'Publicada', retirada: 'Retirada', programada: 'Programada', caducada: 'Caducada', activo: 'Activo', inactivo: 'Inactivo', archivado: 'Archivado' };
const etiqueta = valor => Object.hasOwn(estados, valor) ? estados[valor] : (valor || '—');

export function textoServicio(campo, valor) {
  return ['estado', 'estado_editorial'].includes(campo) ? etiqueta(valor) : (valor ?? '—');
}

export function panelServicios({ endpoint, csrf, fetcher = (...args) => fetch(...args), cambio = () => {} }) {
  const estado = { abierto: false, ocupado: false, detalle: null, etag: '', admin: {}, editorial: {}, baseAdmin: {}, baseEditorial: {}, destino: 'inactivo', mensaje: '', errores: [], bloqueo: '', comparacion: null, listadoPendiente: false };
  const pendiente = () => JSON.stringify(estado.admin) !== JSON.stringify(estado.baseAdmin) || JSON.stringify(estado.editorial) !== JSON.stringify(estado.baseEditorial);
  const avisar = () => cambio(estado);
  const error = mensaje => { estado.errores = [mensaje]; };
  function recibir(datos, etag, reiniciar = 'todos') {
    estado.detalle = datos; estado.etag = etag; estado.abierto = true;
    const admin = copiar(datos.servicio, ['codigo', 'nombre', 'categoria']);
    const editorial = copiar(datos.publicacion, camposEditoriales);
    if (reiniciar === 'todos' || reiniciar === 'administrativo' || reiniciar === 'crear') estado.admin = { ...admin };
    if (reiniciar === 'todos' || reiniciar === 'editorial' || reiniciar === 'crear') estado.editorial = { ...editorial };
    estado.baseAdmin = admin; estado.baseEditorial = editorial; estado.destino = datos.servicio.estado;
  }
  async function pedir(url, opciones = {}) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 12000);
    try {
      const respuesta = await fetcher(url, { ...opciones, credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: controller.signal,
        headers: { Accept: 'application/json', ...opciones.headers } });
      let datos = null;
      try { datos = await respuesta.json(); } catch { /* El estado HTTP determina si hubo un rechazo conocido. */ }
      return { respuesta, datos, etag: respuesta.headers.get('ETag') };
    } finally { clearTimeout(timer); }
  }
  function valido(datos, etag, id = null) {
    return Number.isSafeInteger(datos?.servicio?.id) && datos.servicio.id > 0
      && (id === null || datos.servicio.id === id) && etag?.match(new RegExp(`^"servicio-${datos.servicio.id}-v[1-9][0-9]*"$`));
  }
  function rechazo(status, datos) {
    if ([401, 403, 419].includes(status)) {
      estado.bloqueo = 'acceso'; error('La sesión o los permisos cambiaron. Conserva tu texto y vuelve a acceder antes de continuar.');
    } else if ([412, 428].includes(status)) {
      estado.bloqueo = 'conflicto'; error('El servicio cambió o falta su revisión. Tu borrador se conserva. Compara la versión actual antes de guardar.');
    } else if (status === 422) {
      estado.errores = Object.values(datos?.errors || {}).flat().filter(texto => typeof texto === 'string').slice(0, 20).map(texto => texto.slice(0, 500));
      if (!estado.errores.length) error('Revisa los campos indicados antes de guardar.');
    } else if (status === 409) {
      error('La operación no es compatible con el estado actual. Revisa el estado, la ficha y su vigencia; una ficha publicada debe retirarse antes de editarla.');
    } else if (status === 404) {
      estado.bloqueo = 'acceso'; error('El servicio ya no está disponible. Tu borrador se conserva.');
    } else {
      estado.bloqueo = 'incierto'; error('No se pudo confirmar el resultado. No reenvíes la operación. Conserva tu borrador y revisa la versión actual o el listado.');
    }
  }
  const panel = {
    estado, pendiente,
    nuevo() {
      if (estado.ocupado || estado.bloqueo) return false;
      estado.abierto = true; estado.detalle = null; estado.etag = ''; estado.admin = copiar(null, ['codigo', 'nombre', 'categoria']);
      estado.editorial = copiar(null, camposEditoriales); estado.baseAdmin = { ...estado.admin }; estado.baseEditorial = { ...estado.editorial };
      estado.errores = []; estado.mensaje = ''; estado.comparacion = null; avisar(); return true;
    },
    async abrir(id, comparar = false) {
      if (estado.ocupado || !Number.isSafeInteger(id) || id < 1 || (estado.bloqueo && !comparar)) return false;
      estado.ocupado = true; estado.errores = []; estado.mensaje = 'Cargando servicio…'; avisar();
      try {
        const { respuesta, datos, etag } = await pedir(`${endpoint}/${id}`);
        if (!respuesta.ok) {
          if ([401, 403, 419].includes(respuesta.status)) rechazo(respuesta.status, datos);
          else error('No se pudo consultar el servicio. Tu borrador se conserva. Puedes volver a consultar.');
          estado.mensaje = ''; return false;
        }
        if (!valido(datos, etag, id)) { error('No se pudo leer una versión válida del servicio. Tu borrador se conserva.'); return false; }
        if (comparar) estado.comparacion = { datos, etag };
        else { recibir(datos, etag); estado.bloqueo = ''; estado.comparacion = null; }
        estado.mensaje = comparar ? 'Versión actual cargada. Compara todos sus campos con tu borrador.' : 'Servicio cargado.';
        return true;
      } catch { error('No se pudo cargar el servicio. Tu borrador se conserva. Puedes volver a consultar.'); return false; }
      finally { estado.ocupado = false; if (estado.mensaje === 'Cargando servicio…') estado.mensaje = ''; avisar(); }
    },
    comparar() { return estado.detalle ? panel.abrir(estado.detalle.servicio.id, true) : false; },
    aceptarVersion() {
      if (estado.ocupado || !estado.comparacion || estado.bloqueo === 'acceso') return false;
      recibir(estado.comparacion.datos, estado.comparacion.etag, 'conservar');
      estado.comparacion = null; estado.bloqueo = ''; estado.errores = []; estado.mensaje = 'Versión revisada. Tu borrador se conserva; revisa las diferencias antes de guardar.'; avisar(); return true;
    },
    async guardar(accion) {
      if (estado.ocupado || estado.bloqueo) return false;
      const id = estado.detalle?.servicio.id;
      if (accion !== 'crear' && (!id || !estado.etag)) return false;
      if (accion === 'autorizar' && pendiente()) { error('Guarda o revisa los cambios pendientes antes de autorizar la ficha guardada.'); avisar(); return false; }
      const rutas = {
        crear: ['POST', endpoint, { ...copiar(estado.admin, ['codigo', 'nombre', 'categoria']), estado: 'inactivo' }],
        administrativo: ['PUT', `${endpoint}/${id}`, copiar(estado.admin, ['nombre', 'categoria'])],
        estado: ['PATCH', `${endpoint}/${id}/estado`, { estado: estado.destino }],
        editorial: ['PUT', `${endpoint}/${id}/publicacion`, copiar(estado.editorial, camposEditoriales)],
        autorizar: ['POST', `${endpoint}/${id}/publicacion/autorizar`, { confirmacion_publica: true }],
        retirar: ['DELETE', `${endpoint}/${id}/publicacion`, {}],
      };
      if (!Object.hasOwn(rutas, accion)) return false;
      const [method, url, payload] = rutas[accion];
      estado.ocupado = true; estado.errores = []; estado.mensaje = 'Guardando…'; avisar();
      try {
        const { respuesta, datos, etag } = await pedir(url, { method, headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, ...(id ? { 'If-Match': estado.etag } : {}) }, body: JSON.stringify(payload) });
        if (!respuesta.ok) { rechazo(respuesta.status, datos); estado.mensaje = ''; return false; }
        if (!valido(datos, etag, accion === 'crear' ? null : id)) { rechazo(500, null); estado.mensaje = ''; return false; }
        recibir(datos, etag, accion); estado.listadoPendiente = true; estado.mensaje = 'Operación confirmada. El catálogo público continúa desactivado.'; return true;
      } catch { rechazo(500, null); estado.mensaje = ''; return false; }
      finally { estado.ocupado = false; avisar(); }
    },
  };
  return panel;
}

function conectar(root) {
  const q = selector => root.querySelector(selector);
  const mostrar = (selector, visible) => { const el = q(selector); if (el) el.hidden = !visible; };
  const formularios = [...root.querySelectorAll('[data-form]')];
  function render(estado) {
    root.setAttribute('aria-busy', String(estado.ocupado));
    q('[data-mensaje]').textContent = estado.mensaje;
    mostrar('[data-listado-pendiente]', estado.listadoPendiente);
    const errores = q('[data-errores]');
    errores.replaceChildren(...estado.errores.map(texto => { const p = document.createElement('p'); p.textContent = texto; return p; }));
    errores.hidden = !estado.errores.length;
    mostrar('[data-editor]', estado.abierto);
    const existente = !!estado.detalle;
    mostrar('[data-detalle]', existente); mostrar('[data-ficha]', existente);
    q('#servicio-titulo').textContent = existente ? 'Revisar servicio' : 'Nuevo servicio';
    const publicada = estado.detalle?.publicacion?.estado === 'publicada';
    mostrar('[data-aviso-publicada]', publicada);
    mostrar('[data-conflicto]', ['conflicto', 'incierto'].includes(estado.bloqueo) && existente);
    mostrar('[data-comparacion]', !!estado.comparacion); mostrar('[data-accion="aceptar-version"]', !!estado.comparacion);
    if (estado.comparacion) {
      const { servicio, publicacion, estado_editorial } = estado.comparacion.datos;
      q('[data-comparacion]').textContent = JSON.stringify({ codigo: servicio.codigo, nombre: servicio.nombre, categoria: servicio.categoria, estado: servicio.estado, estado_editorial,
        ficha: publicacion ? { ...copiar(publicacion, camposEditoriales), estado: publicacion.estado } : null }, null, 2);
    }
    for (const el of root.querySelectorAll('[data-dato]')) {
      const campo = el.dataset.dato;
      el.textContent = textoServicio(campo, campo === 'estado_editorial' ? estado.detalle?.estado_editorial : estado.detalle?.servicio?.[campo]);
    }
    for (const el of root.querySelectorAll('[data-publicacion]')) el.textContent = estado.detalle?.publicacion?.[el.dataset.publicacion] || '—';
    mostrar('[data-sin-ficha]', !estado.detalle?.publicacion);
    for (const form of formularios) {
      const tipo = form.dataset.form;
      form.hidden = tipo === 'crear' ? existente : !existente;
      if (tipo === 'autorizar') form.hidden = !existente || !estado.detalle.publicacion || publicada;
      const fieldset = form.querySelector('fieldset');
      fieldset.disabled = estado.ocupado || !!estado.bloqueo || (tipo === 'editorial' && publicada) || (tipo === 'autorizar' && panel.pendiente());
      const valores = tipo === 'editorial' ? estado.editorial : (['crear', 'administrativo'].includes(tipo) ? estado.admin : { estado: estado.destino });
      for (const input of form.querySelectorAll('input:not([type=hidden]):not([type=checkbox]), textarea, select')) {
        const valor = valores[input.name] ?? '';
        if (input.value !== valor) input.value = valor;
      }
      if ((estado.errores.length || estado.ocupado) && tipo === 'autorizar') form.querySelector('[type=checkbox]').checked = false;
    }
    mostrar('[data-accion="retirar"]', existente && publicada);
    for (const boton of root.querySelectorAll('[data-servicio-id], [data-accion]')) {
      boton.disabled = estado.ocupado || (!!estado.bloqueo && !['comparar', 'aceptar-version'].includes(boton.dataset.accion));
    }
    if (existente) {
      const fila = q(`[data-fila-servicio="${estado.detalle.servicio.id}"]`);
      if (fila) {
        fila.querySelector('[data-nombre]').textContent = estado.detalle.servicio.nombre;
        fila.querySelector('[data-estado]').textContent = etiqueta(estado.detalle.servicio.estado);
        fila.querySelector('[data-editorial]').textContent = etiqueta(estado.detalle.estado_editorial);
      }
    }
  }
  const panel = panelServicios({ endpoint: root.dataset.endpoint, csrf: document.querySelector('meta[name=csrf-token]').content, cambio: render });
  const enfocar = () => q('[data-errores]').hidden ? q('#servicio-titulo').focus() : q('[data-errores]').focus();
  const abandonar = () => !panel.pendiente() || window.confirm('Hay cambios sin guardar. ¿Descartarlos para abrir otro servicio?');
  root.addEventListener('click', async event => {
    const boton = event.target.closest('button');
    if (!boton || boton.disabled) return;
    const accion = boton.dataset.accion;
    if (boton.dataset.servicioId && abandonar()) { await panel.abrir(Number(boton.dataset.servicioId)); enfocar(); }
    if (accion === 'nuevo' && abandonar()) { panel.nuevo(); q('#crear-codigo')?.focus(); }
    if (accion === 'comparar') { await panel.comparar(); enfocar(); }
    if (accion === 'aceptar-version') { panel.aceptarVersion(); enfocar(); }
    if (accion === 'retirar' && window.confirm('¿Retirar la publicación de este servicio?')) { await panel.guardar('retirar'); enfocar(); }
  });
  for (const form of formularios) {
    form.addEventListener('input', event => {
      const input = event.target;
      if (input.name === '_token' || input.type === 'checkbox') return;
      if (form.dataset.form === 'estado') panel.estado.destino = input.value;
      else (form.dataset.form === 'editorial' ? panel.estado.editorial : panel.estado.admin)[input.name] = input.value;
      render(panel.estado);
    });
    form.addEventListener('submit', async event => {
      event.preventDefault();
      const crear = form.dataset.form === 'crear';
      await panel.guardar(form.dataset.form);
      if (crear && panel.estado.detalle && !panel.estado.errores.length) form.reset();
      enfocar();
    });
  }
  window.addEventListener('beforeunload', event => {
    if (panel.pendiente() || panel.estado.ocupado || panel.estado.bloqueo === 'incierto') { event.preventDefault(); event.returnValue = ''; }
  });
  render(panel.estado);
}

if (typeof document !== 'undefined') {
  const iniciar = () => { for (const root of document.querySelectorAll('[data-panel-servicios]')) conectar(root); };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar, { once: true });
  else iniciar();
}
