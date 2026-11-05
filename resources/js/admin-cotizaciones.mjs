const campos = ['contacto_nombre', 'contacto_email', 'contacto_telefono', 'necesidad_cliente', 'servicio_uuid'];
const vacio = () => Object.fromEntries(campos.map(c => [c, '']));
const copiar = s => Object.fromEntries(campos.map(c => [c, s?.[c] ?? '']));
const uuidValido = s => /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(s || '');
const versionValida = (id, etag) => uuidValido(id) && new RegExp(`^"cotizacion-${id}-v[1-9][0-9]*"$`).test(etag || '');
const etiquetas = { contacto_nombre: 'Nombre de contacto', contacto_email: 'Correo', contacto_telefono: 'Teléfono', necesidad_cliente: 'Necesidad',
  servicio_uuid: 'UUID del servicio', servicio_nombre_historico: 'Nombre histórico', codigo: 'Código', estado: 'Estado', responsable_id: 'Responsable (ID)',
  recibida_at: 'Recepción', proximo_seguimiento: 'Próximo seguimiento', ultimo_seguimiento_at: 'Último seguimiento', revision: 'Revisión' };
function presentar(campo, valor) {
  if (valor === null || valor === undefined || valor === '') return 'Sin indicar';
  if (campo === 'estado' || campo === 'anterior') return valor === 'recibida' ? 'Recibida' : valor === 'en_revision' ? 'En revisión' : 'Pendiente de consulta';
  if (campo.endsWith('_at')) {
    const fecha = new Date(valor);
    return Number.isNaN(fecha.getTime()) ? 'Fecha no disponible' : new Intl.DateTimeFormat('es-PE', { dateStyle: 'short', timeStyle: 'short', timeZone: 'America/Lima' }).format(fecha) + ' Lima';
  }
  if (campo === 'proximo_seguimiento' && /^\d{4}-\d{2}-\d{2}/.test(valor)) return valor.slice(0, 10).split('-').reverse().join('/');
  return String(valor);
}

