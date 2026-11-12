import { spawn } from 'node:child_process';
import { request } from 'node:http';
import { mkdir, mkdtemp, realpath, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, sep } from 'node:path';

// Solo GET/HEAD sobre el servidor solicitado. Nunca envía consultas ni formularios reales.
const base = 'http://127.0.0.1:8000';
const before = process.argv.includes('--before');
const evidence = 'storage/app/testing/public-restoration';
await mkdir(evidence, { recursive: true });
const profile = await mkdtemp(join(tmpdir(), 'horus-restoration-'));
const port = 19331;
const browser = spawn(process.env.EDGE_PATH || 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', [
  '--headless=new', '--disable-gpu', '--disable-background-networking', '--no-first-run',
  `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, 'about:blank',
], { stdio: 'ignore', windowsHide: true });
const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const jsonRequest = (path, method = 'GET') => new Promise((resolve, reject) => {
  const req = request(`http://127.0.0.1:${port}${path}`, { method }, (res) => {
    let body = '';
    res.setEncoding('utf8'); res.on('data', (part) => { body += part; });
    res.on('end', () => { try { resolve(JSON.parse(body)); } catch (error) { reject(error); } });
  });
  req.on('error', reject); req.end();
});
async function waitFor(fn, timeout = 15000) {
  const end = Date.now() + timeout;
  while (Date.now() < end) {
    try { const result = await fn(); if (result) return result; } catch { /* Startup/navigation. */ }
    await pause(100);
  }
  throw new Error('Restoration browser check timed out.');
}
let socket;
try {
  await waitFor(() => jsonRequest('/json/version'));
  const page = await jsonRequest('/json/new?about:blank', 'PUT');
  socket = new WebSocket(page.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, { once: true }); socket.addEventListener('error', reject, { once: true }); });
  let id = 0;
  const pending = new Map();
  const network = [], failures = [], errors = [], blocked = [];
  const requestUrls = new Map();
  const command = (method, params = {}) => new Promise((resolve, reject) => {
    const next = ++id; pending.set(next, { resolve, reject });
    socket.send(JSON.stringify({ id: next, method, params }));
  });
  socket.addEventListener('message', ({ data }) => {
    const msg = JSON.parse(data);
    if (msg.id && pending.has(msg.id)) {
      const promise = pending.get(msg.id); pending.delete(msg.id);
      msg.error ? promise.reject(new Error(msg.error.message)) : promise.resolve(msg.result);
    } else if (msg.method === 'Network.requestWillBeSent') {
      requestUrls.set(msg.params.requestId, msg.params.request.url);
    } else if (msg.method === 'Network.responseReceived') {
      const r = msg.params.response;
      if (new URL(r.url).origin === base) network.push({ url: r.url, status: r.status, mime: r.mimeType });
    } else if (msg.method === 'Network.loadingFailed') {
      failures.push({ url: requestUrls.get(msg.params.requestId), type: msg.params.type, error: msg.params.errorText });
    } else if (msg.method === 'Runtime.exceptionThrown') {
      errors.push(msg.params.exceptionDetails.exception?.description || msg.params.exceptionDetails.text);
    } else if (msg.method === 'Runtime.consoleAPICalled' && msg.params.type === 'error') {
      errors.push(msg.params.args.map((arg) => arg.description || arg.value || '').join(' '));
    } else if (msg.method === 'Fetch.requestPaused') {
      const r = msg.params.request;
      if (new URL(r.url).origin !== base || !['GET', 'HEAD'].includes(r.method)) {
        blocked.push({ url: r.url, method: r.method });
        command('Fetch.failRequest', { requestId: msg.params.requestId, errorReason: 'BlockedByClient' }).catch(() => {});
      } else command('Fetch.continueRequest', { requestId: msg.params.requestId }).catch(() => {});
    }
  });
  const evaluate = async (expression) => {
    const result = await command('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (result.exceptionDetails) throw new Error(result.exceptionDetails.text);
    return result.result.value;
  };
  await command('Page.enable'); await command('Runtime.enable'); await command('Network.enable');
  await command('Fetch.enable', { patterns: [{ urlPattern: '*' }] });
  const paths = before ? ['/'] : ['/', '/educacion/cursos', '/educacion/capacitaciones', '/educacion/asesoramiento', '/tecnologias/cableado-estructurado', '/tecnologias/camaras-seguridad', '/tecnologias/soporte-mantenimiento', '/quienes-somos', '/galeria', '/contactos', '/preguntas-frecuentes', '/politicas-devolucion'];
  const results = [];
  for (const path of paths) {
    for (const width of before ? [1440] : [390, 768, 1440]) {
      await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 768 });
      await command('Page.navigate', { url: base + path });
      await waitFor(() => evaluate('document.readyState === "complete"'));
      if (!before) await waitFor(() => evaluate('!!window.Alpine'));
      await evaluate('document.fonts.ready');
      const state = await evaluate(`(() => {
        const logo = document.querySelector('.site-brand-logo');
        const nav = document.querySelector('.nav-pill');
        const desktop = nav?.querySelector('ul');
        return { path: location.pathname, width: innerWidth, scrollWidth: document.documentElement.scrollWidth,
          logoWidth: logo?.getBoundingClientRect().width, logoHeight: logo?.getBoundingClientRect().height,
          navDisplay: nav ? getComputedStyle(nav).display : null,
          menuDisplay: desktop ? getComputedStyle(desktop).display : null,
          alpine: !!window.Alpine, stylesheets: [...document.styleSheets].map((s) => s.href),
          brokenImages: [...document.images].filter((img) => img.getAttribute('src') && img.complete && img.naturalWidth === 0 && !img.loading?.includes('lazy')).map((img) => img.getAttribute('src')),
          chat: !!document.querySelector('[aria-controls="horus-chat-panel"]'),
          fontFamily: getComputedStyle(document.body).fontFamily,
          cards: [...document.querySelectorAll('.carta-card, .convenio-card, .stat-card')].slice(0, 4).map((el) => ({ borderRadius: getComputedStyle(el).borderRadius, background: getComputedStyle(el).backgroundColor })),
          imageCount: document.images.length };
      })()`);
      if (!before) {
        if (state.path !== path || !state.alpine || state.logoWidth > 120 || state.logoHeight > 120 || state.navDisplay !== 'flex' || !state.chat || state.scrollWidth > state.width + 1 || state.brokenImages.length) {
          throw new Error('Visual contract failed: ' + JSON.stringify(state));
        }
        if (width === 1440 && state.menuDisplay !== 'flex') throw new Error('Desktop menu is not horizontal.');
        if (width === 1440) {
          const dropdown = await evaluate(`(async () => {
            const button = [...document.querySelectorAll('.nav-pill > ul button')].find((el) => el.textContent.includes('Educación'));
            button.click(); await new Promise((resolve) => setTimeout(resolve, 350));
            const visible = getComputedStyle(button.nextElementSibling).display !== 'none';
            button.click(); await new Promise((resolve) => setTimeout(resolve, 350)); return visible;
          })()`);
          if (!dropdown) throw new Error('Education dropdown failed.');
        } else {
          const menu = await evaluate(`(async () => {
            const button = document.querySelector('[aria-label="Abrir menú"]');
            button.click(); await new Promise((resolve) => setTimeout(resolve, 350));
            const visible = getComputedStyle(document.querySelector('.nav-pill > div')).display !== 'none';
            button.click(); await new Promise((resolve) => setTimeout(resolve, 350)); return visible;
          })()`);
          if (!menu) throw new Error('Mobile menu failed.');
        }
        await evaluate(`document.querySelector('[aria-controls="horus-chat-panel"]').click()`);
        await waitFor(() => evaluate('document.querySelector("[aria-controls=horus-chat-panel]").getAttribute("aria-expanded") === "true"'));
        await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
        await waitFor(() => evaluate('document.querySelector("[aria-controls=horus-chat-panel]").getAttribute("aria-expanded") === "false" && getComputedStyle(document.querySelector("#horus-chat-panel")).display === "none"'));
      }
      results.push(state);
      if (path === '/' || path === '/educacion/cursos') {
        const screenshot = await command('Page.captureScreenshot', { format: 'png' });
        await writeFile(join(evidence, `${before ? 'before' : 'after'}-${path === '/' ? 'home' : 'courses'}-${width}.png`), Buffer.from(screenshot.data, 'base64'));
      }
    }
  }
  const report = { mode: before ? 'before' : 'after', base, results, network, failures, errors, blocked };
  await writeFile(join(evidence, `${report.mode}.json`), JSON.stringify(report, null, 2));
  const localFailures = failures.filter((r) => r.url?.startsWith(base + '/') && r.error !== 'net::ERR_ABORTED');
  if (!before && (errors.length || localFailures.length || network.some((r) => r.status >= 400))) throw new Error('Console or resource errors: ' + JSON.stringify({ errors, localFailures, bad: network.filter((r) => r.status >= 400) }));
  process.stdout.write(JSON.stringify({ mode: report.mode, pages: paths.length, checks: results.length, sample: results[0], errors, failedResources: network.filter((r) => r.status >= 400), evidence }) + '\n');
} finally {
  socket?.close(); browser.kill();
  const root = await realpath(tmpdir()), target = await realpath(profile);
  if (!target.startsWith(root + sep) || !target.split(sep).at(-1).startsWith('horus-restoration-')) throw new Error('Unsafe profile cleanup.');
  await rm(target, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
}
