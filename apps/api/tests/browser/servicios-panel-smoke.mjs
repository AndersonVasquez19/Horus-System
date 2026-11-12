// Solo http://127.0.0.1:8773 con servicios-panel-browser-router.php opt-in y SQLite :memory:.
// Usa el panel real y formularios nativos; cada petición reconstruye datos ficticios.
import { spawn } from 'node:child_process';
import { request } from 'node:http';
import { createServer } from 'node:net';
import { mkdir, mkdtemp, realpath, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, sep, resolve } from 'node:path';

const base = 'http://127.0.0.1:8773';
const debugPort = 19335;
const evidence = resolve('storage/app/private/testing/servicios-panel');
await mkdir(evidence, { recursive: true });
for (const port of [8773, debugPort]) {
  await new Promise((ok, fail) => {
    const probe = createServer();
    probe.once('error', fail);
    probe.listen(port, '127.0.0.1', () => probe.close(ok));
  });
}
const profile = await mkdtemp(join(tmpdir(), 'horus-servicios-panel-'));
const server = spawn('php', ['-S', '127.0.0.1:8773', '-t', 'public', 'tests/Support/servicios-panel-browser-router.php'], {
  env: { ...process.env, HORUS_SERVICIOS_BROWSER_FIXTURE: '1', HORUS_SERVICIOS_FIXTURE_TIME: new Date().toISOString(), APP_ENV: 'testing', DB_CONNECTION: 'sqlite', DB_DATABASE: ':memory:', DB_URL: '' },
  windowsHide: true, stdio: 'ignore',
});
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
  await waitFor(async () => (await localRequest(`${base}/admin/servicios`)).headers['x-horus-fixture'] === 'sqlite-memory-servicios');
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
      report.responses.push({ url: response.url, status: response.status, editorial: headers['x-horus-fixture-editorial'], audits: headers['x-horus-fixture-audits'] });
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
    else if (message.method === 'Page.javascriptDialogOpening') report.dialog = true;
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
  await command('Page.bringToFront');
  await command('Runtime.enable');
  await command('Network.enable');
  await command('Network.setCacheDisabled', { cacheDisabled: true });
  await command('Network.setBypassServiceWorker', { bypass: true });
  await command('Fetch.enable', { patterns: [{ urlPattern: '*' }] });
  await command('Page.addScriptToEvaluateOnNewDocument', { source: `window.__inputEvidence = []; for (const type of ['keydown', 'keyup', 'click']) document.addEventListener(type, e => { window.__inputEvidence.push({ type, key: e.key, target: e.target.outerHTML?.slice(0,180), prevented: e.defaultPrevented }); }, true);` });

  const headers = async (revision = '2', state = 'borrador', role = 'admin') => command('Network.setExtraHTTPHeaders', { headers: { 'X-Horus-Fixture-Revision': revision, 'X-Horus-Fixture-State': state, 'X-Horus-Fixture-Role': role } });
  const ready = () => evaluate('document.querySelector("[data-panel-servicios]")?.getAttribute("aria-busy") === "false"');
  const input = async (id, value) => evaluate(`(() => { const el = document.getElementById(${JSON.stringify(id)}); el.value = ${JSON.stringify(value)}; el.dispatchEvent(new Event('input', { bubbles: true })); })()`);
  const submit = async (name, status = 200) => {
    const start = report.responses.length;
    await evaluate(`document.querySelector('[data-form="${name}"]').requestSubmit()`);
    await waitFor(() => report.responses.slice(start).some(r => r.status === status));
    await waitFor(ready);
  };
  for (const width of [390, 768, 1440]) {
    await headers();
    await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width === 390 });
    await navigate('/admin/servicios', '[data-panel-servicios]');
    await screenshot(`list-${width}`);
    await evaluate(`document.querySelector('[data-servicio-id="1"]').focus()`);
    await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, text: '\r', unmodifiedText: '\r' });
    await command('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
    await waitFor(() => evaluate('!document.querySelector("[data-editor]").hidden && document.querySelector("[data-dato=nombre]").textContent.includes("ficticio")'));
    const layout = await evaluate(`(() => {
      const controls = [...document.querySelectorAll('[data-editor] input:not([type=hidden]):not([type=checkbox]), [data-editor] textarea, [data-editor] select')];
      const buttons = [...document.querySelectorAll('[data-editor] button')].filter(el => el.getClientRects().length);
      return { noPageOverflow: document.documentElement.scrollWidth <= innerWidth, labeled: controls.every(el => !!document.querySelector('label[for="'+el.id+'"]')), targets: buttons.every(el => el.getBoundingClientRect().height >= 44), keyboardFocus: document.activeElement.id === 'servicio-titulo', consent: !document.querySelector('[name=confirmacion_publica]').checked, fieldWidth: document.querySelector('#ficha-titulo').getBoundingClientRect().width };
    })()`);
    if (!layout.noPageOverflow || !layout.labeled || !layout.targets || !layout.keyboardFocus || !layout.consent || layout.fieldWidth < 150) throw new Error(`Layout failed at ${width}: ${JSON.stringify(layout)}`);
    await screenshot(`editor-${width}`);
    report.widths.push({ width, ...layout });
    // Contenido de la página pública: el formulario de tarjeta se abre, está etiquetado y no desborda.
    await evaluate('document.querySelector("[data-accion=tarjeta-nueva]").click()');
    await waitFor(() => evaluate('!document.querySelector("[data-form=tarjeta]").hidden'));
    const tarjeta = await evaluate(`(() => {
      const form = document.querySelector('[data-form=tarjeta]');
      const controles = [...form.querySelectorAll('input:not([type=hidden]), textarea, select')];
      const botones = [...form.querySelectorAll('button')];
      return { noPageOverflow: document.documentElement.scrollWidth <= innerWidth,
        etiquetados: controles.every(el => !!form.querySelector('label[for="' + el.id + '"]') || !!el.closest('label')),
        objetivos: botones.every(el => el.getBoundingClientRect().height >= 40), campo: Math.round(form.querySelector('#tarjeta-titulo').getBoundingClientRect().width) };
    })()`);
    if (!tarjeta.noPageOverflow || !tarjeta.etiquetados || !tarjeta.objetivos || tarjeta.campo < 150) throw new Error(`Card form layout failed at ${width}: ${JSON.stringify(tarjeta)}`);
    await screenshot(`tarjeta-${width}`);
    report.widths.at(-1).tarjeta = tarjeta;
    await evaluate('document.querySelector("[data-accion=tarjeta-cancelar]").click()');
    await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
    await command('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
    if (!(await evaluate('!!document.activeElement.closest("[data-editor]")'))) throw new Error('Tab did not enter editor controls');
  }
  await input('editar-nombre', 'Servicio modificado en navegador');
  await submit('administrativo');
  await headers('3');
  await input('ficha-titulo', 'Ficha revisada desde navegador');
  await submit('editorial');
  await headers('4');
  await evaluate('document.querySelector("[name=confirmacion_publica]").click()');
  await submit('autorizar');
  if (!report.responses.some(r => r.editorial === 'publicada' && r.audits === '1')) throw new Error('Publication was not confirmed inside fixture request');
  await headers('5', 'publicada');
  const startRetire = report.responses.length;
  const retiroPendiente = evaluate('document.querySelector("[data-accion=retirar]").click()');
  await waitFor(() => report.dialog);
  await command('Page.handleJavaScriptDialog', { accept: true });
  await retiroPendiente;
  report.dialog = false;
  await waitFor(() => report.responses.slice(startRetire).some(r => r.editorial === 'retirada' && r.audits === '1'));
  await waitFor(ready);
  await headers('7', 'retirada');
  await input('ficha-titulo', 'Mi borrador protegido');
  await input('editar-nombre', 'Mi nombre protegido');
  await submit('administrativo', 412);
  const conflict = await evaluate('({ draft: document.querySelector("#editar-nombre").value, visible: !document.querySelector("[data-conflicto]").hidden, blocked: document.querySelector("[data-form=administrativo] fieldset").disabled })');
  if (conflict.draft !== 'Mi nombre protegido' || !conflict.visible || !conflict.blocked) throw new Error(`Draft lost: ${JSON.stringify(conflict)}`);
  await command('Emulation.setDeviceMetricsOverride', { width: 390, height: 900, deviceScaleFactor: 1, mobile: true });
  await screenshot('conflict-390');
  await evaluate('document.querySelector("[data-accion=comparar]").click()');
  await waitFor(() => evaluate('!document.querySelector("[data-comparacion]").hidden'));
  if (!(await evaluate('document.querySelector("#editar-nombre").value === "Mi nombre protegido" && document.querySelector("[data-form=administrativo] fieldset").disabled'))) throw new Error('Comparison silently replaced draft or unlocked writes');
  await evaluate('document.querySelector("[data-accion=aceptar-version]").click()');
  if (!(await evaluate('document.querySelector("#editar-nombre").value === "Mi nombre protegido" && !document.querySelector("[data-form=administrativo] fieldset").disabled'))) throw new Error('Explicit adoption failed');
  report.conflict = conflict;
  // Navigate only after explicit beforeunload confirmation; no automatic resend.
  await headers('2', 'borrador', 'lector');
  const navegacionPendiente = command('Page.navigate', { url: base + '/admin/servicios' });
  await waitFor(() => report.dialog);
  await command('Page.handleJavaScriptDialog', { accept: true }); report.dialog = false;
  await navegacionPendiente;
  await waitFor(() => evaluate('document.readyState === "complete" && !!document.querySelector("[data-panel-servicios]") && !document.querySelector("[data-accion=nuevo]")'));
  report.reader = await evaluate('!document.querySelector("[data-form=editorial]") && !document.querySelector("[data-form=autorizar]")');
  if (!report.reader) throw new Error('Unauthorized controls rendered');
  report.serverPermission = await evaluate(`(async () => (await fetch('/admin/servicios/1/publicacion/autorizar', { method: 'POST', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'If-Match': '"servicio-1-v2"' }, body: JSON.stringify({ confirmacion_publica: true }) })).status === 403)()`);
  if (!report.serverPermission) throw new Error('Server did not reject reader publication');
  await headers('2', 'borrador', 'admin');
  await navigate('/admin/servicios', '[data-panel-servicios]');
  await evaluate('document.querySelector("[data-accion=nuevo]").click()');
  await input('crear-codigo', 'TEC-NUEVO'); await input('crear-nombre', 'Creado desde navegador');
  await submit('crear', 201);
  report.creation = await evaluate('document.querySelector("[data-dato=codigo]").textContent === "TEC-NUEVO" && document.querySelector("[data-dato=estado]").textContent === "Inactivo"');
  if (!report.creation) throw new Error('Creation failed');
  if (report.errors.length) throw new Error(`Browser exceptions: ${report.errors.join(', ')}`);
  await writeFile(join(evidence, 'report.json'), JSON.stringify(report, null, 2));
  process.stdout.write(JSON.stringify({ evidence, widths: report.widths, conflict: report.conflict, creation: report.creation, reader: report.reader, errors: report.errors, responses: report.responses }) + '\n');
} catch (error) {
  report.failure = error.message;
  if (debugEvaluate) report.page = await debugEvaluate('({ url: location.href, text: document.body.innerText.slice(0, 1800), scripts: [...document.scripts].map(s=>s.src), alpine: !!window.Alpine, busy: document.querySelector("[data-panel-servicios]")?.getAttribute("aria-busy"), active: document.activeElement?.outerHTML, focus: document.hasFocus(), input: window.__inputEvidence })').catch(() => null);
  await debugScreenshot?.('failure').catch(() => {});
  await writeFile(join(evidence, 'report.json'), JSON.stringify(report, null, 2));
  throw error;
} finally {
  socket?.close();
  browser.kill(); server.kill();
  await pause(500);
  const root = await realpath(tmpdir());
  const target = await realpath(profile);
  if (!target.startsWith(root + sep) || !target.split(sep).at(-1).startsWith('horus-servicios-panel-')) throw new Error('Unsafe profile cleanup path');
  await rm(target, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
}
