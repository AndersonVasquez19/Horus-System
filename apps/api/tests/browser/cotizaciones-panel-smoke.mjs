// Solo http://127.0.0.1:8775 con cotizaciones-panel-browser-router.php opt-in y SQLite :memory:.
// Usa el panel real y formularios nativos; cada petición reconstruye datos ficticios.
import { spawn } from 'node:child_process';
import { request } from 'node:http';
import { createServer } from 'node:net';
import { mkdir, mkdtemp, realpath, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, sep, resolve } from 'node:path';

const base = 'http://127.0.0.1:8775';
const debugPort = 19337;
const evidence = resolve('storage/app/private/testing/cotizaciones-panel');
await mkdir(evidence, { recursive: true });
for (const port of [8775, debugPort]) {
  await new Promise((ok, fail) => {
    const probe = createServer();
    probe.once('error', fail);
    probe.listen(port, '127.0.0.1', () => probe.close(ok));
  });
}
const profile = await mkdtemp(join(tmpdir(), 'horus-cotizaciones-panel-'));
const server = spawn('php', ['-S', '127.0.0.1:8775', '-t', 'public', 'tests/Support/cotizaciones-panel-browser-router.php'], {
  env: { ...process.env, HORUS_COTIZACIONES_BROWSER_FIXTURE: '1', HORUS_COTIZACIONES_FIXTURE_TIME: new Date().toISOString(), APP_ENV: 'testing', DB_CONNECTION: 'sqlite', DB_DATABASE: ':memory:', DB_URL: '' },
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
  await waitFor(async () => (await localRequest(`${base}/admin/cotizaciones`)).headers['x-horus-fixture'] === 'sqlite-memory-cotizaciones');
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
  const ready = () => evaluate('document.querySelector("[data-panel-cotizaciones]")?.getAttribute("aria-busy") === "false"');
  for (const width of [390, 768, 1440]) {
    await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width === 390 });
    await navigate('/admin/cotizaciones', '[data-panel-cotizaciones]');
    await screenshot(`list-${width}`);
    await evaluate('document.querySelector("[data-abrir]").focus()');
    await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, text: '\r' });
    await command('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
    await waitFor(() => evaluate('!document.querySelector("[data-editor]").hidden && !!document.querySelector("[data-dato=revision]").textContent'));
    await waitFor(ready);
    const layout = await evaluate(`(() => {
      const controls = [...document.querySelectorAll('[data-editor] input, [data-editor] textarea')];
      const buttons = [...document.querySelectorAll('[data-editor] button')].filter(el => el.getClientRects().length);
      return { noPageOverflow: document.documentElement.scrollWidth <= innerWidth,
        labeled: controls.every(el => !!document.querySelector('label[for="'+el.id+'"]')),
        targets: buttons.every(el => el.getBoundingClientRect().height >= 44),
        keyboardFocus: document.activeElement.id === 'cotizacion-titulo' };
    })()`);
    if (Object.values(layout).some(v => !v)) throw new Error(`Layout ${width}: ${JSON.stringify(layout)}`);
    await screenshot(`detail-${width}`); report.widths.push({ width, ...layout });
    await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
    await command('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
    if (!(await evaluate('!!document.activeElement.closest("[data-editor]")'))) throw new Error('Tab did not enter editor');
  }
  // Real backend writes in memory; rebuilt fixture does not demonstrate persistence across requests.
  const submit = async (form) => {
    const start = report.responses.length;
    await evaluate(`document.querySelector('[data-form="${form}"]').requestSubmit()`);
    await waitFor(() => report.responses.slice(start).some(r => r.status === 200)); await waitFor(ready);
    if (await evaluate('!document.querySelector("[data-errores]").hidden')) throw new Error('Write failed');
  };
  await submit('estado');
  await evaluate('document.querySelector("#c-responsable").value = "1"; document.querySelector("#c-responsable").dispatchEvent(new Event("input", {bubbles:true}))');
  await submit('responsable');
  await evaluate('document.querySelector("#c-nota").value = "Seguimiento ficticio"; document.querySelector("#c-nota").dispatchEvent(new Event("input", {bubbles:true}))');
  await submit('seguimiento');
  await command('Network.setExtraHTTPHeaders', { headers: { 'X-Horus-Fixture-Revision': '7' } });
  await evaluate('document.querySelector("#c-contacto_nombre").value = "Borrador protegido"; document.querySelector("#c-contacto_nombre").dispatchEvent(new Event("input", {bubbles:true}))');
  await evaluate('document.querySelector("[data-form=informacion]").requestSubmit()');
  await waitFor(() => evaluate('!document.querySelector("[data-errores]").hidden'));
  if (!(await evaluate('document.querySelector("#c-contacto_nombre").value === "Borrador protegido" && document.querySelector("[data-form=informacion] fieldset").disabled'))) throw new Error('Conflict lost draft');
  await screenshot('conflict-1440');
  await evaluate('document.querySelector("[data-accion=comparar]").click()');
  await waitFor(() => evaluate('!document.querySelector("[data-comparacion]").hidden'));
  report.conflict = true;
  const adoption = evaluate('document.querySelector("[data-accion=adoptar]").click()');
  await waitFor(() => report.dialog);
  await command('Page.handleJavaScriptDialog', { accept: true }); report.dialog = false;
  await adoption;
  await waitFor(() => evaluate('!document.querySelector("[data-form=informacion] fieldset").disabled'));
  if (!(await evaluate('document.querySelector("#c-contacto_nombre").value === "Borrador protegido"'))) throw new Error('Adoption overwrote draft');
  await submit('informacion');
  // Reload with a reader role after explicitly discarding the synthetic draft.
  async function reload(role = 'admin') {
    await command('Network.setExtraHTTPHeaders', { headers: { 'X-Horus-Fixture-Role': role } });
    const next = command('Page.navigate', { url: base + '/admin/cotizaciones' });
    await pause(100);
    if (report.dialog) { await command('Page.handleJavaScriptDialog', { accept: true }); report.dialog = false; }
    await next;
    await waitFor(() => evaluate('document.readyState === "complete" && !!document.querySelector("[data-panel-cotizaciones]")'));
    await waitFor(ready);
  }
  await reload('lector');
  if (!(await evaluate('!document.querySelector("[data-form=informacion]") && !document.querySelector("[data-form=seguimiento]") && !document.querySelector("[data-accion=nuevo]")'))) throw new Error('Reader got write forms');
  report.reader = true;
  report.serverPermission = await evaluate(`(async () => (await fetch('/admin/cotizaciones/11111111-1111-4111-8111-111111111111/estado', {method:'PATCH', headers:{Accept:'application/json', 'Content-Type':'application/json', 'X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content, 'If-Match':'"cotizacion-11111111-1111-4111-8111-111111111111-v1"'}, body:JSON.stringify({estado:'en_revision'})})).status === 403)()`);
  if (!report.serverPermission) throw new Error('Server accepted reader write');
  await reload();
  await evaluate('document.querySelector("[data-accion=nuevo]").click()');
  for (const [id, value] of [['c-contacto_nombre', 'Contacto creado ficticio'], ['c-contacto_email', 'creado@example.test'], ['c-necesidad', 'Necesidad ficticia desde navegador']]) {
    await evaluate(`(() => { const el = document.getElementById(${JSON.stringify(id)}); el.value = ${JSON.stringify(value)}; el.dispatchEvent(new Event('input', {bubbles:true})); })()`);
  }
  const creationStart = report.responses.length;
  await evaluate('document.querySelector("[data-form=informacion]").requestSubmit()');
  await waitFor(() => report.responses.slice(creationStart).some(r => r.status === 201)); await waitFor(ready);
  if (await evaluate('!document.querySelector("[data-errores]").hidden')) throw new Error('Creation failed');
  report.creation = true;
  // Simulate an unreadable write result in browser, without sending that write to the fixture.
  await command('Network.emulateNetworkConditions', { offline: true, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
  await evaluate('document.querySelector("#c-nota").value = "Nota ficticia protegida"; document.querySelector("#c-nota").dispatchEvent(new Event("input", {bubbles:true})); document.querySelector("[data-form=seguimiento]").requestSubmit()');
  await waitFor(() => evaluate('!document.querySelector("[data-accion=conciliar]").hidden'));
  if (!(await evaluate('document.querySelector("#c-nota").value === "Nota ficticia protegida" && document.querySelector("[data-form=seguimiento] fieldset").disabled'))) throw new Error('Uncertain write lost draft');
  await screenshot('uncertain-1440'); report.uncertain = true;
  await command('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
  if (report.errors.length || report.externalBlocked.length) throw new Error('Unexpected JS error or external request');
  await writeFile(join(evidence, 'report.json'), JSON.stringify(report, null, 2));
  process.stdout.write(JSON.stringify({ evidence, widths: report.widths, conflict: report.conflict, responses: report.responses.length, errors: report.errors }) + '\n');
} catch (error) {
  report.failure = error.message;
  await debugScreenshot?.('failure').catch(() => {});
  await writeFile(join(evidence, 'report.json'), JSON.stringify(report, null, 2));
  throw error;
} finally {
  socket?.close();
  browser.kill(); server.kill();
  await pause(500);
  const root = await realpath(tmpdir());
  const target = await realpath(profile);
  if (!target.startsWith(root + sep) || !target.split(sep).at(-1).startsWith('horus-cotizaciones-panel-')) throw new Error('Unsafe profile cleanup path');
  await rm(target, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
}
