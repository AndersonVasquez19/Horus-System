import test, { beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { chatClient, safeChatLinks } from '../../resources/js/chat-client.js';

beforeEach(() => {
  const values = new Map();
  globalThis.sessionStorage = {
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, String(value)),
    removeItem: (key) => values.delete(key),
  };
});

function client() {
  const chat = chatClient();
  chat.$refs = { messages: { scrollTop: 0, scrollHeight: 100, clientHeight: 50, querySelector: () => null } };
  chat.$nextTick = (callback) => callback();
  chat.$el = { offsetParent: null, dataset: { endpoint: '/chat/mensaje', csrfToken: 'test-token' } };
  globalThis.document = { activeElement: null, body: {} };
  chat.endpoint = '/chat/mensaje';
  chat.draft = '¿Qué cursos ofrecen?';
  return chat;
}

test('only predefined public internal links are rendered from untrusted responses', () => {
  const links = safeChatLinks('<img src=x onerror=alert(1)> https://evil.example/admin javascript:alert(1) /educacion/cursos');
  assert.deepEqual(links, [{ url: '/educacion/cursos', label: 'Ver cursos', whatsapp: false }]);
  assert.deepEqual(safeChatLinks('https://evil.example /admin/usuarios'), []);
});

test('whitelisted official whatsapp destinations produce safe action buttons', () => {
  const quoteText = 'Para solicitar una cotización continúa por WhatsApp: https://wa.me/51972021374?text=Hola%2C%20deseo%20solicitar%20una%20cotizaci%C3%B3n';
  const quoteLinks = safeChatLinks(quoteText);
  assert.deepEqual(quoteLinks, [{
    url: 'https://wa.me/51972021374?text=Hola%2C%20deseo%20solicitar%20una%20cotizaci%C3%B3n',
    label: 'Solicitar cotización por WhatsApp',
    whatsapp: true,
  }]);

  const matText = 'Un asesor te atenderá: https://wa.me/51972021374?text=Hola%2C%20deseo%20consultar%20sobre%20la%20matr%C3%ADcula';
  const matLinks = safeChatLinks(matText);
  assert.deepEqual(matLinks, [{
    url: 'https://wa.me/51972021374?text=Hola%2C%20deseo%20consultar%20sobre%20la%20matr%C3%ADcula',
    label: 'Consultar matrícula por WhatsApp',
    whatsapp: true,
  }]);

  // Arbitrary or spoofed WhatsApp destinations are rejected
  assert.deepEqual(safeChatLinks('https://wa.me/51999999999 https://evil.com/whatsapp'), []);
});

test('double submit shares one request and bounds the visible history', async () => {
  const chat = client();
  chat.messages = Array.from({ length: 80 }, () => ({ role: 'bot', text: 'old' }));
  let resolve;
  let calls = 0;
  globalThis.fetch = () => { calls++; return new Promise((done) => { resolve = done; }); };
  const first = chat.sendMessage();
  chat.draft = 'consulta duplicada';
  await chat.sendMessage();
  resolve({ ok: true, json: async () => ({ respuesta: 'Consulta /educacion/cursos' }) });
  await first;
  assert.equal(calls, 1);
  assert.equal(chat.messages.length, 41);
  assert.equal(chat.sending, false);
  assert.equal(chat.messages.at(-1).links[0].url, '/educacion/cursos');
});

for (const [status, expected] of [[429, 'Espera un minuto'], [419, 'La sesión venció'], [500, 'No pude completar']]) {
  test(`HTTP ${status} keeps the draft and gives a useful error without retrying`, async () => {
    const chat = client();
    let calls = 0;
    globalThis.fetch = async () => { calls++; return { ok: false, status }; };
    await chat.sendMessage();
    assert.equal(calls, 1);
    assert.equal(chat.draft, '¿Qué cursos ofrecen?');
    assert.ok(chat.messages.at(-1).text.includes(expected));
    assert.equal(chat.sending, false);
  });
}

test('a network failure preserves a newer draft and a reader who scrolled back', async () => {
  const chat = client();
  globalThis.fetch = async () => {
    chat.draft = 'otro borrador';
    chat.$refs.messages.scrollHeight = 500;
    chat.trackScroll();
    throw new TypeError('network unavailable');
  };
  await chat.sendMessage();
  assert.equal(chat.draft, 'otro borrador');
  assert.equal(chat.$refs.messages.scrollTop, 0);
});

test('storage denial still allows an in-memory session', () => {
  globalThis.sessionStorage = { getItem() { throw new Error('denied'); }, setItem() { throw new Error('denied'); } };
  const chat = client();
  chat.init();
  assert.ok(chat.sessionId);
  assert.equal(chat.endpoint, '/chat/mensaje');
});