// Estado privado en memoria. Sin almacenamiento, telemetría ni reintentos automáticos.
export function panelCotizaciones({ endpoint, csrf, permisos, fetcher = globalThis.fetch, uuid = () => crypto.randomUUID(), cambio = () => {} }) {
  const e = { abierto: false, ocupado: false, detalle: null, etag: '', borrador: vacio(), base: vacio(),
    responsable: '', nota: '', proximo: '', baseResponsable: '', baseProximo: '', bloqueo: '', comparacion: null,
    intencion: null, errores: [], mensaje: '', historial: null, listadoPendiente: false };
  const avisar = () => cambio(e);
  const pendiente = () => JSON.stringify(e.borrador) !== JSON.stringify(e.base) || e.nota !== '' || e.responsable !== e.baseResponsable || e.proximo !== e.baseProximo;
  function rechazar(status) {
    if (![401, 403, 419, 404, 409, 412, 428, 422].includes(status) && e.intencion) e.intencion.incierta = true;
    e.errores = [status === 422 ? 'Revisa los campos: nombre y necesidad obligatorios, correo o teléfono válido, UUID y fechas válidos; texto sin HTML.'
      : status === 409 ? 'La intención, asociación o transición entra en conflicto. Compara la versión actual antes de decidir.'
      : [412, 428].includes(status) ? 'La revisión cambió o está ausente. Tu borrador se conserva; compara la versión actual.'
      : [401, 403, 419, 404].includes(status) ? 'La sesión, permisos o disponibilidad cambiaron. Tu borrador se conserva; vuelve a acceder.'
      : 'Resultado incierto. No reenvíes ni cambies la intención. Conserva el borrador y concilia deliberadamente.'];
    e.bloqueo = [401, 403, 419, 404].includes(status) ? 'acceso'
      : [409, 412, 428].includes(status) ? 'conflicto' : status === 422 ? '' : 'incierto';
  }
  async function pedir(url, opciones = {}) {
    const controlador = new AbortController();
    const timer = setTimeout(() => controlador.abort(), 12000);
    try {
      const r = await fetcher(url, { ...opciones, credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: controlador.signal,
        headers: { Accept: 'application/json', ...opciones.headers } });
      let datos = null;
      try { datos = await r.json(); } catch { /* Una escritura con respuesta ilegible requiere conciliación. */ }
      return { r, datos, etag: r.headers.get('ETag') };
    } finally { clearTimeout(timer); }
  }
  function cargar(datos, etag, conservar = false) {
    e.detalle = datos; e.etag = etag; e.abierto = true;
    if (!conservar) {
      e.borrador = copiar(datos); e.responsable = datos.responsable_id?.toString() ?? ''; e.proximo = datos.proximo_seguimiento?.slice(0, 10) ?? ''; e.nota = '';
    }
    e.base = copiar(datos); e.baseResponsable = datos.responsable_id?.toString() ?? ''; e.baseProximo = datos.proximo_seguimiento?.slice(0, 10) ?? '';
  }
  async function enviar(i) {
    e.ocupado = true; e.errores = []; e.mensaje = 'Guardando…'; avisar();
    try {
      const { r, datos, etag } = await pedir(i.url, { method: i.method, headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf,
        ...(i.etag ? { 'If-Match': i.etag } : {}), ...(i.clave ? { 'Idempotency-Key': i.clave } : {}) }, body: i.body });
      if (!r.ok) { rechazar(r.status); if (r.status === 422) { if (!i.incierta) e.intencion = null; else e.bloqueo = 'incierto'; } return false; }
      const s = datos?.solicitud;
      if (!versionValida(s?.identificador, etag) || typeof s?.codigo !== 'string' || (i.id && s.identificador !== i.id)) { rechazar(500); return false; }
      // El recibo mínimo confirma la escritura; nunca se usa como si fuera un detalle completo.
      e.detalle = { ...e.detalle, identificador: s.identificador, codigo: s.codigo }; e.etag = etag;
      if (i.accion === 'informacion') e.base = copiar(JSON.parse(i.body));
      if (i.accion === 'seguimiento') { e.nota = ''; e.baseProximo = e.proximo; }
      if (i.accion === 'responsable') e.baseResponsable = e.responsable;
      e.intencion = null; e.bloqueo = ''; e.comparacion = null; e.listadoPendiente = true;
      e.mensaje = 'Operación confirmada. Consulta el detalle actualizado; los demás borradores se conservan.';
      // Refresca datos guardados sin reemplazar ningún borrador pendiente.
      await consultar(s.identificador, false, true);
      await panel.historia(1, true);
      return true;
    } catch { rechazar(500); return false; }
    finally { e.ocupado = false; if (e.errores.length) e.mensaje = ''; avisar(); }
  }
  async function consultar(id, comparar, conservar = false) {
    try {
      const { r, datos, etag } = await pedir(`${endpoint}/${id}`);
      if (!r.ok) { if ([401, 403, 419, 404].includes(r.status)) rechazar(r.status); else e.errores = ['No se pudo consultar. El borrador se conserva; puedes volver a consultar.']; return false; }
      if (datos?.solicitud?.identificador !== id || !versionValida(id, etag) || !Number.isInteger(datos.solicitud.revision)) {
        e.errores = ['No se recibió un detalle válido. El borrador se conserva.']; return false;
      }
      if (comparar) e.comparacion = { datos: datos.solicitud, etag };
      else cargar(datos.solicitud, etag, conservar);
      return true;
    } catch { e.errores = ['No se pudo consultar. El borrador se conserva; puedes volver a consultar.']; return false; }
  }
  const panel = {
    estado: e, pendiente,
    nuevo() {
      if (!permisos.crear || e.ocupado || e.bloqueo) return false;
      Object.assign(e, { abierto: true, detalle: null, etag: '', borrador: vacio(), base: vacio(), responsable: '', baseResponsable: '', proximo: '', baseProximo: '', nota: '', intencion: null, comparacion: null, historial: null, errores: [], mensaje: '' }); avisar(); return true;
    },
    async abrir(id, comparar = false) {
      if (!uuidValido(id) || e.ocupado || (e.bloqueo && !comparar)) return false;
      e.ocupado = true; e.errores = []; avisar();
      try {
        const ok = await consultar(id, comparar);
        if (ok && !comparar) { e.historial = null; await panel.historia(1, true); }
        return ok;
      } finally { e.ocupado = false; avisar(); }
    },
    comparar() { return e.detalle?.identificador ? panel.abrir(e.detalle.identificador, true) : false; },
    adoptar() {
      if (e.ocupado || !e.comparacion || e.bloqueo === 'acceso') return false;
      cargar(e.comparacion.datos, e.comparacion.etag, true); e.comparacion = null;
      if (e.intencion?.incierta) {
        // Seguimiento idempotente puede conciliarse con revisión adoptada y cuerpo/clave originales.
        if (e.intencion.accion === 'seguimiento') e.intencion.etag = e.etag;
        e.bloqueo = 'incierto';
      } else { e.bloqueo = ''; e.intencion = null; }
      e.errores = []; e.mensaje = 'Revisión comparada confirmada. Borradores conservados; revisa antes de guardar.'; avisar(); return true;
    },
    revisado() {
      if (e.ocupado || !e.comparacion || e.bloqueo !== 'incierto' || e.intencion?.clave) return false;
      cargar(e.comparacion.datos, e.comparacion.etag, true); e.comparacion = null; e.intencion = null; e.bloqueo = ''; e.errores = [];
      e.mensaje = 'Resultado revisado por el operador. No se reenvió la escritura; los borradores se conservan.'; avisar(); return true;
    },
    async conciliar() {
      if (e.ocupado || e.bloqueo !== 'incierto' || !e.intencion?.clave) return false;
      return enviar(e.intencion);
    },
    async guardar(accion) {
      if (e.ocupado || e.bloqueo) return false;
      const id = e.detalle?.identificador;
      const permiso = accion === 'informacion' ? (id ? 'editar' : 'crear') : 'gestionar';
      if (!permisos[permiso] || (!id && accion !== 'informacion') || (id && !e.etag)) return false;
      let cuerpo, sufijo = '', method = 'PATCH', clave = null;
      if (accion === 'informacion') {
        cuerpo = Object.fromEntries(campos.map(c => [c, e.borrador[c] || null]));
        if (id && e.detalle.servicio_uuid) delete cuerpo.servicio_uuid;
        method = id ? 'PUT' : 'POST'; if (!id) clave = uuid();
      } else if (accion === 'estado') { cuerpo = { estado: 'en_revision' }; sufijo = '/estado'; }
      else if (accion === 'responsable') { cuerpo = { responsable_id: e.responsable === '' ? null : Number(e.responsable) }; sufijo = '/responsable'; }
      else if (accion === 'seguimiento') { cuerpo = { nota: e.nota, proximo_seguimiento: e.proximo || null }; sufijo = '/seguimientos'; method = 'POST'; clave = uuid(); }
      else return false;
      e.intencion = { accion, id, url: `${endpoint}${id ? '/' + id : ''}${sufijo}`, method, body: JSON.stringify(cuerpo), clave, etag: id ? e.etag : null };
      return enviar(e.intencion);
    },
    async historia(page = 1, interno = false) {
      if (!e.detalle?.identificador || (e.ocupado && !interno) || page < 1) return false;
      if (!interno) { e.ocupado = true; avisar(); }
      try {
        const { r, datos } = await pedir(`${endpoint}/${e.detalle.identificador}/historial?page=${page}`);
        if (!r.ok || !Array.isArray(datos?.data)) { e.errores = ['No se pudo cargar el historial privado.']; return false; }
        e.historial = datos; return true;
      } catch { e.errores = ['No se pudo cargar el historial privado.']; return false; }
      finally { if (!interno) { e.ocupado = false; avisar(); } }
    },
  };
  return panel;
}

