import { spawn } from 'node:child_process';
import { mkdtemp, realpath, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, sep } from 'node:path';

// --history: solo http://127.0.0.1:8765/ con horus-ai-browser-router.php,
// SQLite :memory:, sesión/caché/correo array y cola sync; respuestas simuladas en Edge.
const baseUrl = process.env.CHAT_SMOKE_URL || 'http://127.0.0.1:8765/';
const historyOnly = process.argv.includes('--history');
if (historyOnly && baseUrl !== 'http://127.0.0.1:8765/') {
  throw new Error('History smoke requires the isolated fixture at http://127.0.0.1:8765/.');
}
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
  if (historyOnly) {
    await command('Page.enable');
    // Se instala antes de cada navegación: ninguna consulta alcanza un proveedor real.
    await command('Page.addScriptToEvaluateOnNewDocument', { source: `
      window.__chatRequests = 0;
      window.__chatDocument = crypto.randomUUID();
      window.__chatTokenMatches = false;
      const original = window.fetch;
      window.fetch = (url, options) => {
        if (String(url).endsWith('/chat/mensaje')) {
          window.__chatRequests++;
          window.__chatTokenMatches = options.headers['X-CSRF-TOKEN'] === document.querySelector('[x-data="chatClient"]').dataset.csrfToken;
          return Promise.resolve(new Response(JSON.stringify({
            respuesta: 'Respuesta ficticia para probar navegación. Consulta /educacion/cursos',
            fuente: 'advisor', tarjetas: [{ id: 'servicio:fixture', tipo: 'servicio', titulo: 'Servicio de prueba',
              descripcion: 'Contenido ficticio del smoke.', url: '/tecnologias/soporte-mantenimiento', imagen: null }]
          }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
        }
        return original(url, options);
      };
    ` });
    const ready = async (path) => waitFor(() => evaluate(`location.pathname === ${JSON.stringify(path)}
      && document.readyState === 'complete' && !!window.Alpine
      && !!document.querySelector('[x-data="chatClient"]')?._x_dataStack`));
    const reloadPage = async () => {
      const previous = await evaluate('window.__chatDocument');
      await command('Page.reload');
      await waitFor(() => evaluate(`!!window.__chatDocument && window.__chatDocument !== ${JSON.stringify(previous)}`));
      await ready('/educacion/cursos');
    };
    const snapshot = () => evaluate(`({
      texts: [...document.querySelectorAll('.hc-message > p')].map(el => el.textContent),
      links: [...document.querySelectorAll('.hc-action')].map(el => el.getAttribute('href')),
      cards: [...document.querySelectorAll('.hc-card h3')].map(el => el.textContent),
      session: sessionStorage.getItem('horus-chat-session:' + document.querySelector('[x-data="chatClient"]').dataset.endpoint),
      requests: window.__chatRequests,
      sending: document.querySelector('#mensaje-chat').disabled
    })`);
    await command('Page.navigate', { url: baseUrl });
    await ready('/');
    await evaluate(`(async () => {
      document.querySelector('.hc-launcher').click();
      const input = document.querySelector('#mensaje-chat');
      input.value = 'Consulta ficticia de cursos';
      input.dispatchEvent(new Event('input', { bubbles: true }));
      await new Promise(resolve => setTimeout(resolve, 50));
      input.closest('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    })()`);
    await waitFor(() => evaluate(`document.querySelectorAll('.hc-message').length === 3 && !document.querySelector('#mensaje-chat').disabled`));
    const initial = await snapshot();
    if (initial.requests !== 1 || !initial.session || initial.texts.length !== 3 || initial.cards[0] !== 'Servicio de prueba'
        || !await evaluate('window.__chatTokenMatches')) throw new Error('Initial fixture conversation failed: ' + JSON.stringify({
          requests: initial.requests, messages: initial.texts.length, cards: initial.cards,
          tokenMatches: await evaluate('window.__chatTokenMatches'),
        }));
    await evaluate(`document.querySelector('.hc-action[href="/educacion/cursos"]').click()`);
    await ready('/educacion/cursos');
    await evaluate(`document.querySelector('.hc-launcher').click()`);
    const cursos = await snapshot();
    await reloadPage();
    await command('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    await evaluate(`document.querySelector('.hc-launcher').click()`);
    const reload = await snapshot();
    for (const state of [cursos, reload]) {
      if (JSON.stringify(state.texts) !== JSON.stringify(initial.texts)
          || JSON.stringify(state.links) !== JSON.stringify(initial.links)
          || JSON.stringify(state.cards) !== JSON.stringify(initial.cards)
          || state.session !== initial.session || state.requests !== 0 || state.sending) {
        throw new Error('Navigation or reload lost the fixture conversation.');
      }
    }
    await evaluate(`document.querySelector('.hc-close').click(); document.querySelector('.hc-launcher').click()`);
    if (JSON.stringify((await snapshot()).texts) !== JSON.stringify(initial.texts)) throw new Error('Closing the widget lost history.');
    // Caducidad sin esperar 30 minutos ni cambiar los tiempos de espera del smoke.
    await evaluate(`const key = 'horus-chat-history:' + document.querySelector('[x-data="chatClient"]').dataset.endpoint;
      const saved = JSON.parse(sessionStorage.getItem(key));
      saved.savedAt = Date.now() - 30 * 60 * 1000; sessionStorage.setItem(key, JSON.stringify(saved));`);
    await reloadPage();
    if ((await snapshot()).texts.length !== 1 || !await evaluate(`sessionStorage.getItem('horus-chat-history:' + document.querySelector('[x-data="chatClient"]').dataset.endpoint) === null`)) {
      throw new Error('Expired history was restored.');
    }
    process.stdout.write('Edge history smoke passed: Inicio → Cursos, reload, desktop/mobile, messages, links, cards, session, no repeated requests, close/reopen and expiry.\n');
  } else {
  try {
    await waitFor(() => evaluate('document.readyState === "complete" && document.querySelector("[aria-label=\\"Abrir chat con el asistente Horus\\"]")?.getAttribute("aria-expanded") === "false"'));
  } catch (error) {
    const state = await evaluate('JSON.stringify({ ready: document.readyState, alpine: !!window.Alpine, button: document.querySelector("[aria-label=\\"Abrir chat con el asistente Horus\\"]")?.outerHTML.slice(0, 400), scripts: [...document.querySelectorAll("script[src]")].map((element) => element.src) })');
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
    const button = document.querySelector('[aria-label="Abrir chat con el asistente Horus"]');
    const panelId = button.getAttribute('aria-controls');
    button.click();
    await new Promise((resolve) => setTimeout(resolve, 100));
    const panel = document.getElementById(panelId);
    const panelContract = { controls: panelId, panelFound: !!panel, panelRole: panel?.getAttribute('role') };
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
      open: button.getAttribute('aria-expanded'), panelContract, known, unknown,
      texts: [...document.querySelectorAll('[x-ref="messages"] p')].map((el) => el.textContent),
      draft: input.value,
      fetches: window.__chatFetch,
    };
  })()`);
  if (result.open !== 'true' || result.panelContract.controls !== 'horus-chat-panel'
      || !result.panelContract.panelFound || result.panelContract.panelRole !== 'dialog'
      || !result.known || !result.unknown
      || result.fetches.length !== 2 || result.fetches.some((request) => request.status !== 200 || !request.tokenMatchesDom)) {
    const browserCookies = await command('Network.getCookies', { urls: [baseUrl] });
    throw new Error(`Chat UI failed: ${JSON.stringify({ ...result, cookieNames: browserCookies.cookies.map((cookie) => cookie.name) })}`);
  }
  await waitFor(() => evaluate(`!document.querySelector('#mensaje-chat').disabled`));
  await command('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
  const mobile = await evaluate(`(async () => {
    await document.fonts.ready;
    const panel = document.getElementById('horus-chat-panel');
    const input = document.getElementById('mensaje-chat');
    input.value = '¿Cuáles son los requisitos?';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await new Promise((resolve) => setTimeout(resolve, 50));
    input.focus();
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
    return { width: panel.getBoundingClientRect().width, viewport: window.innerWidth };
  })()`);
  await waitFor(() => evaluate(`document.querySelector('.hc-log').textContent.includes('No hay una lista general') && !document.querySelector('#mensaje-chat').disabled`));
  const actions = await evaluate(`({
    links: [...document.querySelectorAll('.hc-action')].map((el) => el.getAttribute('href')),
    focus: document.activeElement.id,
    composerVisible: document.querySelector('.hc-compose').getBoundingClientRect().bottom <= window.innerHeight,
    minSendTarget: document.querySelector('[aria-label="Enviar consulta"]').getBoundingClientRect().height,
  })`);
  if (mobile.width > mobile.viewport || !actions.composerVisible || actions.minSendTarget < 44
      || !actions.links.includes('/educacion/cursos') || actions.links.some((url) => !url.startsWith('/'))
      || actions.focus !== 'mensaje-chat') {
    throw new Error('Mobile, action or keyboard contract failed: ' + JSON.stringify({ mobile, actions }));
  }
  await command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
  await waitFor(() => evaluate(`document.querySelector('[aria-label="Abrir chat con el asistente Horus"]').getAttribute('aria-expanded') === 'false' && document.activeElement.getAttribute('aria-label') === 'Abrir chat con el asistente Horus'`));
  await evaluate(`document.querySelector('[aria-label="Abrir chat con el asistente Horus"]').click()`);
  await evaluate(`(async () => {
    window.fetch = async () => ({ ok: false, status: 429 });
    const input = document.getElementById('mensaje-chat');
    input.value = 'Quiero consultar';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await new Promise((resolve) => setTimeout(resolve, 50));
    input.closest('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  })()`);
  await waitFor(() => evaluate(`document.querySelector('.hc-log').textContent.includes('Espera un minuto') && !document.querySelector('#mensaje-chat').disabled`));
  if (!await evaluate(`document.querySelector('#mensaje-chat').value === 'Quiero consultar'`)) throw new Error('Draft lost after HTTP 429');
  if (process.env.CHAT_SMOKE_ARTIFACT_DIR) {
    const screenshot = await command('Page.captureScreenshot', { format: 'png' });
    await writeFile(join(process.env.CHAT_SMOKE_ARTIFACT_DIR, 'chat-mobile.png'), Buffer.from(screenshot.data, 'base64'));
  }
  process.stdout.write(`Edge chat smoke passed: local reply, fallback, mobile 390x844, safe links, Enter, Escape, focus and simulated 429 with draft preserved.\n`);
  }
} finally {
  await cleanup();
}
