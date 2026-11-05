// Solo http://127.0.0.1:8772 con catalogo-web-browser-router.php opt-in y SQLite :memory:.
// Página pública real, cinco estados ficticios; SQLite :memory: por petición.
import { spawn } from 'node:child_process';
import { request } from 'node:http';
import { createServer } from 'node:net';
import { mkdir, mkdtemp, realpath, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, sep, resolve } from 'node:path';

const base = 'http://127.0.0.1:8772';
const debugPort = 19334;
const evidence = resolve('storage/app/private/testing/catalogo-web');
await mkdir(evidence, { recursive: true });
for (const port of [8772, debugPort]) {
  await new Promise((ok, fail) => {
    const probe = createServer();
    probe.once('error', fail);
    probe.listen(port, '127.0.0.1', () => probe.close(ok));
  });
}
const profile = await mkdtemp(join(tmpdir(), 'horus-catalogo-web-'));
const server = spawn('php', ['-S', '127.0.0.1:8772', '-t', 'public', 'tests/Support/catalogo-web-browser-router.php'], {
  env: { ...process.env, HORUS_CATALOGO_BROWSER_FIXTURE: '1', HORUS_CATALOGO_FIXTURE_TIME: new Date().toISOString(), APP_ENV: 'testing', DB_CONNECTION: 'sqlite', DB_DATABASE: ':memory:', DB_URL: '' },
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
  await waitFor(async () => (await localRequest(`${base}/educacion/cursos`)).headers['x-horus-fixture'] === 'sqlite-memory-catalogo');
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
    await evaluate('document.documentElement.dataset.smokePrevious = 1');
    await command('Page.navigate', { url: base + path });
    await waitFor(() => evaluate(`!document.documentElement.dataset.smokePrevious && location.pathname === ${JSON.stringify(path)} && document.readyState === 'complete' && !!document.querySelector(${JSON.stringify(selector)})`));
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
    let staticMarkup;
    let staticLayout;
    for (const state of ['off', 'on', 'empty', 'missing', 'off-missing']) {
      await command('Network.setExtraHTTPHeaders', { headers: { 'X-Horus-Fixture-Catalog': state } });
      await navigate('/educacion/cursos', '.legacy-showcase');
      await evaluate(`(async () => {
        for (const img of document.images) { img.scrollIntoView(); await new Promise(done => { if (img.complete) done(); else { img.onload = done; img.onerror = done; } }); }
        scrollTo(0, 0); await new Promise(done => requestAnimationFrame(() => requestAnimationFrame(done)));
      })()`);
      const current = await evaluate(`(() => {
        const sections = [...document.querySelector('.legacy-showcase').children].slice(0, 2);
        const cards = [...document.querySelectorAll('#catalogo-cursos article')];
        return {
          noPageOverflow: document.documentElement.scrollWidth <= innerWidth,
          staticMarkup: sections.map(s => s.outerHTML).join(''),
          staticLayout: sections.map(s => { const b = s.getBoundingClientRect(); return [b.x, b.y, b.width, b.height]; }),
          catalog: !!document.querySelector('#catalogo-cursos'), count: cards.length,
          empty: document.body.innerText.includes('No hay fichas de cursos publicadas vigentes.'),
          unavailable: document.body.innerText.includes('No podemos mostrar los cursos publicados'),
          images: [...document.images].every(img => img.complete && img.naturalWidth > 0),
          links: cards.every(card => card.querySelector('a').getAttribute('href').endsWith('/contactos')),
          contained: cards.every(card => card.scrollWidth <= card.clientWidth && card.getBoundingClientRect().right <= innerWidth)
        };
      })()`);
      if (state === 'off') { staticMarkup = current.staticMarkup; staticLayout = current.staticLayout; }
      if (current.staticMarkup !== staticMarkup || JSON.stringify(current.staticLayout) !== JSON.stringify(staticLayout)) throw new Error('Static sections changed at ' + width + '/' + state);
      if (!current.noPageOverflow || !current.images || !current.links || !current.contained) throw new Error('Layout failed at ' + width + '/' + state + ': ' + JSON.stringify(current));
      if ((state === 'on' && current.count !== 3) || (state !== 'on' && current.count !== 0) || (state.startsWith('off') && current.catalog) || (state === 'empty' && !current.empty) || (state === 'missing' && !current.unavailable)) throw new Error('Catalog state failed: ' + state);
      if (state === 'on') {
        const focus = await evaluate(`(() => { const a = document.querySelector('#catalogo-cursos a'); a.scrollIntoView({block:'center'}); a.focus(); const b = a.getBoundingClientRect(); const hit = document.elementFromPoint(b.x + b.width/2, b.y + b.height/2); return { focused: document.activeElement === a, clickable: hit === a || a.contains(hit), height: b.height }; })()`);
        if (!focus.focused || !focus.clickable || focus.height < 44) throw new Error('Contact action inaccessible: ' + JSON.stringify(focus));
        current.action = focus;
        await evaluate('scrollTo(0, 0)');
      }
      await screenshot(state + '-' + width);
      delete current.staticMarkup;
      report.widths.push({ width, state, ...current });
    }
  }
  if (report.errors.length || report.externalBlocked.length) throw new Error('Unexpected browser errors or external requests');
  await writeFile(join(evidence, 'report.json'), JSON.stringify(report, null, 2));
  process.stdout.write(JSON.stringify({ evidence, checks: report.widths.length, errors: report.errors, externalBlocked: report.externalBlocked }) + '\n');
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
  if (!target.startsWith(root + sep) || !target.split(sep).at(-1).startsWith('horus-catalogo-web-')) throw new Error('Unsafe profile cleanup path');
  await rm(target, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
}