function conectar(root) {
  const q = s => root.querySelector(s);
  const mostrar = (s, visible) => { const el = q(s); if (el) el.hidden = !visible; };
  const permisos = { crear: root.dataset.crear === '1', editar: root.dataset.editar === '1', gestionar: root.dataset.gestionar === '1' };
  function render(e) {
    root.setAttribute('aria-busy', String(e.ocupado));
    q('[data-mensaje]').textContent = e.mensaje;
    q('[data-errores]').replaceChildren(...e.errores.map(t => { const p = document.createElement('p'); p.textContent = t; return p; }));
    mostrar('[data-errores]', !!e.errores.length); mostrar('[data-editor]', e.abierto); mostrar('[data-detalle]', !!e.detalle);
    mostrar('[data-listado-pendiente]', e.listadoPendiente); mostrar('[data-gestion]', !!e.detalle);
    q('#cotizacion-titulo').textContent = e.detalle ? 'Detalle de solicitud' : 'Nueva solicitud';
    for (const el of root.querySelectorAll('[data-dato]')) {
      el.textContent = presentar(el.dataset.dato, e.detalle?.[el.dataset.dato]);
    }
    mostrar('[data-conciliacion]', !!e.bloqueo || !!e.comparacion);
    mostrar('[data-comparacion]', !!e.comparacion);
    q('[data-comparacion]').textContent = e.comparacion ? Object.entries(etiquetas).map(([c, etiqueta]) => `${etiqueta}: ${presentar(c, e.comparacion.datos[c])}`).join('\n\n') : '';
    mostrar('[data-accion="adoptar"]', !!e.comparacion && (e.bloqueo !== 'incierto' || e.intencion?.accion === 'seguimiento'));
    mostrar('[data-accion="revisado"]', !!e.comparacion && e.bloqueo === 'incierto' && !e.intencion?.clave);
    mostrar('[data-accion="conciliar"]', e.bloqueo === 'incierto' && !!e.intencion?.clave);
    for (const form of root.querySelectorAll('[data-form]')) {
      const tipo = form.dataset.form;
      form.hidden = tipo === 'informacion' ? !(e.detalle ? permisos.editar : permisos.crear) : !e.detalle;
      form.querySelector('fieldset').disabled = e.ocupado || !!e.bloqueo;
      for (const input of form.querySelectorAll('input, textarea')) {
        const valor = tipo === 'informacion' ? e.borrador[input.name] : tipo === 'responsable' ? e.responsable : input.name === 'nota' ? e.nota : e.proximo;
        if (input.value !== (valor ?? '')) input.value = valor ?? '';
        if (input.name === 'servicio_uuid') input.readOnly = !!e.detalle?.servicio_uuid;
      }
    }
    mostrar('[data-transicion]', e.detalle?.estado === 'recibida');
    for (const b of root.querySelectorAll('[data-accion], [data-abrir]')) {
      b.disabled = e.ocupado || (!!e.bloqueo && !['comparar', 'adoptar', 'conciliar', 'revisado', 'historial-anterior', 'historial-siguiente'].includes(b.dataset.accion));
    }
    const h = e.historial;
    mostrar('[data-historial]', !!h);
    q('[data-eventos]').replaceChildren(...(h?.data ?? []).map(f => {
      const article = document.createElement('article'); article.className = 'rounded-xl border border-stone-200 p-3 whitespace-pre-wrap [overflow-wrap:anywhere]';
      const m = f.metadatos ?? {}, cambios = [];
      if (Array.isArray(m.campos)) cambios.push('Campos: ' + m.campos.map(c => etiquetas[c] ?? 'Asociación del servicio').join(', '));
      if (m.estado) cambios.push(`${presentar('estado', m.anterior)} → ${presentar('estado', m.estado)}`);
      if (Object.hasOwn(m, 'responsable_id')) cambios.push(`Responsable (ID): ${m.responsable_id ?? 'Sin asignar'}`);
      article.textContent = `Revisión ${f.revision} · ${f.evento.replaceAll('_', ' ')} · Operador ${f.actor_id ?? 'eliminado'} · ${presentar('created_at', f.created_at)}\n${f.nota ?? ''}\n${cambios.join('\n')}`; return article;
    }));
    q('[data-rango-historial]').textContent = h ? `${h.total} acontecimientos; ${h.from ?? 0}–${h.to ?? 0}. Página ${h.current_page} de ${h.last_page}.` : '';
    q('[data-accion="historial-anterior"]').disabled = e.ocupado || !h || h.current_page <= 1;
    q('[data-accion="historial-siguiente"]').disabled = e.ocupado || !h || h.current_page >= h.last_page;
  }
  const panel = panelCotizaciones({ endpoint: root.dataset.endpoint, csrf: document.querySelector('meta[name=csrf-token]').content, permisos, cambio: render });
  const foco = () => (q('[data-errores]').hidden ? q('#cotizacion-titulo') : q('[data-errores]')).focus();
  const abandonar = () => !panel.pendiente() || window.confirm('Hay borradores sin guardar. ¿Descartarlos para continuar?');
  root.addEventListener('click', async event => {
    const b = event.target.closest('button'); if (!b || b.disabled) return;
    if (b.dataset.abrir && abandonar()) { await panel.abrir(b.dataset.abrir); foco(); }
    const a = b.dataset.accion;
    if (a === 'nuevo' && abandonar()) { panel.nuevo(); q('#c-contacto_nombre')?.focus(); }
    if (a === 'comparar') { await panel.comparar(); foco(); }
    if (['adoptar', 'revisado', 'conciliar'].includes(a) && window.confirm('¿Confirmas esta decisión después de revisar el resultado y conservar tus borradores?')) { await panel[a](); foco(); }
    if (a?.startsWith('historial-')) await panel.historia(panel.estado.historial.current_page + (a.endsWith('anterior') ? -1 : 1));
  });
  for (const f of root.querySelectorAll('[data-form]')) {
    f.addEventListener('input', event => {
      const input = event.target, e = panel.estado;
      if (f.dataset.form === 'informacion') e.borrador[input.name] = input.value;
      else if (f.dataset.form === 'responsable') e.responsable = input.value;
      else if (input.name === 'nota') e.nota = input.value;
      else e.proximo = input.value;
    });
    f.addEventListener('submit', async event => { event.preventDefault(); await panel.guardar(f.dataset.form); foco(); });
  }
  q('[data-filtros]').addEventListener('submit', event => {
    event.preventDefault(); if (!abandonar() || panel.estado.ocupado || panel.estado.bloqueo) return;
    const url = new URL(root.dataset.endpoint, location.origin);
    for (const [k, v] of new FormData(event.target)) if (v.trim()) url.searchParams.set(k, v.trim());
    location.assign(url);
  });
  root.addEventListener('click', event => { if (event.target.closest('a') && (panel.estado.ocupado || panel.estado.bloqueo || !abandonar())) event.preventDefault(); });
  window.addEventListener('beforeunload', event => { if (panel.pendiente() || panel.estado.ocupado || panel.estado.bloqueo === 'incierto') { event.preventDefault(); event.returnValue = ''; } });
  render(panel.estado);
}
if (typeof document !== 'undefined') {
  const iniciar = () => document.querySelectorAll('[data-panel-cotizaciones]').forEach(conectar);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar, { once: true }); else iniciar();
}
