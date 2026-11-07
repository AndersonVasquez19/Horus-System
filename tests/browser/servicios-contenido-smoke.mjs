// Solo http://127.0.0.1:8775 con servicios-contenido-browser-router.php (opt-in) y SQLite :memory: por petición.
// Compara, en Edge y en 390/768/1440 px, las tres páginas públicas con el catálogo apagado (HTML estático original)
// frente al contenido inicial importado y autorizado (dinámico): mismo texto, mismas imágenes, misma geometría.
import { spawn } from 'node:child_process';
import { request } from 'node:http';
import { createServer } from 'node:net';
import { mkdir, mkdtemp, realpath, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, sep, resolve } from 'node:path';

const base = 'http://127.0.0.1:8775';
const debugPort = 19337;
const evidence = resolve('storage/app/private/testing/servicios-contenido');
await mkdir(evidence, { recursive: true });
for (const port of [8775, debugPort]) {
  await new Promise((ok, fail) => {
    const probe = createServer();
    probe.once('error', fail);
    probe.listen(port, '127.0.0.1', () => probe.close(ok));
  });
}
const profile = await mkdtemp(join(tmpdir(), 'horus-servicios-contenido-'));
const server = spawn('php', ['-S', '127.0.0.1:8775', '-t', 'public', 'tests/Support/servicios-contenido-browser-router.php'], {
  env: { ...process.env, HORUS_SERVICIOS_CONTENIDO_BROWSER_FIXTURE: '1', HORUS_SERVICIOS_CONTENIDO_FIXTURE_TIME: new Date().toISOString(), APP_ENV: 'testing', DB_CONNECTION: 'sqlite', DB_DATABASE: ':memory:', DB_URL: '' },
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
  throw new Error(`Contenido smoke timed out${last ? ': ' + last.message : ''}`);
}
const paginas = [
  { ruta: '/tecnologias/cableado-estructurado', nombre: 'cableado', tarjetas: 6, selector: '.cartas-contenedor .carta' },
  { ruta: '/tecnologias/camaras-seguridad', nombre: 'camaras', tarjetas: 10, selector: '.cartas-contenedor .carta' },
  { ruta: '/tecnologias/soporte-mantenimiento', nombre: 'soporte', tarjetas: 3, selector: '.carta-grid .carta-card' },
];
let socket;
let debugEvaluate;
let debugScreenshot;
const report = { sqlite: ':memory: per request', checks: [], externalBlocked: [], errors: [] };
try {
  await waitFor(async () => (await localRequest(`${base}/tecnologias/cableado-estructurado`)).headers['x-horus-fixture'] === 'sqlite-memory-servicios-contenido');
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
  socket.addEventListener('message', ({ data }) => {
    const message = JSON.parse(data);
    if (pending.has(message.id)) {
      const task = pending.get(message.id);
      pending.delete(message.id);
      clearTimeout(task.timer);
      message.error ? task.fail(new Error(message.error.message)) : task.ok(message.result);
    } else if (message.method === 'Runtime.exceptionThrown') report.errors.push(message.params.exceptionDetails.text);
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
  const navigate = async (path) => {
    await evaluate('document.documentElement.dataset.smokePrevious = 1');
    await command('Page.navigate', { url: base + path });
    await waitFor(() => evaluate(`!document.documentElement.dataset.smokePrevious && location.pathname === ${JSON.stringify(path)} && document.readyState === 'complete' && !!document.querySelector('.legacy-showcase')`));
    await evaluate('document.fonts.ready');
    await evaluate(`(async () => {
      for (const img of document.images) { img.scrollIntoView(); await new Promise(done => { if (img.complete) done(); else { img.onload = done; img.onerror = done; } }); }
      scrollTo(0, 0); await new Promise(done => requestAnimationFrame(() => requestAnimationFrame(done)));
    })()`);
  };
  const screenshot = async (name) => {
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
  await command('Fetch.enable', { patterns: [{ urlPattern: '*' }] });
  const medir = (selector) => evaluate(`(() => {
    const raiz = document.querySelector('.legacy-showcase');
    const propias = [...raiz.children].filter(c => c.id !== 'catalogo-servicios'); // El catálogo genérico (Sprint 18.2) se verifica aparte.
    const cajas = propias.flatMap(c => [c, ...c.querySelectorAll('*')]).map(e => { const b = e.getBoundingClientRect(); return [e.tagName, Math.round(b.x), Math.round(b.y), Math.round(b.width), Math.round(b.height)]; });
    const tarjetas = [...document.querySelectorAll(${JSON.stringify(selector)})];
    return {
      texto: propias.map(c => c.innerText).join(' ').replace(/\\s+/g, ' ').trim(),
      imagenes: [...document.images].filter(i => propias.some(p => p.contains(i))).map(i => i.getAttribute('src')),
      imagenesCargadas: [...document.images].filter(i => propias.some(p => p.contains(i))).every(i => i.complete && i.naturalWidth > 0),
      cajas, tarjetas: tarjetas.length,
      cotizaciones: tarjetas.every(t => {
        const button = t.querySelector('.carta-solicitar-cotizacion');
        return button && button.dataset.servicio === t.querySelector('h3').textContent.trim() && button.getBoundingClientRect().height >= 44;
      }),
      sinDesbordePagina: document.documentElement.scrollWidth <= innerWidth,
      contenidas: tarjetas.every(t => t.getBoundingClientRect().right <= innerWidth + 1),
    };
  })()`);
  for (const width of [390, 768, 1440]) {
    await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width === 390 });
    for (const pagina of paginas) {
      const resultados = {};
      for (const caso of ['off', 'on']) {
        await command('Network.setExtraHTTPHeaders', { headers: { 'X-Horus-Fixture-Content': caso } });
        await navigate(pagina.ruta);
        resultados[caso] = await medir(pagina.selector);
        if (!resultados[caso].cotizaciones) throw new Error(`Cotización ausente o sin contexto en ${pagina.nombre}/${width}/${caso}`);
        const servicio = await evaluate(`(() => {
          window.__quoteRequests = 0;
          window.fetch = () => { window.__quoteRequests++; throw new Error('Cotización no debe enviar mensajes automáticamente'); };
          const button = document.querySelector('.carta-solicitar-cotizacion');
          button.scrollIntoView({block: 'center'});
          const bounds = button.getBoundingClientRect();
          const hit = document.elementFromPoint(bounds.x + bounds.width / 2, bounds.y + bounds.height / 2);
          if (button !== hit && !button.contains(hit)) throw new Error('El botón está cubierto');
          button.click();
          return button.dataset.servicio;
        })()`);
        await waitFor(() => evaluate(`document.getElementById('horus-chat-panel').getBoundingClientRect().width > 0 && document.querySelector('.hc-log').textContent.includes(${JSON.stringify(servicio)}) && document.activeElement.id === 'mensaje-chat'`));
        const cotizacion = await evaluate(`(() => {
          const link = [...document.querySelectorAll('.hc-action')].at(-1);
          return { whatsapp: link?.getAttribute('href') === 'https://wa.me/51927582305?text=Hola%2C%20deseo%20solicitar%20una%20cotizaci%C3%B3n', requests: window.__quoteRequests };
        })()`);
        if (!cotizacion.whatsapp || cotizacion.requests !== 0) throw new Error('Derivación de cotización incorrecta');
        await evaluate(`document.querySelector('[aria-label="Cerrar chat"]').click()`);
        await waitFor(() => evaluate(`getComputedStyle(document.getElementById('horus-chat-panel')).display === 'none' && document.activeElement === document.querySelector('.carta-solicitar-cotizacion')`));
        await evaluate('document.activeElement.blur(); scrollTo(0, 0)');
        await screenshot(`${pagina.nombre}-${caso}-${width}`);
      }
      const { off, on } = resultados;
      if (off.tarjetas !== pagina.tarjetas || on.tarjetas !== pagina.tarjetas) throw new Error(`Tarjetas inesperadas en ${pagina.nombre}/${width}: ${off.tarjetas}/${on.tarjetas}`);
      if (off.texto !== on.texto) throw new Error(`Texto distinto en ${pagina.nombre}/${width}`);
      if (JSON.stringify(off.imagenes) !== JSON.stringify(on.imagenes)) throw new Error(`Imágenes distintas en ${pagina.nombre}/${width}`);
      if (JSON.stringify(off.cajas) !== JSON.stringify(on.cajas)) throw new Error(`Geometría distinta en ${pagina.nombre}/${width}`);
      for (const caso of ['off', 'on']) {
        const r = resultados[caso];
        if (!r.sinDesbordePagina || !r.imagenesCargadas || !r.contenidas) throw new Error(`Diseño roto en ${pagina.nombre}/${width}/${caso}: ${JSON.stringify({ ...r, cajas: undefined, texto: undefined })}`);
      }
      // Textos extremos: no deben desbordar ni la tarjeta ni la página.
      await command('Network.setExtraHTTPHeaders', { headers: { 'X-Horus-Fixture-Content': 'largo' } });
      await navigate(pagina.ruta);
      const largo = await medir(pagina.selector);
      await screenshot(`${pagina.nombre}-largo-${width}`);
      report.checks.push({ pagina: pagina.nombre, width, tarjetas: on.tarjetas, identica: true, largoSinDesborde: largo.sinDesbordePagina, largoContenidas: largo.contenidas });
      if (!largo.sinDesbordePagina) report.checks.at(-1).advertencia = 'texto extremo desborda la página';
    }
  }
  if (report.errors.length || report.externalBlocked.length) throw new Error('Unexpected browser errors or external requests');
  await writeFile(join(evidence, 'report.json'), JSON.stringify(report, null, 2));
  process.stdout.write(JSON.stringify({ evidence, checks: report.checks.length, errors: report.errors, externalBlocked: report.externalBlocked, resumen: report.checks }) + '\n');
} catch (error) {
  report.failure = error.message;
  if (debugEvaluate) report.page = await debugEvaluate('({ url: location.href, text: document.body.innerText.slice(0, 1200) })').catch(() => null);
  await debugScreenshot?.('failure').catch(() => {});
  await writeFile(join(evidence, 'report.json'), JSON.stringify(report, null, 2));
  throw error;
} finally {
  socket?.close();
  browser.kill(); server.kill();
  await pause(500);
  const root = await realpath(tmpdir());
  const target = await realpath(profile);
  if (!target.startsWith(root + sep) || !target.split(sep).at(-1).startsWith('horus-servicios-contenido-')) throw new Error('Unsafe profile cleanup path');
  await rm(target, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
}
