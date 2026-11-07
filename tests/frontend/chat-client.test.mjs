import test from 'node:test';
import assert from 'node:assert/strict';
import { chatClient, safeChatLinks } from '../../resources/js/chat-client.js';

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
  const quoteText = 'Para solicitar una cotización continúa por WhatsApp: https://wa.me/51927582305?text=Hola%2C%20deseo%20solicitar%20una%20cotizaci%C3%B3n';
  const quoteLinks = safeChatLinks(quoteText);
  assert.deepEqual(quoteLinks, [{
    url: 'https://wa.me/51927582305?text=Hola%2C%20deseo%20solicitar%20una%20cotizaci%C3%B3n',
    label: 'Solicitar cotización por WhatsApp',
    whatsapp: true,
  }]);

  const matText = 'Un asesor te atenderá: https://wa.me/51927582305?text=Hola%2C%20deseo%20consultar%20sobre%20la%20matr%C3%ADcula';
  const matLinks = safeChatLinks(matText);
  assert.deepEqual(matLinks, [{
    url: 'https://wa.me/51927582305?text=Hola%2C%20deseo%20consultar%20sobre%20la%20matr%C3%ADcula',
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
    url: 'https://wa.me/51927582305?text=Hola%2C%20deseo%20solicitar%20una%20cotizaci%C3%B3n',
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
