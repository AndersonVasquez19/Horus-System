// Solo http://127.0.0.1:8771 con editor-panel-browser-router.php opt-in y SQLite :memory:.
// Usa el panel real y formularios nativos; cada petición reconstruye datos ficticios.
import { spawn } from 'node:child_process';
import { request } from 'node:http';
import { createServer } from 'node:net';
import { mkdir, mkdtemp, realpath, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, sep, resolve } from 'node:path';

const base = 'http://127.0.0.1:8771';
const debugPort = 19333;
const evidence = resolve('storage/app/private/testing/editor-panel');
await mkdir(evidence, { recursive: true });
for (const port of [8771, debugPort]) {
  await new Promise((ok, fail) => {
    const probe = createServer();
    probe.once('error', fail);
    probe.listen(port, '127.0.0.1', () => probe.close(ok));
  });
}
const profile = await mkdtemp(join(tmpdir(), 'horus-editor-panel-'));
const server = spawn('php', ['-S', '127.0.0.1:8771', '-t', 'public', 'tests/Support/editor-panel-browser-router.php'], {
  env: { ...process.env, HORUS_EDITOR_BROWSER_FIXTURE: '1', HORUS_EDITOR_FIXTURE_TIME: new Date().toISOString(), APP_ENV: 'testing', DB_CONNECTION: 'sqlite', DB_DATABASE: ':memory:', DB_URL: '' },
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
  await waitFor(async () => (await localRequest(`${base}/admin/cursos`)).headers['x-horus-fixture'] === 'sqlite-memory-editor');
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
  for (const width of [390, 768, 1440]) {
    await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width === 390 });
    await navigate('/admin/cursos', 'a[title="Publicación del curso"]');
    const list = await evaluate(`(() => {
      const link = document.querySelector('a[title="Publicación del curso"]');
      link.scrollIntoView({ block: 'center', inline: 'center' }); link.focus();
      const scroller = link.closest('table').parentElement;
      const box = link.getBoundingClientRect();
      const hit = document.elementFromPoint(box.x + box.width / 2, box.y + box.height / 2);
      return { noPageOverflow: document.documentElement.scrollWidth <= innerWidth, href: link.getAttribute('href'), focused: document.activeElement === link, clickable: hit === link || link.contains(hit), actionWidth: box.width, internalTableScroll: scroller.scrollWidth > scroller.clientWidth, icon: getComputedStyle(link.querySelector('i'), '::before').content };
    })()`);
    if (!list.noPageOverflow || !list.focused || !list.clickable || !list.href.endsWith('/admin/cursos/1/publicacion') || !list.icon || list.icon === 'none') throw new Error(`List layout failed at ${width}: ${JSON.stringify(list)}`);
    await screenshot(`list-${width}`);
    await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
    await command('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
    await waitFor(() => evaluate('location.pathname.endsWith("/1/publicacion") && !!document.querySelector("#titulo") && document.readyState === "complete"'));
    await evaluate('document.fonts.ready');
    const editor = await evaluate(`(() => {
      const controls = [...document.querySelectorAll('textarea, select, input[type=date]')];
      const revisions = [...document.querySelectorAll('[name=_revision]')].map(el => el.value);
      return { noPageOverflow: document.documentElement.scrollWidth <= innerWidth, labeled: controls.every(el => !!document.querySelector('label[for="' + el.id + '"]')), revisionsMatch: revisions.length === 2 && revisions[0] === revisions[1] && /^"[a-f0-9]{64}"$/.test(revisions[0]), consent: document.querySelector('[name=confirmacion_publica]').required && !document.querySelector('[name=confirmacion_publica]').checked, fieldWidth: document.querySelector('#titulo').getBoundingClientRect().width };
    })()`);
    if (!editor.noPageOverflow || !editor.labeled || !editor.revisionsMatch || !editor.consent) throw new Error(`Editor layout failed at ${width}: ${JSON.stringify(editor)}`);
    await screenshot(`editor-${width}`);
    report.widths.push({ width, list, editor });
  }
  await evaluate(`document.querySelector('#titulo').value = 'Guardado desde navegador'; document.querySelector('[name=confirmacion_publica]').click(); document.querySelector('form[action$="/publicacion"]').requestSubmit();`);
  await waitFor(() => report.responses.some(r => r.status === 302 && r.title === 'Guardado desde navegador' && r.published === '1'));
  await waitFor(() => evaluate('document.readyState === "complete" && !!document.querySelector("#titulo") && !document.querySelector("[name=confirmacion_publica]").checked'));
  await evaluate(`document.querySelector('[name=_method][value=DELETE]').form.requestSubmit()`);
  await waitFor(() => report.responses.some(r => r.status === 302 && r.published === '0'));
  await navigate('/admin/cursos/1/publicacion', '#titulo');
  const oldRevision = await evaluate('document.querySelector("[name=_revision]").value');
  await command('Network.setExtraHTTPHeaders', { headers: { 'X-Horus-Fixture-Revision': 'changed' } });
  await evaluate(`document.querySelector('#titulo').value = 'Mi borrador sin perder'; document.querySelector('[name=confirmacion_publica]').click(); document.querySelector('form[action$="/publicacion"]').requestSubmit();`);
  await waitFor(() => evaluate('document.body.innerText.includes("La ficha cambió") && document.querySelector("#titulo")?.value === "Mi borrador sin perder"'));
  const conflict = await evaluate(`({ keptRevision: document.querySelector('[name=_revision]').value === ${JSON.stringify(oldRevision)}, consentReset: !document.querySelector('[name=confirmacion_publica]').checked, reload: [...document.querySelectorAll('a')].some(el => el.textContent.includes('Cargar versión actual')) })`);
  if (!conflict.keptRevision || !conflict.consentReset || !conflict.reload) throw new Error(`Conflict draft safety failed: ${JSON.stringify(conflict)}`);
  await command('Emulation.setDeviceMetricsOverride', { width: 390, height: 900, deviceScaleFactor: 1, mobile: true });
  await screenshot('conflict-390');
  await evaluate(`Array.from(document.querySelectorAll('a')).find(el => el.textContent.includes('Cargar versión actual')).click()`);
  await waitFor(() => evaluate('document.querySelector("#titulo")?.value === "Edición de otro administrador" && !document.body.innerText.includes("Tu texto se conservó")'));
  report.conflict = conflict;
  await command('Network.setExtraHTTPHeaders', { headers: { 'X-Horus-Fixture-Role': 'coordinador' } });
  await navigate('/admin/cursos', 'table');
  report.permission = await evaluate('!document.querySelector(\'a[title="Publicación del curso"]\')');
  if (!report.permission) throw new Error('Unauthorized link is visible');
  await command('Page.navigate', { url: base + '/admin/cursos/1/publicacion' });
  await waitFor(() => report.responses.some(r => r.status === 403));
  if (report.errors.length) throw new Error(`Browser exceptions: ${report.errors.join(', ')}`);
  await writeFile(join(evidence, 'report.json'), JSON.stringify(report, null, 2));
  process.stdout.write(JSON.stringify({ evidence, ...report }) + '\n');
} catch (error) {
  report.failure = error.message;
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
  if (!target.startsWith(root + sep) || !target.split(sep).at(-1).startsWith('horus-editor-panel-')) throw new Error('Unsafe profile cleanup path');
  await rm(target, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
}
