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
  assert.deepEqual(links, [{ url: '/educacion/cursos', label: 'Ver cursos' }]);
  assert.deepEqual(safeChatLinks('https://evil.example /admin/usuarios'), []);
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
