// Smoke del Libro de Reclamaciones (E2) con Edge headless.
// Dos servidores PHP locales con tests/Support/reclamaciones-browser-router.php (SQLite en memoria, sin red externa):
// :8776 con el módulo encendido SOLO en ese proceso y :8777 apagado. Opcional: SMOKE_SCREENSHOTS=<carpeta> guarda capturas.
import { spawn } from 'node:child_process';
import { mkdtemp, realpath, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, sep } from 'node:path';
import { request } from 'node:http';

const encendido = 'http://127.0.0.1:8776';
const apagado = 'http://127.0.0.1:8777';
const debugPort = 19338;
const capturas = process.env.SMOKE_SCREENSHOTS || '';
const profile = await mkdtemp(join(tmpdir(), 'horus-reclamaciones-smoke-'));
const servidor = (puerto, modo) => spawn('php', ['-S', `127.0.0.1:${puerto}`, '-t', 'public', 'tests/Support/reclamaciones-browser-router.php'], {
  env: { ...process.env, HORUS_RECLAMACIONES_BROWSER_FIXTURE: '1', HORUS_RECLAMACIONES_FIXTURE_MODO: modo, APP_ENV: 'testing', DB_CONNECTION: 'sqlite', DB_DATABASE: ':memory:', DB_URL: '' },
  stdio: 'ignore',
});
const servidores = [servidor(8776, 'encendido'), servidor(8777, 'apagado')];
const browser = spawn(process.env.EDGE_PATH || 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', [
  '--headless=new', '--disable-gpu', '--disable-background-networking', '--no-first-run',
  `--remote-debugging-port=${debugPort}`, `--user-data-dir=${profile}`,
  '--host-resolver-rules=MAP * 0.0.0.0, EXCLUDE 127.0.0.1', 'about:blank',
], { stdio: 'ignore' });
const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const localRequest = (url, method = 'GET') => new Promise((resolve, reject) => {
  const req = request(url, { method }, (res) => {
    let body = '';
    res.setEncoding('utf8');
    res.on('data', (part) => { body += part; });
    res.on('end', () => resolve({ status: res.statusCode, body }));
  });
  req.on('error', reject);
  req.setTimeout(8000, () => req.destroy(new Error('Local fixture request timed out.')));
  req.end();
});
async function waitFor(fn, timeout = 20000, etiqueta = 'condición') {
  const end = Date.now() + timeout;
  while (Date.now() < end) {
    try { const result = await fn(); if (result) return result; } catch { /* Arranque o navegación. */ }
    await pause(150);
  }
  throw new Error(`Reclamaciones smoke timed out: ${etiqueta}.`);
}
const fallar = (motivo, datos) => { throw new Error(`${motivo}: ${JSON.stringify(datos)}`); };

