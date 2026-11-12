// Solo http://127.0.0.1:8765 con horus-ai-browser-router.php y FastAPI temporal en :8001.
import { spawn } from 'node:child_process';
import { mkdtemp } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import assert from 'node:assert/strict';

const profile = await mkdtemp(join(tmpdir(), 'horus-ai-edge-'));
const browser = spawn('C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', [
  '--headless=new', '--disable-gpu', '--no-first-run', '--disable-background-networking',
  '--remote-debugging-port=19322', `--user-data-dir=${profile}`, 'about:blank',
], { stdio: 'ignore', windowsHide: true });
let socket;
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
try {
  let targets;
  for (let i = 0; i < 100; i++) {
    try { targets = await (await fetch('http://127.0.0.1:19322/json')).json(); if (targets.length) break; } catch {}
    await pause(100);
  }
  const page = await (await fetch('http://127.0.0.1:19322/json/new?about:blank', { method: 'PUT' })).json();
  socket = new WebSocket(page.webSocketDebuggerUrl);
  await new Promise(resolve => socket.addEventListener('open', resolve, { once: true }));
  let next = 0;
  let captured;
  const chatRequests = new Map();
  const pending = new Map();
  socket.addEventListener('message', ({ data }) => {
    const result = JSON.parse(data);
    if (pending.has(result.id)) { pending.get(result.id)(result.result); pending.delete(result.id); }
    if (result.method === 'Network.responseReceived' && result.params.response.url.endsWith('/chat/mensaje')) chatRequests.set(result.params.requestId, result.params.response);
    if (result.method === 'Network.loadingFinished' && chatRequests.has(result.params.requestId)) {
      const response = chatRequests.get(result.params.requestId);
      command('Network.getResponseBody', { requestId: result.params.requestId }).then(body => {
        captured = { status: response.status, url: response.url, body: JSON.parse(body.body) };
      });
    }
  });
  const command = (method, params = {}) => new Promise(resolve => {
    const id = ++next; pending.set(id, resolve); socket.send(JSON.stringify({ id, method, params }));
  });
  const evaluate = async expression => {
    const response = await command('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
    if (response.exceptionDetails) throw new Error(response.exceptionDetails.exception?.description || response.exceptionDetails.text);
    return response.result?.value;
  };
  await command('Network.enable');
  await command('Page.addScriptToEvaluateOnNewDocument', { source: `
    const originalFetch = window.fetch.bind(window);
    window.fetch = async (...args) => {
      const response = await originalFetch(...args);
      if (String(args[0]).includes('/chat/mensaje')) window.__chatResult = { status: response.status, body: await response.clone().json(), url: String(args[0]) };
      return response;
    };
  ` });
  const navigation = await command('Page.navigate', { url: 'http://127.0.0.1:8765/' });
  assert.ok(!navigation.errorText, navigation.errorText);
  for (let i = 0; i < 100; i++) {
    if (await evaluate('!!window.Alpine && !!document.querySelector("#mensaje-chat")')) break;
    await pause(100);
  }
  const result = await evaluate(`(async () => {
    const button = document.querySelector('[aria-label="Abrir chat con el asistente Horus"]');
    button.click();
    await new Promise(r => setTimeout(r, 150));
    const input = document.querySelector('#mensaje-chat');
    input.value = 'Hola'; input.dispatchEvent(new Event('input', { bubbles: true }));
    await new Promise(r => setTimeout(r, 100));
    input.closest('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    for (let i=0;i<100;i++) { if(document.querySelector('.hc-log').textContent.includes('Horus AI Advisor')) break; await new Promise(r=>setTimeout(r,100)); }
    return { response: window.__chatResult, location: location.href, draft: input.value,
      state: { sending: Alpine.$data(input.closest('[x-data]')).sending, endpoint: Alpine.$data(input.closest('[x-data]')).endpoint },
      svg: !!document.querySelector('#horus-chat-panel svg'),
      labels: document.querySelector('#horus-chat-panel').textContent,
      open: button.getAttribute('aria-expanded') };
  })()`);
  for (let i=0;i<50 && !captured;i++) await pause(100);
  result.response = captured;
  assert.ok(result.response, 'Missing captured chat response');
  assert.equal(result.response.status, 200);
  assert.equal(result.response.body.fuente, 'advisor');
  assert.match(result.response.url, /\/chat\/mensaje$/);
  assert.equal(result.open, 'true');
  for (const label of ['capacitaciones', 'servicios tecnológicos', 'cotización', 'matricularme']) assert.ok(result.labels.includes(label));
  await command('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
  assert.ok(await evaluate('document.querySelector("#horus-chat-panel").getBoundingClientRect().width <= window.innerWidth'));
  await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
  await pause(300);
  assert.equal(await evaluate(`document.querySelector('[aria-controls="horus-chat-panel"]').getAttribute('aria-expanded')`), 'false');
  console.log('PASS Edge: Laravel -> FastAPI real, advisor reply, suggestions, mobile width, Escape.');
} finally {
  socket?.close();
  browser.kill();
}
