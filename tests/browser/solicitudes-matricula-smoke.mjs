// Solo http://127.0.0.1:8780 con solicitudes-matricula-browser-router.php opt-in y SQLite :memory:.
// Usa el panel real y formularios nativos; cada petición reconstruye datos ficticios.
import { spawn } from 'node:child_process';
import { request } from 'node:http';
import { createServer } from 'node:net';
import { mkdir, mkdtemp, realpath, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, sep, resolve } from 'node:path';

const base = 'http://127.0.0.1:8780';
const debugPort = 19340;
const evidence = resolve('storage/app/private/testing/solicitudes-matricula');
await mkdir(evidence, { recursive: true });
for (const port of [8780, debugPort]) {
  await new Promise((ok, fail) => {
    const probe = createServer();
    probe.once('error', fail);
    probe.listen(port, '127.0.0.1', () => probe.close(ok));
  });
}
const profile = await mkdtemp(join(tmpdir(), 'horus-solicitudes-matricula-'));
const server = spawn('php', ['-S', '127.0.0.1:8780', '-t', 'public', 'tests/Support/solicitudes-matricula-browser-router.php'], {
  env: { ...process.env, HORUS_SOLICITUDES_BROWSER_FIXTURE: '1', HORUS_SOLICITUDES_FIXTURE_TIME: new Date().toISOString(), APP_ENV: 'testing', DB_CONNECTION: 'sqlite', DB_DATABASE: ':memory:', DB_URL: '' },
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
  await waitFor(async () => (await localRequest(`${base}/solicitar-matricula`)).headers['x-horus-fixture'] === 'sqlite-memory-solicitudes');
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
      report.responses.push({ status: response.status, count: headers['x-horus-fixture-count'], state: headers['x-horus-fixture-state'], enrollments: headers['x-horus-fixture-enrollments'] });
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
  for (const width of [390, 768, 1440]) {
    await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width === 390 });
    await navigate('/educacion/cursos', 'a[href*="/solicitar-matricula?"]');
    await click('a[href*="/solicitar-matricula?"]');
    await waitFor(() => evaluate('!!document.querySelector("#solicitud-curso") && location.pathname === "/solicitar-matricula"'));
    const form = await evaluate(`(() => ({ fits: document.documentElement.scrollWidth <= innerWidth,
      selected: !!document.querySelector('#solicitud-curso').value,
      labels: [...document.querySelectorAll('form input:not([type=hidden]):not([type=checkbox]), form textarea, form select')].every(el => !!document.querySelector('label[for="' + el.id + '"]')),
      consent: document.querySelector('[name=consentimiento]').required && !document.querySelector('[name=consentimiento]').checked,
      noPrivateCourse: !document.body.innerText.includes('Curso interno ficticio') }))()`);
    check(Object.values(form).every(Boolean), 'Public form failed: ' + JSON.stringify(form));
    await screenshot('publico-' + width);
    await navigate('/admin/solicitudes-matricula', '[data-admin-modal-open]');
    await click('[data-admin-modal-open]');
    await waitFor(() => evaluate('!!document.querySelector("dialog[open]")'));
    const modal = await evaluate(`(() => { const d = document.querySelector('dialog[open]'); return { fits: d.scrollWidth <= d.clientWidth + 1,
      focus: d.contains(document.activeElement), privateContact: d.textContent.includes('persona@example.test'), nativePatch: !!d.querySelector('[name=_method][value=PATCH]') }; })()`);
    check(Object.values(modal).every(Boolean), 'Review modal failed: ' + JSON.stringify(modal));
    await screenshot('revision-' + width);
    report.widths.push({ width, form, modal });
  }
  await navigate('/solicitar-matricula', '#solicitud-curso');
  await evaluate(`const f = document.querySelector('form[action$="/solicitar-matricula"]'); f.noValidate = true;
    f.querySelector('[name=curso]').selectedIndex = 1; f.querySelector('[name=nombres]').value = 'Persona';
    f.querySelector('[name=apellidos]').value = 'Ficticia'; f.querySelector('[name=email]').value = 'correo-invalido';
    f.querySelector('[name=telefono]').value = '+51 999 111 222'; f.querySelector('[name=consentimiento]').checked = true;`);
  await pause(2200);
  await evaluate(`document.querySelector('form[action$="/solicitar-matricula"]').requestSubmit()`);
  await waitFor(() => evaluate('document.querySelector("[name=email]")?.value === "correo-invalido" && !!document.querySelector("[role=alert]")'));
  check(await evaluate('document.querySelector("[name=nombres]").value === "Persona" && !document.querySelector("[name=consentimiento]").checked'), 'Draft or consent recovery failed');
  await evaluate(`document.querySelector('[name=email]').value = 'persona@example.test'; document.querySelector('[name=consentimiento]').checked = true;`);
  await pause(2200);
  await evaluate(`document.querySelector('form[action$="/solicitar-matricula"]').requestSubmit()`);
  await waitFor(() => evaluate('document.body.innerText.includes("Recibimos tu solicitud")'));
  check(report.responses.some(r => r.status === 302 && r.count === '1' && r.state === 'pendiente' && r.enrollments === '0'), 'Public POST did not register only a pending request');
  await navigate('/admin/solicitudes-matricula', '[data-admin-modal-open]');
  await click('[data-admin-modal-open]');
  await evaluate(`document.querySelector('dialog [name=estado]').value = 'en_revision'; document.querySelector('dialog form').requestSubmit()`);
  await waitFor(() => report.responses.some(r => r.status === 302 && r.state === 'en_revision'));
  await command('Network.setExtraHTTPHeaders', { headers: { 'X-Horus-Fixture-State': 'en_revision' } });
  await navigate('/admin/solicitudes-matricula', '[data-admin-modal-open]');
  await click('[data-admin-modal-open]');
  await evaluate(`document.querySelector('dialog [name=estado]').value = 'atendida'; document.querySelector('dialog form').requestSubmit()`);
  await waitFor(() => report.responses.some(r => r.status === 302 && r.state === 'atendida' && r.enrollments === '0'));
  report.publicReception = true; report.adminReview = true;
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
  if (!target.startsWith(root + sep) || !target.split(sep).at(-1).startsWith('horus-solicitudes-matricula-')) throw new Error('Unsafe profile cleanup path');
  await rm(target, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
}
