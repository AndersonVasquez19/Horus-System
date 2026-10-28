import { spawn } from 'node:child_process';
import { mkdtemp, realpath, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, sep } from 'node:path';
import { request } from 'node:http';

const base = 'http://127.0.0.1:8769';
const debugPort = 19329;
const profile = await mkdtemp(join(tmpdir(), 'horus-publication-smoke-'));
const server = spawn('php', ['-S', '127.0.0.1:8769', '-t', 'public', 'tests/Support/publication-browser-router.php'], {
  env: { ...process.env, HORUS_PUBLICATION_BROWSER_FIXTURE: '1', APP_ENV: 'testing', DB_CONNECTION: 'sqlite', DB_DATABASE: ':memory:', DB_URL: '' },
  stdio: 'ignore',
});
const browser = spawn(process.env.EDGE_PATH || 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', [
  '--headless=new', '--disable-gpu', '--disable-background-networking', '--no-first-run',
  `--remote-debugging-port=${debugPort}`, `--user-data-dir=${profile}`,
  '--host-resolver-rules=MAP * 0.0.0.0, EXCLUDE 127.0.0.1', 'about:blank',
], { stdio: 'ignore' });
const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const localRequest = (url, method = 'GET') => new Promise((resolve, reject) => {
  const req = request(url, { method }, (res) => {
    let body = '';
    res.setEncoding('utf8');
    res.on('data', (part) => { body += part; });
    res.on('end', () => resolve({ status: res.statusCode, body }));
  });
  req.on('error', reject);
  req.setTimeout(5000, () => req.destroy(new Error('Local fixture request timed out.')));
  req.end();
});
async function waitFor(fn, timeout = 15000) {
  const end = Date.now() + timeout;
  while (Date.now() < end) {
    try { const result = await fn(); if (result) return result; } catch { /* Startup/navigation. */ }
    await pause(150);
  }
  throw new Error('Publication smoke timed out.');
}
let socket;
try {
  await waitFor(async () => (await localRequest(`${base}/educacion/cursos`)).status === 200);
  await waitFor(async () => (await localRequest(`http://127.0.0.1:${debugPort}/json/version`)).status === 200);
  const page = JSON.parse((await localRequest(`http://127.0.0.1:${debugPort}/json/new?${encodeURIComponent(`${base}/educacion/cursos`)}`, 'PUT')).body);
  socket = new WebSocket(page.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, { once: true }); socket.addEventListener('error', reject, { once: true }); });
  const pending = new Map();
  let id = 0;
  socket.addEventListener('message', ({ data }) => {
    const result = JSON.parse(data);
    if (!pending.has(result.id)) return;
    const promise = pending.get(result.id);
    pending.delete(result.id);
    result.error ? promise.reject(new Error(result.error.message)) : promise.resolve(result.result);
  });
  const command = (method, params = {}) => new Promise((resolve, reject) => {
    const next = ++id;
    pending.set(next, { resolve, reject });
    socket.send(JSON.stringify({ id: next, method, params }));
  });
  const evaluate = async (expression) => {
    const result = await command('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (result.exceptionDetails) throw new Error(result.exceptionDetails.text);
    return result.result.value;
  };
  await command('Runtime.enable');
  await waitFor(() => evaluate('!!window.Alpine && document.body.innerText.includes("Cursos Disponibles.")'));
  await command('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
  const catalog = await evaluate(`(async () => {
    await document.fonts.ready;
    return { noOverflow: document.documentElement.scrollWidth <= innerWidth, editorialHidden: !document.body.innerText.includes('Python inicial') && !document.body.innerText.includes('Martes de 19 a 21 horas'), original: document.body.innerText.includes('Cursos Disponibles.') && document.body.innerText.includes('Horarios Adaptables.'), private: document.body.innerText.includes('9876.54') || document.body.innerText.includes('Nombre administrativo privado') };
  })()`);
  if (!catalog.noOverflow || !catalog.editorialHidden || !catalog.original || catalog.private) throw new Error(`Public page preservation failed: ${JSON.stringify(catalog)}`);
  await evaluate(`document.querySelector('[aria-label="Abrir chat con el asistente Horus"]').click()`);
  await evaluate(`(async () => {
    const input = document.querySelector('#mensaje-chat');
    input.value = 'Horario del curso Python inicial';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await new Promise((resolve) => setTimeout(resolve, 50));
    input.focus();
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
  })()`);
  await waitFor(() => evaluate(`document.querySelector('.hc-log').textContent.includes('no publica un calendario vigente') && !document.querySelector('#mensaje-chat').disabled`));
  const chat = await evaluate(`({ focus: document.activeElement.id, width: document.querySelector('#horus-chat-panel').getBoundingClientRect().width, viewport: innerWidth, target: document.querySelector('[aria-label="Enviar consulta"]').getBoundingClientRect().height })`);
  if (chat.focus !== 'mensaje-chat' || chat.width > chat.viewport || chat.target < 44) throw new Error(`Chat accessibility failed: ${JSON.stringify(chat)}`);
  await command('Page.navigate', { url: `${base}/admin/cursos/1/publicacion` });
  await waitFor(() => evaluate('!!document.querySelector("[name=confirmacion_publica]")'));
  const editor = await evaluate(`(async () => {
    await document.fonts.ready;
    const controls = [...document.querySelectorAll('textarea, select, input[type=date]')];
    return { noOverflow: document.documentElement.scrollWidth <= innerWidth, labeled: controls.every((el) => document.querySelector('label[for="' + el.id + '"]')), consent: document.querySelector('[name=confirmacion_publica]').required && !document.querySelector('[name=confirmacion_publica]').checked, title: document.querySelector('#titulo').value };
  })()`);
  if (!editor.noOverflow || !editor.labeled || !editor.consent || editor.title !== 'Python inicial') throw new Error(`Editorial form failed: ${JSON.stringify(editor)}`);
  process.stdout.write(`${JSON.stringify({ sqlite: ':memory:', catalog, chat, editor })}\n`);
} finally {
  socket?.close(); browser.kill(); server.kill();
  const root = await realpath(tmpdir());
  const target = await realpath(profile);
  if (!target.startsWith(root + sep) || !target.split(sep).at(-1).startsWith('horus-publication-smoke-')) throw new Error('Unsafe browser profile cleanup path.');
  await rm(target, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
}
