// Destino exclusivo: Laravel local real en 127.0.0.1:18080; no instala ni modifica datos editoriales.
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtemp } from 'node:fs/promises';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const listener = createServer();
await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
const port = listener.address().port;
await new Promise(resolve => listener.close(resolve));
const profile = await mkdtemp(join(tmpdir(), 'horus-local-edge-'));
const browser = spawn('C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', [
  '--headless=new', '--disable-gpu', '--no-first-run', '--disable-background-networking',
  `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, 'about:blank',
], { stdio: 'ignore', windowsHide: true });
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
let socket;
let command;
try {
  for (let i = 0; i < 100; i++) {
    try { if ((await (await fetch(`http://127.0.0.1:${port}/json`)).json()).length) break; } catch {}
    await pause(100);
  }
  const target = await (await fetch(`http://127.0.0.1:${port}/json/new?about:blank`, { method: 'PUT' })).json();
  socket = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise(resolve => socket.addEventListener('open', resolve, { once: true }));
  let id = 0;
  let observed;
  const chatResponses = new Map();
  const pending = new Map();
  command = (method, params = {}) => new Promise((resolve, reject) => {
    const next = ++id;
    pending.set(next, { resolve, reject });
    socket.send(JSON.stringify({ id: next, method, params }));
  });
  socket.addEventListener('message', ({ data }) => {
    const value = JSON.parse(data);
    if (pending.has(value.id)) {
      const waiter = pending.get(value.id);
      pending.delete(value.id);
      if (value.error) waiter.reject(new Error(value.error.message)); else waiter.resolve(value.result);
    }
    if (value.method === 'Fetch.requestPaused') {
      const request = value.params;
      const url = new URL(request.request.url);
      const local = ['127.0.0.1', 'localhost', '[::1]'].includes(url.hostname);
      command(local ? 'Fetch.continueRequest' : 'Fetch.failRequest', local
        ? { requestId: request.requestId }
        : { requestId: request.requestId, errorReason: 'BlockedByClient' }).catch(() => {});
    }
    if (value.method === 'Network.responseReceived' && new URL(value.params.response.url).pathname === '/chat/mensaje') {
      chatResponses.set(value.params.requestId, value.params.response.status);
    }
    if (value.method === 'Network.loadingFinished' && chatResponses.has(value.params.requestId)) {
      const status = chatResponses.get(value.params.requestId);
      command('Network.getResponseBody', { requestId: value.params.requestId }).then(result => {
        const raw = result.base64Encoded ? Buffer.from(result.body, 'base64').toString('utf8') : result.body;
        const body = JSON.parse(raw);
        observed = { status, source: body.fuente ?? null };
      }).catch(() => { observed = { status, source: null }; });
    }
  });
  const evaluate = async expression => {
    const value = await command('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
    assert.ok(!value.exceptionDetails, 'Error de ejecución del navegador');
    return value.result?.value;
  };
  await command('Fetch.enable', { patterns: [{ urlPattern: '*' }] });
  await command('Network.enable');
  const navigation = await command('Page.navigate', { url: 'http://127.0.0.1:18080/' });
  assert.ok(!navigation.errorText, 'Navegación local fallida');
  for (let i = 0; i < 150; i++) {
    if (await evaluate('!!window.Alpine && !!document.querySelector("#mensaje-chat")')) break;
    await pause(100);
  }
  assert.ok(await evaluate('!!window.Alpine'), 'Alpine no disponible');
  await evaluate(`(async () => {
    document.querySelector('[aria-label="Abrir chat con el asistente Horus"]').click();
    await new Promise(r => setTimeout(r, 200));
    const input = document.querySelector('#mensaje-chat');
    input.value = 'Hola'; input.dispatchEvent(new Event('input', { bubbles: true }));
    await new Promise(r => setTimeout(r, 100));
    input.closest('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  })()`);
  let reply;
  for (let i = 0; i < 100; i++) {
    reply = observed;
    if (reply) break;
    await pause(100);
  }
  if (!reply) {
    console.log('Diagnóstico del smoke:', JSON.stringify(await evaluate(`(() => {
      const input = document.querySelector('#mensaje-chat');
      const state = Alpine.$data(input.closest('[x-data]'));
      return { endpoint: state.endpoint, sending: state.sending, draftLength: input.value.length,
        valid: input.closest('form').checkValidity(), open: document.querySelector('[aria-controls="horus-chat-panel"]').getAttribute('aria-expanded') };
    })()`)));
  }
  assert.deepEqual(reply, { status: 200, source: 'advisor' });
  assert.equal(await evaluate(`document.querySelector('[aria-label="Abrir chat con el asistente Horus"]').getAttribute('aria-expanded')`), 'true');
  await command('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
  assert.ok(await evaluate('document.querySelector("#horus-chat-panel").getBoundingClientRect().width <= innerWidth'));
  await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
  await pause(300);
  assert.equal(await evaluate(`document.querySelector('[aria-controls="horus-chat-panel"]').getAttribute('aria-expanded')`), 'false');
  console.log('PASS: chat local real, respuesta advisor, apertura, móvil y Escape; red externa bloqueada.');
} finally {
  if (command && socket?.readyState === WebSocket.OPEN) await Promise.race([command('Browser.close').catch(() => {}), pause(2000)]);
  socket?.close();
  browser.kill();
}