test('solicitar cotización muestra el servicio y WhatsApp sin enviar ni borrar el borrador', () => {
  const chat = client();
  const initialDraft = chat.draft;
  let focused = false;
  chat.$refs.question = { focus: () => { focused = true; } };
  globalThis.fetch = () => { throw new Error('El botón no debe enviar mensajes'); };

  chat.solicitarCotizacion('Mantenimiento preventivo');

  assert.equal(chat.draft, initialDraft);
  assert.equal(chat.sending, false);
  assert.ok(chat.messages.at(-1).text.includes('Mantenimiento preventivo'));
  assert.deepEqual(chat.messages.at(-1).links, [{
    url: 'https://wa.me/51972021374?text=Hola%2C%20deseo%20solicitar%20una%20cotizaci%C3%B3n',
    label: 'Solicitar cotización por WhatsApp', whatsapp: true,
  }]);
  assert.equal(focused, true);
  assert.equal(chat.$refs.messages.scrollTop, chat.$refs.messages.scrollHeight);
});

test('solicitar cotización evita duplicados seguidos y conserva el límite del historial', () => {
  const chat = client();
  chat.messages = Array.from({ length: 80 }, () => ({ role: 'bot', text: 'anterior' }));
  chat.solicitarCotizacion('Configuración e instalación');
  chat.solicitarCotizacion('Configuración e instalación');
  assert.equal(chat.messages.length, 40);
  assert.equal(chat.messages.filter(message => message.text.includes('Configuración e instalación')).length, 1);

  chat.solicitarCotizacion('Asesoría y capacitación');
  assert.equal(chat.messages.length, 40);
  assert.ok(chat.messages.at(-1).text.includes('Asesoría y capacitación'));
});

test('cotización ignora servicios inválidos y conserva una petición en curso', () => {
  const chat = client();
  const original = structuredClone(chat.messages);
  for (const service of [undefined, null, {}, '', '   ', 'a'.repeat(121)]) {
    chat.solicitarCotizacion(service);
  }
  assert.deepEqual(chat.messages, original);

  chat.sending = true;
  chat.$refs.question = { focus: () => { throw new Error('El campo está deshabilitado'); } };
  chat.solicitarCotizacion('Mantenimiento preventivo');
  assert.equal(chat.sending, true);
  assert.equal(chat.draft, '¿Qué cursos ofrecen?');
});

const historyKey = 'horus-chat-history:/chat/mensaje';

test('Inicio a Cursos y una recarga conservan mensajes, acciones y sesión sin reenviar el historial', async () => {
  const inicio = client();
  inicio.init();
  let request;
  globalThis.fetch = async (_url, options) => {
    request = JSON.parse(options.body);
    return { ok: true, json: async () => ({ respuesta: 'Consulta /educacion/cursos' }) };
  };
  await inicio.sendMessage();
  globalThis.fetch = () => { throw new Error('Restaurar no debe enviar peticiones'); };
  const cursos = client();
  cursos.$el.dataset.csrfToken = 'nuevo-token';
  cursos.init();
  const recarga = client();
  recarga.init();

  assert.deepEqual(cursos.messages, inicio.messages);
  assert.deepEqual(recarga.messages, inicio.messages);
  assert.equal(cursos.sessionId, inicio.sessionId);
  assert.equal(cursos.csrfToken, 'nuevo-token');
  assert.deepEqual(request, { mensaje: '¿Qué cursos ofrecen?', session_id: inicio.sessionId });
  const saved = sessionStorage.getItem(historyKey);
  assert.ok(saved);
  assert.ok(!saved.includes('test-token'));
  assert.ok(!saved.includes('csrfToken'));
});

test('la orientación de cotización y su enlace sobreviven a la navegación', () => {
  const inicio = client();
  inicio.init();
  inicio.solicitarCotizacion('Mantenimiento preventivo');
  const cursos = client();
  cursos.init();
  assert.deepEqual(cursos.messages, inicio.messages);
});

test('una navegación durante la petición conserva la consulta sin bloquear el nuevo chat', async () => {
  const inicio = client();
  inicio.init();
  let resolve;
  globalThis.fetch = () => new Promise((done) => { resolve = done; });
  const pending = inicio.sendMessage();
  const cursos = client();
  cursos.init();
  assert.equal(cursos.messages.at(-1).text, '¿Qué cursos ofrecen?');
  assert.equal(cursos.sending, false);
  resolve({ ok: true, json: async () => ({ respuesta: 'Respuesta de prueba' }) });
  await pending;
});

test('los errores de envío también se conservan después de navegar', async () => {
  const inicio = client();
  inicio.init();
  globalThis.fetch = async () => ({ ok: false, status: 429 });
  await inicio.sendMessage();
  const cursos = client();
  cursos.init();
  assert.deepEqual(cursos.messages, inicio.messages);
});

