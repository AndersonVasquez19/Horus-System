// Solo http://127.0.0.1:8778 con formularios-modal-browser-router.php opt-in y SQLite :memory:.
// Usa el panel real y formularios nativos; cada petición reconstruye datos ficticios.
import { spawn } from 'node:child_process';
import { request } from 'node:http';
import { createServer } from 'node:net';
import { mkdir, mkdtemp, realpath, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, sep, resolve } from 'node:path';

const base = 'http://127.0.0.1:8778';
const debugPort = 19338;
const evidence = resolve('storage/app/private/testing/formularios-modal');
await mkdir(evidence, { recursive: true });
for (const port of [8778, debugPort]) {
  await new Promise((ok, fail) => {
    const probe = createServer();
    probe.once('error', fail);
    probe.listen(port, '127.0.0.1', () => probe.close(ok));
  });
}
const profile = await mkdtemp(join(tmpdir(), 'horus-formularios-modal-'));
const server = spawn('php', ['-S', '127.0.0.1:8778', '-t', 'public', 'tests/Support/formularios-modal-browser-router.php'], {
  env: { ...process.env, HORUS_MODAL_BROWSER_FIXTURE: '1', HORUS_MODAL_FIXTURE_TIME: new Date().toISOString(), APP_ENV: 'testing', DB_CONNECTION: 'sqlite', DB_DATABASE: ':memory:', DB_URL: '' },
  windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'],
});
let serverError = '';
server.stderr.on('data', data => { serverError = (serverError + data.toString()).slice(-6000); });
const browser = spawn(process.env.EDGE_PATH || 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', [
  '--headless=new', '--disable-gpu', '--disable-background-networking', '--no-first-run', '--no-default-browser-check',
  `--remote-debugging-port=${debugPort}`, `--user-data-dir=${profile}`,
  '--host-resolver-rules=MAP * 0.0.0.0, EXCLUDE 127.0.0.1', 'about:blank',
], { windowsHide: true, stdio: 'ignore' });
const pause = (ms) => new Promise((done) => setTimeout(done, ms));
const localRequest = (url, method = 'GET') => new Promise((ok, fail) => {
  const req = request(url, { method }, (res) => {
    let body = '';
    res.setEncoding('utf8');
    res.on('data', (part) => { body += part; });
    res.on('end', () => ok({ status: res.statusCode, headers: res.headers, body }));
  });
  req.on('error', fail);
  req.setTimeout(5000, () => req.destroy(new Error('Local fixture timed out')));
  req.end();
});
async function waitFor(fn, timeout = 20000) {
  const end = Date.now() + timeout;
  let last;
  while (Date.now() < end) {
    try { const result = await fn(); if (result) return result; } catch (error) { last = error; }
    await pause(100);
  }
  throw new Error(`Editor smoke timed out${last ? ': ' + last.message : ''}`);
}
let socket;
let debugEvaluate;
let debugScreenshot;
const report = { sqlite: ':memory: per request', widths: [], responses: [], externalBlocked: [], errors: [] };
try {
  await waitFor(async () => (await localRequest(`${base}/admin/cursos`)).headers['x-horus-fixture'] === 'sqlite-memory-modal');
  await waitFor(async () => (await localRequest(`http://127.0.0.1:${debugPort}/json/version`)).status === 200);
  const tab = JSON.parse((await localRequest(`http://127.0.0.1:${debugPort}/json/new?about:blank`, 'PUT')).body);
  socket = new WebSocket(tab.webSocketDebuggerUrl);
  await new Promise((ok, fail) => { socket.addEventListener('open', ok, { once: true }); socket.addEventListener('error', fail, { once: true }); });
  const pending = new Map();
  let sequence = 0;
  const command = (method, params = {}) => new Promise((ok, fail) => {
    const id = ++sequence;
    const timer = setTimeout(() => { pending.delete(id); fail(new Error(`CDP timeout: ${method}`)); }, 15000);
    pending.set(id, { ok, fail, timer });
    socket.send(JSON.stringify({ id, method, params }));
  });
  const record = (response) => {
    const headers = Object.fromEntries(Object.entries(response.headers || {}).map(([k, v]) => [k.toLowerCase(), v]));
    if (response.url?.startsWith(base) && headers['x-horus-fixture']) {
      report.responses.push({ status: response.status, title: decodeURIComponent(headers['x-horus-fixture-title'] || ''), published: headers['x-horus-fixture-published'] });
    }
  };
  socket.addEventListener('message', ({ data }) => {
    const message = JSON.parse(data);
    if (pending.has(message.id)) {
      const task = pending.get(message.id);
      pending.delete(message.id);
      clearTimeout(task.timer);
      message.error ? task.fail(new Error(message.error.message)) : task.ok(message.result);
    } else if (message.method === 'Network.responseReceived') record(message.params.response);
    else if (message.method === 'Network.requestWillBeSent' && message.params.redirectResponse) record(message.params.redirectResponse);
    else if (message.method === 'Runtime.exceptionThrown') report.errors.push(message.params.exceptionDetails.text);
    else if (message.method === 'Fetch.requestPaused') {
      const { requestId, request: outgoing } = message.params;
      if (outgoing.url.startsWith(base + '/')) command('Fetch.continueRequest', { requestId }).catch(() => {});
      else {
        report.externalBlocked.push(outgoing.url);
        command('Fetch.failRequest', { requestId, errorReason: 'BlockedByClient' }).catch(() => {});
      }
    }
  });
  const evaluate = async (expression) => {
    const result = await command('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (result.exceptionDetails) throw new Error(result.exceptionDetails.text);
    return result.result.value;
  };
  const navigate = async (path, selector) => {
    await command('Page.navigate', { url: base + path });
    await waitFor(() => evaluate(`location.pathname === ${JSON.stringify(path)} && document.readyState === 'complete' && !!document.querySelector(${JSON.stringify(selector)})`));
    await evaluate('document.fonts.ready');
    await waitFor(() => evaluate('!!window.Alpine'));
  };
  const screenshot = async (name) => {
    await evaluate(`(async () => {
      await document.fonts.ready;
      await new Promise(done => requestAnimationFrame(() => requestAnimationFrame(done)));
      await Promise.all(document.getAnimations().filter(a => a.effect?.getTiming().iterations !== Infinity).map(a => a.finished.catch(() => {})));
    })()`);
    const metrics = await command('Page.getLayoutMetrics');
    const size = metrics.cssContentSize;
    const shot = await command('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width: size.width, height: size.height, scale: 1 } });
    await writeFile(join(evidence, name + '.png'), Buffer.from(shot.data, 'base64'));
  };
  debugEvaluate = evaluate;
  debugScreenshot = screenshot;
  await command('Page.enable');
  await command('Runtime.enable');
  await command('Network.enable');
  await command('Network.setCacheDisabled', { cacheDisabled: true });
  await command('Network.setBypassServiceWorker', { bypass: true });
  await command('Fetch.enable', { patterns: [{ urlPattern: '*' }] });
  const check = (value, message) => { if (!value) throw new Error(message); };
  const click = selector => evaluate(`document.querySelector(${JSON.stringify(selector)}).click()`);
  const modalOpen = () => evaluate('!!document.querySelector("dialog[data-admin-modal][open]")');
  for (const width of [390, 768, 1440]) {
    await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width === 390 });
    await navigate('/admin/cursos', 'a[href$="/cursos/create"]');
    await evaluate('window.listadoConservado = true');
    await click('a[href$="/cursos/create"]');
    await waitFor(modalOpen);
    const view = await evaluate(`(() => {
      const dialog = document.querySelector('dialog[open]');
      return { width: innerWidth, fits: dialog.getBoundingClientRect().right <= innerWidth && dialog.scrollWidth <= dialog.clientWidth + 1,
        focusInside: dialog.contains(document.activeElement), locked: document.documentElement.classList.contains('admin-modal-open'),
        backgroundKept: window.listadoConservado === true, correctUrl: location.pathname === '/admin/cursos/create' };
    })()`);
    check(Object.values(view).every(Boolean), 'Modal layout or focus failed: ' + JSON.stringify(view));
    await screenshot('curso-modal-' + width);
    await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
    await command('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
    await waitFor(() => evaluate('location.pathname === "/admin/cursos" && !document.querySelector("dialog[open]")'));
    check(await evaluate(`document.activeElement.matches('a[href$="/cursos/create"]') && window.listadoConservado && !document.documentElement.classList.contains('admin-modal-open')`), 'Focus or list not restored');
    await command('Page.navigateToHistoryEntry', { entryId: (await command('Page.getNavigationHistory')).entries.at(-1).id });
    await waitFor(modalOpen);
    check(await evaluate('location.pathname === "/admin/cursos/create"'), 'Forward failed');
    report.widths.push(view);
  }
  for (const module of ['estudiantes', 'cursos', 'matriculas', 'usuarios']) {
    for (const action of ['create', '1/edit']) {
      await navigate('/admin/' + module + '/' + action, 'dialog[data-admin-page-form]');
      await waitFor(modalOpen);
      check(await evaluate('!!document.querySelector("dialog[open] form [name=_token]")'), 'Missing native form/CSRF: ' + module + action);
      check(await evaluate(`(() => { const modal = document.querySelector('dialog[open]'); return [...modal.querySelectorAll('label[for]')].every(label => label.control && modal.contains(label.control)); })()`), 'Label outside modal: ' + module + action);
    }
  }
  // Selectores de matrícula con búsqueda en el servidor: teclado, valor oculto y Escape sin cerrar el diálogo.
  await navigate('/admin/matriculas/create', '#estudiante_id'); await waitFor(modalOpen);
  // Sin foco real del documento, el navegador sin ventana no emite eventos focus/blur.
  await command('Emulation.setFocusEmulationEnabled', { enabled: true });
  const tecla = async (key, code, windowsVirtualKeyCode) => {
    await command('Input.dispatchKeyEvent', { type: 'keyDown', key, code, windowsVirtualKeyCode });
    await command('Input.dispatchKeyEvent', { type: 'keyUp', key, code, windowsVirtualKeyCode });
  };
  for (const [campo, etiqueta] of [['estudiante_id', '70123456 · Estudiante Ficticio'], ['curso_id', 'FIXTURE · Curso ficticio (19 cupos)']]) {
    await evaluate(`(() => { const campo = document.querySelector('#${campo}'); campo.blur(); campo.focus(); })()`);
    await waitFor(() => evaluate(`document.querySelectorAll('#${campo}-lista [role=option]').length === 1`));
    check(await evaluate(`document.querySelector('#${campo}').getAttribute('aria-expanded') === 'true'`), 'Combobox no abre: ' + campo);
    await tecla('ArrowDown', 'ArrowDown', 40);
    check(await evaluate(`document.querySelector('#${campo}').getAttribute('aria-activedescendant') === '${campo}-opcion-0'`), 'Combobox sin opción activa: ' + campo);
    await tecla('Enter', 'Enter', 13);
    check(await evaluate(`document.querySelector('input[type=hidden][name=${campo}]').value === '1' && document.querySelector('#${campo}').value === ${JSON.stringify(etiqueta)}`), 'Combobox no eligió: ' + campo);
    check(await modalOpen(), 'Enter del combobox cerró o envió el diálogo: ' + campo);
  }
  await evaluate(`(() => { const campo = document.querySelector('#estudiante_id'); campo.blur(); campo.focus(); })()`);
  await waitFor(() => evaluate(`document.querySelector('#estudiante_id').getAttribute('aria-expanded') === 'true'`));
  await tecla('Escape', 'Escape', 27);
  check(await evaluate(`document.querySelector('#estudiante_id').getAttribute('aria-expanded') === 'false'`), 'Escape no cerró la lista');
  check(await modalOpen(), 'Escape del combobox cerró el diálogo del formulario');
  await evaluate(`(() => { const campo = document.querySelector('#estudiante_id'); campo.value = 'sin-coincidencias'; campo.dispatchEvent(new Event('input', { bubbles: true })); })()`);
  await waitFor(() => evaluate(`document.querySelectorAll('#estudiante_id-lista [role=option]').length === 0 && /Sin resultados/.test(document.body.innerText)`));
  check(await evaluate(`document.querySelector('input[type=hidden][name=estudiante_id]').value === '1'`), 'Escribir sin elegir no debe cambiar el valor');
  await navigate('/admin/cursos/1/publicacion', '#titulo'); await waitFor(modalOpen);
  check(await evaluate('document.querySelector("[name=confirmacion_publica]").required && !document.querySelector("[name=confirmacion_publica]").checked'), 'Editorial consent changed');
  await navigate('/admin/configuracion', '#settings_nombre_empresa'); await waitFor(modalOpen);
  await navigate('/admin/cursos', 'a[href$="/cursos/1/edit"]');
  await click('a[href$="/cursos/1/edit"]'); await waitFor(modalOpen);
  await evaluate(`const f = document.querySelector('dialog[open] form'); f.noValidate = true; f.querySelector('[name=nombre]').value = 'Borrador modal conservado'; f.querySelector('[name=duracion_horas]').value = '0'; f.requestSubmit();`);
  await waitFor(() => evaluate('document.querySelector("dialog[open] [name=nombre]")?.value === "Borrador modal conservado" && !!document.querySelector("dialog[open] .admin-error")'));
  check(await evaluate('location.pathname === "/admin/cursos/1/edit"'), 'Validation did not return to form URL');
  await evaluate(`document.querySelector('dialog[open] [name=duracion_horas]').value = '10'; document.querySelector('dialog[open] form').requestSubmit();`);
  await waitFor(() => evaluate('location.pathname === "/admin/cursos/1" && document.readyState === "complete"'));
  report.nativeSave = true;
  await navigate('/admin/roles', '[data-admin-modal-open="crear-rol"]');
  await click('[data-admin-modal-open="crear-rol"]'); await waitFor(modalOpen);
  await evaluate(`const f = document.querySelector('#crear-rol form'); f.noValidate = true; f.querySelector('[name=name]').value = 'admin'; f.querySelector('[name="permissions[]"]').checked = true; f.requestSubmit();`);
  await waitFor(() => evaluate('!!document.querySelector("#crear-rol[open] [role=alert]")'));
  check(await evaluate(`document.querySelector('#crear-rol [name=name]').value === 'admin' && document.querySelector('#crear-rol input[type=checkbox]').checked`), 'Role draft lost');
  await click('#crear-rol [data-admin-modal-close]');
  check(!(await modalOpen()), 'Role did not close');
  const editId = await evaluate('document.querySelector("[data-admin-modal-open^=editar-rol-]").dataset.adminModalOpen');
  await click('[data-admin-modal-open="' + editId + '"]'); await waitFor(modalOpen);
  await navigate('/admin/aprobaciones', '[aria-controls="form-nueva-aprobacion"]');
  await click('[aria-controls="form-nueva-aprobacion"]'); await waitFor(modalOpen);
  await click('#form-nueva-aprobacion [data-admin-modal-close]');
  await click('[aria-controls="form-nueva-aprobacion"]'); await waitFor(modalOpen);
  check(await evaluate('document.querySelector("#form-nueva-aprobacion").contains(document.activeElement)'), 'Approval focus missing');
  await click('#form-nueva-aprobacion [data-admin-modal-close]');
  await evaluate(`document.querySelector('button[aria-expanded][class*=admin-btn-danger]').click()`);
  await waitFor(() => evaluate('!!document.querySelector("#rechazar-2[open]")'));
  await click('#rechazar-2 [data-admin-modal-close]');
  await evaluate(`Array.from(document.querySelectorAll('button')).find(button => button.textContent.includes('Reabrir solicitud')).click()`);
  await waitFor(() => evaluate('!!document.querySelector("#reabrir-3[open]")'));
  await screenshot('aprobacion-modal-1440');
  for (const module of ['servicios', 'cotizaciones']) {
    await navigate('/admin/' + module, '[data-accion=nuevo]');
    await click('[data-accion=nuevo]'); await waitFor(modalOpen);
    check(await evaluate('document.querySelector("dialog[open]").contains(document.querySelector("[data-errores]"))'), 'Editor errors outside modal');
    await click('dialog[open] [data-admin-modal-close]');
    check(!(await modalOpen()), 'Editor failed to close: ' + module);
  }
  if (report.errors.length) throw new Error(`Browser exceptions: ${report.errors.join(', ')}`);
  await writeFile(join(evidence, 'report.json'), JSON.stringify(report, null, 2));
  process.stdout.write(JSON.stringify({ evidence, ...report }) + '\n');
} catch (error) {
  report.failure = error.message;
  report.serverError = serverError;
  if (debugEvaluate) report.page = await debugEvaluate('({ url: location.href, text: document.body.innerText.slice(0, 1800) })').catch(() => null);
  await debugScreenshot?.('failure').catch(() => {});
  await writeFile(join(evidence, 'report.json'), JSON.stringify(report, null, 2));
  throw error;
} finally {
  socket?.close();
  browser.kill(); server.kill();
  await pause(500);
  const root = await realpath(tmpdir());
  const target = await realpath(profile);
  if (!target.startsWith(root + sep) || !target.split(sep).at(-1).startsWith('horus-formularios-modal-')) throw new Error('Unsafe profile cleanup path');
  await rm(target, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
}
