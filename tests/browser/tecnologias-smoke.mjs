// Destino exclusivo: http://127.0.0.1:8765 con chat-estado-browser-router.php opt-in.
// SQLite :memory:, sesión/caché/correo array, cola sync y Python simulado; sin proveedores reales.
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtemp, realpath, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, sep } from 'node:path';

const base = 'http://127.0.0.1:8765';
const port = 19331;
const profile = await mkdtemp(join(tmpdir(), 'horus-tecnologias-'));
const browser = spawn(process.env.EDGE_PATH || 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', [
  '--headless=new', '--disable-gpu', '--disable-background-networking', '--no-first-run',
  `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`,
  '--host-resolver-rules=MAP * 0.0.0.0, EXCLUDE 127.0.0.1', 'about:blank',
], { stdio: 'ignore' });
const pause = (ms) => new Promise(resolve => setTimeout(resolve, ms));
async function waitFor(check) {
  const end = Date.now() + 15000;
  while (Date.now() < end) {
    try { if (await check()) return; } catch { /* Inicio del navegador. */ }
    await pause(100);
  }
  throw new Error('Timeout del smoke de Tecnologías.');
}
let socket;
try {
  await waitFor(async () => (await fetch(`http://127.0.0.1:${port}/json/version`)).ok);
  const page = await (await fetch(`http://127.0.0.1:${port}/json/new?about:blank`, { method: 'PUT' })).json();
  socket = new WebSocket(page.webSocketDebuggerUrl);
  await new Promise(resolve => socket.addEventListener('open', resolve, { once: true }));
  let id = 0;
  const pending = new Map();
  socket.addEventListener('message', ({ data }) => {
    const result = JSON.parse(data);
    if (!pending.has(result.id)) return;
    const { resolve, reject } = pending.get(result.id);
    pending.delete(result.id);
    result.error ? reject(new Error(result.error.message)) : resolve(result.result);
  });
  const command = (method, params = {}) => new Promise((resolve, reject) => {
    pending.set(++id, { resolve, reject });
    socket.send(JSON.stringify({ id, method, params }));
  });
  const evaluate = async expression => {
    const result = await command('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (result.exceptionDetails) throw new Error(result.exceptionDetails.text);
    return result.result.value;
  };
  await command('Page.enable');
  await command('Runtime.enable');
  for (const width of [1440, 390]) {
    await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 500 });
    await command('Page.navigate', { url: base });
    await waitFor(() => evaluate(`document.readyState === 'complete' && !!document.querySelector('[x-data="chatClient"]')?._x_dataStack`));
    const state = await evaluate(`({
      links: document.querySelectorAll('nav a[href*="/tecnologias/"]').length,
      slides: document.querySelectorAll('.hero-backdrop').length,
      suggestions: document.querySelector('.hc-suggestions').textContent,
      scope: document.querySelector('[x-data="chatClient"]').dataset.tecnologias,
      overflow: document.documentElement.scrollWidth > innerWidth
    })`);
    assert.equal(state.links, 0);
    assert.equal(state.slides, 3);
    assert.equal(state.scope, 'oculto');
    assert.doesNotMatch(state.suggestions, /tecnol|cotizaci/i);
    assert.equal(state.overflow, false);
    await evaluate(`document.querySelector('.hc-launcher').click()`);
    await waitFor(() => evaluate(`document.querySelector('.hc-service-status').dataset.estado === 'listo'`));
    await evaluate(`(async () => {
      const input = document.querySelector('#mensaje-chat');
      input.value = 'que cámaras tienen';
      input.dispatchEvent(new Event('input', { bubbles: true }));
      await new Promise(resolve => setTimeout(resolve, 50));
      input.closest('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    })()`);
    await waitFor(() => evaluate(`document.querySelector('.hc-log').textContent.includes('Puedo orientarte sobre cursos, capacitaciones, asesoramiento y matrículas.')`));
    assert.equal(await evaluate(`document.querySelectorAll('.hc-card').length`), 0);
    if (process.env.TECNOLOGIAS_SCREENSHOT_DIR) {
      const screenshot = await command('Page.captureScreenshot', { format: 'png' });
      await writeFile(join(process.env.TECNOLOGIAS_SCREENSHOT_DIR, `tecnologias-${width}.png`), Buffer.from(screenshot.data, 'base64'));
    }
  }
  process.stdout.write('Edge: Tecnologías oculta en navegación, carrusel, sugerencias y respuesta real del widget; escritorio y móvil sin desbordamiento.\n');
} finally {
  socket?.close();
  browser.kill();
  const root = await realpath(tmpdir());
  const target = await realpath(profile);
  if (!target.startsWith(root + sep) || !target.split(sep).at(-1).startsWith('horus-tecnologias-')) {
    throw new Error('Perfil temporal fuera del destino permitido.');
  }
  await rm(target, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
}
