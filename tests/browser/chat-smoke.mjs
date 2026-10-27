import { spawn } from 'node:child_process';
import { mkdtemp, realpath, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, sep } from 'node:path';

const baseUrl = process.env.CHAT_SMOKE_URL || 'http://127.0.0.1:8765/';
const edgePath = process.env.EDGE_PATH || 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
const port = 19322;
const profile = await mkdtemp(join(tmpdir(), 'horus-chat-smoke-'));
const browser = spawn(edgePath, [
  '--headless=new', '--disable-gpu', '--disable-background-networking',
  '--no-first-run', `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`,
  '--host-resolver-rules=MAP * 0.0.0.0, EXCLUDE 127.0.0.1', 'about:blank',
], { stdio: 'ignore' });

const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
async function waitFor(callback, timeout = 15000) {
  const end = Date.now() + timeout;
  while (Date.now() < end) {
    try {
      const result = await callback();
      if (result) return result;
    } catch { /* Browser may still be starting. */ }
    await pause(150);
  }
  throw new Error('Timed out waiting for the browser or chat response.');
}

let socket;
let cleanupPromise;
function cleanup() {
  cleanupPromise ??= (async () => {
    socket?.close();
    browser.kill();
    const root = await realpath(tmpdir());
    const target = await realpath(profile);
    if (!target.startsWith(root + sep) || !target.split(sep).at(-1).startsWith('horus-chat-smoke-')) {
      throw new Error('Refusing to remove browser profile outside the smoke directory.');
    }
    await rm(target, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
  })();
  return cleanupPromise;
}
for (const signal of ['SIGINT', 'SIGTERM']) {
  process.once(signal, () => {
    cleanup().catch((error) => process.stderr.write(`${error.message}\n`))
      .finally(() => process.exit(signal === 'SIGINT' ? 130 : 143));
  });
}

try {
  await waitFor(async () => (await fetch(`http://127.0.0.1:${port}/json/version`)).ok);
  const page = await (await fetch(`http://127.0.0.1:${port}/json/new?${encodeURIComponent(baseUrl)}`, { method: 'PUT' })).json();
  socket = new WebSocket(page.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => {
    socket.addEventListener('open', resolve, { once: true });
    socket.addEventListener('error', reject, { once: true });
  });

  let nextId = 0;
  const pending = new Map();
  socket.addEventListener('message', ({ data }) => {
    const result = JSON.parse(data);
    if (!result.id || !pending.has(result.id)) return;
    const { resolve, reject } = pending.get(result.id);
    pending.delete(result.id);
    result.error ? reject(new Error(result.error.message)) : resolve(result.result);
  });
  const command = (method, params = {}) => new Promise((resolve, reject) => {
    const id = ++nextId;
    pending.set(id, { resolve, reject });
    socket.send(JSON.stringify({ id, method, params }));
  });
  const evaluate = async (expression) => {
    const result = await command('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (result.exceptionDetails) throw new Error(result.exceptionDetails.text);
    return result.result.value;
  };

  await command('Runtime.enable');
  try {
    await waitFor(() => evaluate('document.readyState === "complete" && document.querySelector("[aria-label=\\"Abrir asistente virtual\\"]")?.getAttribute("aria-expanded") === "false"'));
  } catch (error) {
    const state = await evaluate('JSON.stringify({ ready: document.readyState, alpine: !!window.Alpine, button: document.querySelector("[aria-label=\\"Abrir asistente virtual\\"]")?.outerHTML.slice(0, 400), scripts: [...document.querySelectorAll("script[src]")].map((element) => element.src) })');
    throw new Error(`Chat did not initialize: ${state}`, { cause: error });
  }
  const result = await evaluate(`(async () => {
    window.__chatFetch = [];
    const originalFetch = window.fetch;
    window.fetch = (...args) => originalFetch(...args).then(
      (response) => {
        const token = args[1]?.headers?.['X-CSRF-TOKEN'];
        window.__chatFetch.push({ status: response.status, url: String(args[0]), tokenPresent: !!token,
          tokenMatchesDom: token === document.querySelector('[x-data="chatClient"]').dataset.csrfToken });
        return response;
      },
      (error) => { window.__chatFetch.push({ error: String(error) }); throw error; }
    );
    const button = document.querySelector('[aria-label="Abrir asistente virtual"]');
    button.click();
    const input = document.querySelector('#mensaje-chat');
    async function send(text, expected) {
      input.value = text;
      input.dispatchEvent(new Event('input', { bubbles: true }));
      await new Promise((resolve) => setTimeout(resolve, 100));
      input.closest('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
      const deadline = Date.now() + 12000;
      while (Date.now() < deadline) {
        const texts = [...document.querySelectorAll('[x-ref="messages"] p')].map((el) => el.textContent);
        if (texts.some((text) => text.includes(expected))) return true;
        await new Promise((resolve) => setTimeout(resolve, 100));
      }
      return false;
    }
    const known = await send('¿Cuánto cuesta un curso?', 'Los precios dependen');
    if (known) await new Promise((resolve) => setTimeout(resolve, 600));
    const unknown = known && await send('zzqv desconocido', 'No tengo un dato confirmado');
    return {
      open: button.getAttribute('aria-expanded'), known, unknown,
      texts: [...document.querySelectorAll('[x-ref="messages"] p')].map((el) => el.textContent),
      draft: input.value,
      fetches: window.__chatFetch,
    };
  })()`);
  if (result.open !== 'true' || !result.known || !result.unknown
      || result.fetches.length !== 2 || result.fetches.some((request) => request.status !== 200 || !request.tokenMatchesDom)) {
    const browserCookies = await command('Network.getCookies', { urls: [baseUrl] });
    throw new Error(`Chat UI failed: ${JSON.stringify({ ...result, cookieNames: browserCookies.cookies.map((cookie) => cookie.name) })}`);
  }
  process.stdout.write(`Edge chat smoke passed: ${result.texts.length} messages, known answer and fallback visible.\n`);
} finally {
  await cleanup();
}
