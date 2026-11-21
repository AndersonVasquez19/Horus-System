// Destino único: http://127.0.0.1:8765 con chat-estado-browser-router.php opt-in.
// SQLite :memory:, sesión/caché/correo array, cola sync y transporte Python simulado.
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtemp, realpath, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, sep } from 'node:path';

const base = 'http://127.0.0.1:8765';
const port = 19329;
const profile = await mkdtemp(join(tmpdir(), 'horus-chat-estado-'));
const browser = spawn(process.env.EDGE_PATH || 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', [
  '--headless=new', '--disable-gpu', '--disable-background-networking', '--no-first-run',
  `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`,
  '--host-resolver-rules=MAP * 0.0.0.0, EXCLUDE 127.0.0.1', 'about:blank',
], { stdio: 'ignore' });
const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
async function waitFor(check) {
  const end = Date.now() + 15000;
  while (Date.now() < end) {
    try { if (await check()) return; } catch { /* Arranque del navegador. */ }
    await pause(100);
  }
  throw new Error('Timeout del smoke de estado.');
}
let socket;
let evaluate;
const errors = [];
try {
  await waitFor(async () => (await fetch(`http://127.0.0.1:${port}/json/version`)).ok);
  const page = await (await fetch(`http://127.0.0.1:${port}/json/new?about:blank`, { method: 'PUT' })).json();
  socket = new WebSocket(page.webSocketDebuggerUrl);
  await new Promise((resolve) => socket.addEventListener('open', resolve, { once: true }));
  const pending = new Map();
  let id = 0;
  socket.addEventListener('message', ({ data }) => {
    const result = JSON.parse(data);
    if (result.method === 'Runtime.exceptionThrown') {
      errors.push(result.params.exceptionDetails.exception?.description?.split('\n')[0] || 'Runtime error');
    }
    if (!pending.has(result.id)) return;
    const call = pending.get(result.id);
    pending.delete(result.id);
    result.error ? call.reject(new Error(result.error.message)) : call.resolve(result.result);
  });
  const command = (method, params = {}) => new Promise((resolve, reject) => {
    const next = ++id;
    pending.set(next, { resolve, reject });
    socket.send(JSON.stringify({ id: next, method, params }));
  });
  evaluate = async (expression) => {
    const result = await command('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
    if (result.exceptionDetails) throw new Error(result.exceptionDetails.text);
    return result.result?.value;
  };
  await command('Runtime.enable');
  socket.addEventListener('message', ({ data }) => {
    const event = JSON.parse(data);
    if (event.method !== 'Fetch.requestPaused') return;
    const requestId = event.params.requestId;
    const allowed = new URL(event.params.request.url).origin === base;
    void command(allowed ? 'Fetch.continueRequest' : 'Fetch.failRequest',
      allowed ? { requestId } : { requestId, errorReason: 'BlockedByClient' });
  });
  await command('Fetch.enable', { patterns: [{ urlPattern: '*' }] });
  await command('Page.navigate', { url: base });
  await waitFor(() => evaluate(`!!document.querySelector('[x-data="chatClient"]')?._x_dataStack && !!window.Alpine`));
  await evaluate(`fetch('/_fixture/estado?valor=cargando').then(r => r.json())`);
  await evaluate(`window.__posts = 0; const original = window.fetch; window.fetch = (url, options) => {
    if (String(url).endsWith('/chat/mensaje')) window.__posts++;
    return original(url, options).then(response => {
      if (String(url).endsWith('/chat/mensaje')) window.__postStatus = response.status;
      return response;
    });
  }; document.querySelector('.hc-launcher').click()`);
  await waitFor(() => evaluate(`document.querySelector('.hc-service-status').dataset.estado === 'cargando'`));
  await evaluate(`const input = document.querySelector('#mensaje-chat'); input.value = 'quiero cámaras';
    input.dispatchEvent(new Event('input', { bubbles: true }));`);
  assert.equal(await evaluate(`document.querySelector('[aria-label="Enviar consulta"]').disabled`), true);
  await evaluate(`document.querySelector('#mensaje-chat').closest('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))`);
  assert.equal(await evaluate('window.__posts'), 0);
  await evaluate(`fetch('/_fixture/estado?valor=listo').then(r => r.json())`);
  await waitFor(() => evaluate(`document.querySelector('.hc-service-status').dataset.estado === 'listo'`));
  assert.equal(await evaluate(`document.querySelector('#mensaje-chat').value`), 'quiero cámaras');
  assert.equal(await evaluate('window.__posts'), 0);
  await evaluate(`document.querySelector('#mensaje-chat').dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }))`);
  await waitFor(() => evaluate(`document.querySelector('.hc-log').textContent.includes('capacitación publicada') && !document.querySelector('#mensaje-chat').disabled`));
  assert.equal(await evaluate('window.__posts'), 1);
  for (const [width, height] of [[1366, 900], [390, 844]]) {
    await command('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: width === 390 });
    await evaluate('document.fonts.ready');
    const layout = await evaluate(`({ width: document.querySelector('.hc-dialog').getBoundingClientRect().width,
      viewport: window.innerWidth, bottom: document.querySelector('.hc-compose').getBoundingClientRect().bottom,
      height: window.innerHeight, role: document.querySelector('.hc-service-status [role="status"]').getAttribute('aria-live') })`);
    assert.ok(layout.width <= layout.viewport && layout.bottom <= layout.height, JSON.stringify(layout));
    assert.equal(layout.role, 'polite');
    if (process.env.CHAT_SMOKE_ARTIFACT_DIR) {
      const screenshot = await command('Page.captureScreenshot', { format: 'png' });
      await writeFile(join(process.env.CHAT_SMOKE_ARTIFACT_DIR, `estado-${width}.png`), Buffer.from(screenshot.data, 'base64'));
    }
  }
  await evaluate(`fetch('/_fixture/estado?valor=no_disponible').then(r => r.json())`);
  await evaluate(`document.querySelector('[x-data="chatClient"]')._x_dataStack[0].reintentarEstado()`);
  await waitFor(() => evaluate(`document.querySelector('.hc-service-status').dataset.estado === 'no_disponible'`));
  await evaluate(`fetch('/_fixture/estado?valor=listo').then(r => r.json())`);
  await evaluate(`document.querySelector('.hc-service-status button').click()`);
  await waitFor(() => evaluate(`document.querySelector('.hc-service-status').dataset.estado === 'listo'`));
  await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
  await waitFor(() => evaluate(`document.querySelector('.hc-launcher').getAttribute('aria-expanded') === 'false'
    && document.activeElement === document.querySelector('.hc-launcher')`));
  assert.equal(await evaluate(`document.activeElement === document.querySelector('.hc-launcher')`), true);
  process.stdout.write('Smoke aprobado: cargando → listo, borrador, Enter, aclaración validada, caída/reintento, escritorio/móvil y Escape.\n');
} catch (error) {
  const state = evaluate ? await evaluate(`({ ready: document.readyState, alpine: !!window.Alpine,
    estado: document.querySelector('.hc-service-status')?.dataset.estado,
    posts: window.__posts, postStatus: window.__postStatus,
    messages: [...document.querySelectorAll('.hc-message>p')].map(el => el.textContent) })`) : {};
  throw new Error(error.message + ': ' + JSON.stringify({ state, errors }), { cause: error });
} finally {
  socket?.close();
  browser.kill();
  const root = await realpath(tmpdir());
  const target = await realpath(profile);
  if (!target.startsWith(root + sep) || !target.split(sep).at(-1).startsWith('horus-chat-estado-')) {
    throw new Error('Directorio de navegador fuera del temporal permitido.');
  }
  await rm(target, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
}