test('el historial caduca a los 30 minutos sin renovarse solo por navegar', (t) => {
  t.mock.timers.enable({ apis: ['Date'], now: 1800000000000 });
  const inicio = client();
  inicio.init();
  inicio.solicitarCotizacion('Soporte');
  t.mock.timers.tick(29 * 60 * 1000);
  const antes = client();
  antes.init();
  assert.deepEqual(antes.messages, inicio.messages);
  t.mock.timers.tick(60 * 1000);
  const despues = client();
  despues.init();
  assert.equal(despues.messages.length, 1);
  assert.equal(sessionStorage.getItem(historyKey), null);
});

test('el probador administrativo y una sesión nueva no recuperan el historial público', () => {
  const inicio = client();
  inicio.init();
  inicio.solicitarCotizacion('Soporte');
  const admin = client();
  admin.$el.dataset.endpoint = '/admin/asistente/mensaje';
  admin.init();
  assert.equal(admin.messages.length, 1);
  sessionStorage.removeItem('horus-chat-session:/chat/mensaje');
  const nueva = client();
  nueva.init();
  assert.notEqual(nueva.sessionId, inicio.sessionId);
  assert.equal(nueva.messages.length, 1);
});

test('cerrar la sesión de almacenamiento deja una conversación nueva', () => {
  const inicio = client();
  inicio.init();
  inicio.solicitarCotizacion('Soporte');
  sessionStorage = { getItem: () => null, setItem() {}, removeItem() {} };
  const nueva = client();
  nueva.init();
  assert.equal(nueva.messages.length, 1);
});

test('el almacenamiento lleno permite seguir conversando y descarta el historial anterior', async () => {
  const inicio = client();
  inicio.init();
  inicio.solicitarCotizacion('Soporte');
  sessionStorage.setItem = () => { throw new Error('QuotaExceededError'); };
  globalThis.fetch = async () => ({ ok: true, json: async () => ({ respuesta: 'Respuesta de prueba' }) });
  await inicio.sendMessage();
  assert.equal(inicio.messages.at(-1).text, 'Respuesta de prueba');
  assert.equal(sessionStorage.getItem(historyKey), null);
});

test('restaurar descarta JSON corrupto, versiones desconocidas, fechas futuras y mensajes inválidos', () => {
  const inicio = client();
  inicio.init();
  inicio.solicitarCotizacion('Soporte');
  const valid = JSON.parse(sessionStorage.getItem(historyKey));
  for (const value of [
    '{', 'null', JSON.stringify({ ...valid, version: 99 }),
    JSON.stringify({ ...valid, savedAt: Date.now() + 60000 }),
    JSON.stringify({ ...valid, messages: [{ role: 'system', text: 'No permitido' }] }),
    JSON.stringify({ ...valid, messages: [{ role: 'bot', text: {} }] }),
    JSON.stringify({ ...valid, messages: [{ role: 'user', text: 'a'.repeat(4001) }] }),
    JSON.stringify({ ...valid, messages: [] }), ' '.repeat(300001),
  ]) {
    sessionStorage.setItem(historyKey, value);
    const cursos = client();
    cursos.init();
    assert.equal(cursos.messages.length, 1);
    assert.equal(sessionStorage.getItem(historyKey), null);
  }
});

test('restaurar limita mensajes y vuelve a validar enlaces y tarjetas del almacenamiento', async () => {
  const inicio = client();
  inicio.init();
  const card = {
    id: 'servicio:soporte', tipo: 'servicio', titulo: 'Soporte', descripcion: 'Información pública de prueba',
    url: '/tecnologias/soporte-mantenimiento', imagen: '/assets/images/tecnologias/mantenimiento.png',
  };
  globalThis.fetch = async () => ({ ok: true, json: async () => ({
    respuesta: 'Consulta /educacion/cursos', fuente: 'advisor', tarjetas: [card],
  }) });
  await inicio.sendMessage();
  const saved = JSON.parse(sessionStorage.getItem(historyKey));
  saved.messages = Array.from({ length: 80 }, () => ({ role: 'user', text: 'Consulta anterior' }));
  saved.messages.push({
    ...inicio.messages.at(-1),
    links: [{ url: 'javascript:alert(1)', label: 'Inseguro' }, { url: 'https://evil.example/educacion/cursos' }],
    cards: [card, { ...card, id: 'otro', imagen: 'https://evil.example/pixel', url: 'javascript:alert(1)' }],
  });
  sessionStorage.setItem(historyKey, JSON.stringify(saved));
  const cursos = client();
  cursos.init();
  assert.equal(cursos.messages.length, 41);
  assert.deepEqual(cursos.messages.at(-1).links, [{ url: '/educacion/cursos', label: 'Ver cursos', whatsapp: false }]);
  assert.deepEqual(cursos.messages.at(-1).cards[0], card);
  assert.equal(cursos.messages.at(-1).cards[1].url, null);
  assert.equal(cursos.messages.at(-1).cards[1].imagen, null);
});