let socket;
const resultado = { sqlite: ':memory:' };
try {
  await waitFor(async () => (await localRequest(`${encendido}/libro-reclamaciones`)).status === 200, 30000, 'servidor encendido');
  await waitFor(async () => (await localRequest(`${apagado}/libro-reclamaciones`)).status === 200, 30000, 'servidor apagado');
  await waitFor(async () => (await localRequest(`http://127.0.0.1:${debugPort}/json/version`)).status === 200, 20000, 'Edge');
  const page = JSON.parse((await localRequest(`http://127.0.0.1:${debugPort}/json/new?about:blank`, 'PUT')).body);
  socket = new WebSocket(page.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, { once: true }); socket.addEventListener('error', reject, { once: true }); });
  const pending = new Map();
  let id = 0;
  socket.addEventListener('message', ({ data }) => {
    const r = JSON.parse(data);
    if (!pending.has(r.id)) return;
    const promise = pending.get(r.id);
    pending.delete(r.id);
    r.error ? promise.reject(new Error(r.error.message)) : promise.resolve(r.result);
  });
  const command = (method, params = {}) => new Promise((resolve, reject) => {
    const next = ++id;
    pending.set(next, { resolve, reject });
    socket.send(JSON.stringify({ id: next, method, params }));
  });
  const evaluate = async (expression) => {
    const r = await command('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
    return r.result.value;
  };
  const viewport = (ancho, alto, movil) => command('Emulation.setDeviceMetricsOverride', { width: ancho, height: alto, deviceScaleFactor: 1, mobile: movil });
  // Captura de la pantalla visible tras desplazarse al elemento: las animaciones de entrada solo se activan en pantalla.
  const captura = async (nombre, selector) => {
    if (!capturas) return;
    await evaluate(`(() => { document.querySelector(${JSON.stringify(selector)})?.scrollIntoView({ block: 'start' }); return true; })()`);
    await pause(900);
    const { data } = await command('Page.captureScreenshot', { format: 'png' });
    await writeFile(join(capturas, `${nombre}.png`), Buffer.from(data, 'base64'));
  };
  // Cuenta las peticiones POST del formulario para comprobar el doble clic.
  const abrir = async (url) => {
    await command('Page.navigate', { url });
    await waitFor(() => evaluate(`document.readyState === 'complete' && !!document.querySelector('.libro-hero')`), 20000, `carga de ${url}`);
    await evaluate(`(() => { window.__posts = 0; const f = window.fetch; window.fetch = (...a) => { if ((a[1]?.method || 'GET') === 'POST') window.__posts++; return f(...a); }; })()`);
    await evaluate('document.fonts.ready.then(() => true)');
  };
  const sinDesborde = () => evaluate('document.documentElement.scrollWidth <= innerWidth');
  const rellenar = (valores) => evaluate(`(() => {
    for (const [nombre, valor] of Object.entries(${JSON.stringify(valores)})) {
      const campo = document.querySelector('[name="' + nombre + '"]');
      campo.value = valor;
      campo.dispatchEvent(new Event('input', { bubbles: true }));
      campo.dispatchEvent(new Event('change', { bubbles: true }));
    }
    return true;
  })()`);
  const clic = (selector) => evaluate(`(() => { document.querySelector(${JSON.stringify(selector)}).click(); return true; })()`);
  const pasoActivo = () => evaluate(`document.querySelector('.form-paso.activo')?.id`);
  const textoError = (nombre) => evaluate(`document.getElementById('error-${nombre}')?.textContent || ''`);
  const personales = {
    nombres: 'Persona', apellidos: 'Ficticia', tipo_documento: 'dni', numero_documento: '12345678',
    email: 'persona.ficticia@example.test', telefono: '987654321', domicilio: 'Calle Ficticia 123, Cajamarca',
  };
  await command('Runtime.enable');
  await command('Page.enable');

  // 1) Módulo apagado (Opción A): formulario completo, recorrible y validado, sin ningún POST, en escritorio y móvil.
  // Los POST se cuentan en la red del navegador (CDP Network), no solo en window.fetch.
  await command('Network.enable');
  const postsRed = [];
  socket.addEventListener('message', ({ data }) => {
    const r = JSON.parse(data);
    if (r.method === 'Network.requestWillBeSent' && r.params.request.method !== 'GET') postsRed.push(r.params.request.url);
  });
  for (const [ancho, alto, movil, etiqueta] of [[1280, 900, false, 'escritorio'], [390, 844, true, 'movil']]) {
    await viewport(ancho, alto, movil);
    await abrir(`${apagado}/libro-reclamaciones`);
    const tipo = movil ? 'reclamo' : 'queja';
    const estado = await evaluate(`({
      tarjetas: !!document.getElementById('btn-reclamo') && !!document.getElementById('btn-queja'),
      formulario: !!document.getElementById('libroForm') && document.getElementById('libroForm').dataset.habilitado === '0',
      sinMarcaNiToken: !document.querySelector('[name="marca"], [name="token_envio"]'),
      sinExito: !document.getElementById('exito-container'),
      sinTarjetaAntigua: !document.getElementById('registro-no-disponible'),
      botonDeshabilitadoHtml: document.getElementById('btn-enviar').disabled,
      textoBoton: document.getElementById('btn-enviar').innerText.trim(),
    })`);
    await captura(`apagado-${etiqueta}-tarjetas`, '#seleccion-tipo');
    await clic(`#btn-${tipo}`);
    estado.tarjetaSeleccionada = await evaluate(`document.getElementById('btn-${tipo}').getAttribute('aria-pressed') === 'true' && !document.getElementById('formulario-section').hidden`);
    estado.modoConsulta = await evaluate(`!!document.getElementById('modo-consulta') && document.getElementById('modo-consulta').offsetHeight > 0`);
    // Validación visual del paso 1: vacío no avanza y marca errores.
    await clic('#btn-paso1-siguiente');
    estado.validaPaso1 = (await pasoActivo()) === 'paso-1' && (await textoError('nombres')).length > 0;
    await rellenar(personales);
    estado.noOverflowPaso1 = await sinDesborde();
    await captura(`apagado-${etiqueta}-paso1`, '#paso-1');
    await clic('#btn-paso1-siguiente');
    estado.paso2 = (await pasoActivo()) === 'paso-2';
    await clic('#btn-paso2-siguiente');
    estado.validaPaso2 = (await pasoActivo()) === 'paso-2' && (await textoError('detalle')).length > 0;
    if (tipo === 'reclamo') {
      estado.camposBien = await evaluate(`[...document.querySelectorAll('[data-condicion="reclamo"]')].every((g) => !g.hidden)`);
      await clic('input[name="bien_tipo"][value="servicio"]');
      await rellenar({ monto_bien: '150.00', bien_descripcion: 'Curso sintético de prueba' });
    }
    await rellenar({ detalle: 'Detalle sintético de prueba, sin envío al servidor.', pedido: 'Pedido sintético.' });
    estado.noOverflowPaso2 = await sinDesborde();
    await captura(`apagado-${etiqueta}-paso2`, '#paso-2');
    await clic('#btn-paso2-siguiente');
    estado.paso3 = (await pasoActivo()) === 'paso-3';
    estado.resumen = await evaluate(`document.getElementById('resumen-reclamo').innerText.includes('Pedido sintético.')`);
    // Regresar y volver a avanzar.
    await clic('#btn-paso3-anterior');
    estado.regresa = (await pasoActivo()) === 'paso-2';
    await clic('#btn-paso2-siguiente');
    await clic('#acepta-terminos');
    estado.aviso = await evaluate(`(() => { const a = document.getElementById('aviso-envio-deshabilitado'); return !!a && a.offsetHeight > 0 && a.innerText.includes('todavía no se encuentra habilitado'); })()`);
    estado.noOverflowPaso3 = await sinDesborde();
    estado.alturaBoton = await evaluate(`document.getElementById('btn-enviar').getBoundingClientRect().height`);
    await captura(`apagado-${etiqueta}-paso3`, '#aviso-envio-deshabilitado');
    // Intentos de envío: clic, requestSubmit, Enter y botón reactivado a mano. Ninguno debe salir a la red.
    await pause(5500);
    await evaluate(`(() => {
      const f = document.getElementById('libroForm'); const b = document.getElementById('btn-enviar');
      b.click(); f.requestSubmit();
      document.getElementById('acepta-terminos').dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
      b.disabled = false; b.click(); f.requestSubmit(b);
      return true;
    })()`);
    await pause(1500);
    Object.assign(estado, await evaluate(`({
      postsFetch: window.__posts,
      botonSigueDeshabilitado: document.getElementById('btn-enviar').disabled,
      sinConfirmacion: !document.body.innerText.includes('Registrado Exitosamente') && !/\\d{9}-\\d{4}/.test(document.body.innerText),
      sigueEnPaso3: document.querySelector('.form-paso.activo')?.id === 'paso-3',
      url: location.pathname,
    })`));
    estado.postsRed = postsRed.length;
    // Petición directa con la sesión y el CSRF válidos de la página, saltándose el botón: el servidor responde 404.
    estado.postDirecto = await evaluate(`fetch('/libro-reclamaciones', { method: 'POST', body: new FormData(document.getElementById('libroForm')),
      headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then((r) => r.status)`);
    postsRed.length = 0;
    if (estado.postDirecto !== 404 ||!estado.tarjetas || !estado.formulario || !estado.sinMarcaNiToken || !estado.sinExito || !estado.sinTarjetaAntigua
      || !estado.botonDeshabilitadoHtml || estado.textoBoton !== 'Envío temporalmente deshabilitado' || !estado.tarjetaSeleccionada
      || !estado.modoConsulta || !estado.validaPaso1 || !estado.paso2 || !estado.validaPaso2 || (tipo === 'reclamo' && !estado.camposBien)
      || !estado.paso3 || !estado.resumen || !estado.regresa || !estado.aviso || estado.alturaBoton < 44
      || !estado.noOverflowPaso1 || !estado.noOverflowPaso2 || !estado.noOverflowPaso3
      || estado.postsFetch !== 0 || estado.postsRed !== 0 || !estado.botonSigueDeshabilitado || !estado.sinConfirmacion
      || !estado.sigueEnPaso3 || estado.url !== '/libro-reclamaciones') fallar(`Formulario apagado (${etiqueta})`, estado);
    resultado[`apagado_${etiqueta}`] = estado;
  }
  await command('Network.disable');

  // 2) Queja en escritorio: validación en el navegador, bloques condicionales, doble clic y confirmación real.
  await viewport(1280, 900, false);
  await abrir(`${encendido}/libro-reclamaciones`);
  await clic('#btn-queja');
  const queja = {};
  queja.bloquesReclamoOcultos = await evaluate(`[...document.querySelectorAll('[data-condicion="reclamo"]')].every((g) => g.hidden && [...g.querySelectorAll('input,textarea')].every((c) => c.disabled))`);
  queja.sinSelectDuplicado = await evaluate(`!document.querySelector('[name="tipo-registro"], #area, #fecha-incidente, #acepta-comunicaciones')`);
  await rellenar({ ...personales, numero_documento: '123' });
  await clic('#btn-paso1-siguiente');
  queja.errorDni = await textoError('numero_documento');
  if ((await pasoActivo()) !== 'paso-1' || !queja.errorDni.includes('8 dígitos')) fallar('Validación de DNI', queja);
  await clic('#es_menor');
  queja.representanteVisible = await evaluate(`[...document.querySelectorAll('[data-condicion="menor"]')].every((g) => !g.hidden)`);
  await clic('#es_menor');
  await rellenar({ numero_documento: '12345678' });
  await clic('#btn-paso1-siguiente');
  if ((await pasoActivo()) !== 'paso-2') fallar('Avance al paso 2', queja);
  await clic('#btn-paso2-siguiente');
  queja.errorPedidoVacio = await textoError('pedido');
  await rellenar({ detalle: 'Detalle sintético de una queja de prueba sobre la atención recibida.', pedido: 'Mejorar la atención.' });
  await clic('#btn-paso2-siguiente');
  if ((await pasoActivo()) !== 'paso-3') fallar('Avance al paso 3', queja);
  queja.resumen = await evaluate(`document.getElementById('resumen-reclamo').innerText.includes('Mejorar la atención.') && document.getElementById('resumen-reclamo').innerText.includes('Queja')`);
  await clic('#btn-enviar');
  queja.errorTerminos = await textoError('terminos');
  await clic('#acepta-terminos');
  await pause(5500); // La marca anti-spam exige al menos 5 segundos desde que se mostró el formulario.
  await evaluate(`(() => { const b = document.getElementById('btn-enviar'); b.click(); b.click(); document.getElementById('libroForm').requestSubmit(); return true; })()`);
  await waitFor(() => evaluate(`!document.getElementById('exito-container').hidden`), 20000, 'confirmación de queja');
  Object.assign(queja, await evaluate(`({
    numero: document.getElementById('numero-reclamo').textContent,
    tipo: document.getElementById('exito-tipo').textContent,
    fechaLimite: document.getElementById('exito-fecha-limite').textContent,
    titulo: document.querySelector('#exito-container h2').textContent,
    sinMencionCorreo: !document.getElementById('exito-correo') && !document.body.innerText.includes('Te hemos enviado'),
    formularioOculto: document.getElementById('libroForm').hidden,
    posts: window.__posts,
  })`));
  await captura('queja-confirmacion-escritorio', '#exito-container');
  if (!queja.bloquesReclamoOcultos || !queja.sinSelectDuplicado || !queja.representanteVisible || !queja.errorPedidoVacio || !queja.resumen
    || !queja.errorTerminos || !/^\d{9}-\d{4}$/.test(queja.numero) || queja.tipo !== 'Queja' || !/^\d{2}\/\d{2}\/\d{4}$/.test(queja.fechaLimite)
    || !queja.titulo.includes('Queja') || !queja.sinMencionCorreo || !queja.formularioOculto || queja.posts !== 1) fallar('Flujo de queja', queja);
  resultado.queja = queja;

  // 3) Reclamo en móvil: campos del bien, «no aplica», error 422 del servidor junto a su campo y reintento.
  await viewport(390, 844, true);
  await abrir(`${encendido}/libro-reclamaciones`);
  await clic('#btn-reclamo');
  const reclamo = {};
  reclamo.bloquesReclamoVisibles = await evaluate(`[...document.querySelectorAll('[data-condicion="reclamo"]')].every((g) => !g.hidden)`);
  await rellenar(personales);
  await clic('#es_menor');
  await rellenar({ representante_nombre: 'Representante Ficticio', representante_telefono: '987654322', representante_email: 'representante@example.test', representante_domicilio: 'Calle Ficticia 123, Cajamarca' });
  reclamo.noOverflowPaso1 = await sinDesborde();
  await captura('reclamo-paso1-movil', '#paso-1');
  await clic('#btn-paso1-siguiente');
  if ((await pasoActivo()) !== 'paso-2') fallar('Reclamo: avance al paso 2', reclamo);
  await rellenar({ monto_bien: 'abc', bien_descripcion: 'Curso sintético de prueba', detalle: 'Detalle sintético del reclamo de prueba.', pedido: 'Devolución del pago.' });
  await clic('#btn-paso2-siguiente');
  reclamo.errorBienTipo = await textoError('bien_tipo');
  reclamo.errorMonto = await textoError('monto_bien');
  await clic('input[name="bien_tipo"][value="servicio"]');
  await clic('#monto_no_aplica');
  reclamo.montoDeshabilitado = await evaluate(`document.getElementById('monto_bien').disabled && document.getElementById('monto_bien').value === ''`);
  reclamo.noOverflowPaso2 = await sinDesborde();
  await captura('reclamo-paso2-movil', '#paso-2');
  await clic('#btn-paso2-siguiente');
  if ((await pasoActivo()) !== 'paso-3') fallar('Reclamo: avance al paso 3', reclamo);
  await clic('#acepta-terminos');
  // El navegador no limita la longitud de un valor asignado por script: el servidor responde 422 en «detalle».
  await evaluate(`(() => { document.getElementById('detalle').value = 'a'.repeat(3001); return true; })()`);
  await pause(5500);
  await clic('#btn-enviar');
  await waitFor(() => evaluate(`!document.getElementById('form-alerta').hidden && !document.getElementById('btn-enviar').disabled`), 20000, 'respuesta 422');
  Object.assign(reclamo, await evaluate(`({
    pasoTras422: document.querySelector('.form-paso.activo').id,
    errorDetalle: document.getElementById('error-detalle').textContent,
    detalleMarcado: document.getElementById('detalle').getAttribute('aria-invalid') === 'true',
    alerta: document.getElementById('form-alerta').textContent,
  })`));
  await captura('reclamo-error-422-movil', '#form-alerta');
  await rellenar({ detalle: 'Detalle sintético del reclamo de prueba, corregido.' });
  await clic('#btn-paso2-siguiente');
  await clic('#btn-enviar');
  await waitFor(() => evaluate(`!document.getElementById('exito-container').hidden`), 20000, 'confirmación de reclamo');
  Object.assign(reclamo, await evaluate(`({
    numero: document.getElementById('numero-reclamo').textContent,
    tipo: document.getElementById('exito-tipo').textContent,
    fechaLimite: document.getElementById('exito-fecha-limite').textContent,
    posts: window.__posts,
    noOverflowExito: document.documentElement.scrollWidth <= innerWidth,
    botonVolver: document.querySelector('.btn-volver').getBoundingClientRect().height,
  })`));
  await captura('reclamo-confirmacion-movil', '#exito-container');
  if (!reclamo.bloquesReclamoVisibles || !reclamo.noOverflowPaso1 || !reclamo.noOverflowPaso2 || !reclamo.errorBienTipo || !reclamo.errorMonto
    || !reclamo.montoDeshabilitado || reclamo.pasoTras422 !== 'paso-2' || !reclamo.errorDetalle || !reclamo.detalleMarcado || !reclamo.alerta
    || !/^\d{9}-\d{4}$/.test(reclamo.numero) || reclamo.tipo !== 'Reclamo' || !/^\d{2}\/\d{2}\/\d{4}$/.test(reclamo.fechaLimite)
    || reclamo.posts !== 2 || !reclamo.noOverflowExito || reclamo.botonVolver < 44) fallar('Flujo de reclamo', reclamo);
  resultado.reclamo = reclamo;

  // 4) Envío demasiado rápido: el 422 de la marca anti-spam se muestra en la alerta general.
  await viewport(1280, 900, false);
  await abrir(`${encendido}/libro-reclamaciones`);
  await clic('#btn-queja');
  await rellenar({ ...personales, detalle: 'Detalle sintético para un envío inmediato.', pedido: 'Pedido sintético.' });
  await clic('#btn-paso1-siguiente');
  await clic('#btn-paso2-siguiente');
  await clic('#acepta-terminos');
  await clic('#btn-enviar');
  await waitFor(() => evaluate(`!document.getElementById('form-alerta').hidden && !document.getElementById('btn-enviar').disabled`), 20000, 'alerta de marca');
  const rapido = await evaluate(`({ alerta: document.getElementById('form-alerta').textContent, exito: !document.getElementById('exito-container').hidden })`);
  if (!rapido.alerta.includes('demasiado rápido') || rapido.exito) fallar('Envío rápido', rapido);
  resultado.envioRapido = rapido;

  process.stdout.write(`${JSON.stringify(resultado)}\n`);
} finally {
  socket?.close(); browser.kill(); servidores.forEach((s) => s.kill());
  const root = await realpath(tmpdir());
  const target = await realpath(profile);
  if (!target.startsWith(root + sep) || !target.split(sep).at(-1).startsWith('horus-reclamaciones-smoke-')) throw new Error('Unsafe browser profile cleanup path.');
  await rm(target, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
}
