import test, { beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { chatClient } from '../../resources/js/chat-client.js';

const original = { fetch: globalThis.fetch, setTimeout: globalThis.setTimeout, clearTimeout: globalThis.clearTimeout };
let timers;
let listeners;
let calls;
let nextTimer;

beforeEach(() => {
  timers = new Map(); listeners = new Map(); calls = []; nextTimer = 0;
  globalThis.setTimeout = (callback, delay) => { const id = ++nextTimer; timers.set(id, { callback, delay }); return id; };
  globalThis.clearTimeout = (id) => timers.delete(id);
  globalThis.document = {
    hidden: false,
    addEventListener: (name, callback) => listeners.set(name, callback),
    removeEventListener: (name) => listeners.delete(name),
  };
  globalThis.sessionStorage = { getItem: () => null, setItem() {}, removeItem() {} };
  globalThis.fetch = async (url, options) => {
    calls.push({ url, options });
    return { ok: true, json: async () => ({ estado: 'cargando' }) };
  };
});

afterEach(() => Object.assign(globalThis, original));

const settle = async () => { for (let i = 0; i < 12; i++) await Promise.resolve(); };
function client(endpoint = '/chat/estado') {
  const chat = chatClient();
  chat.$el = { dataset: { endpoint: '/chat/mensaje', csrfToken: 'ficticio', estadoEndpoint: endpoint } };
  chat.$refs = { messages: {} };
  chat.$nextTick = (callback) => callback();
  chat.$watch = (_name, callback) => { chat.onOpen = callback; };
  chat.abierto = false;
  chat.init();
  return chat;
}

test('cerrado no consulta y al abrir usa solo el endpoint público sin mensajes ni credenciales', async () => {
  const chat = client();
  assert.equal(calls.length, 0);
  chat.onOpen(true);
  await settle();
  assert.equal(calls.length, 1);
  assert.equal(calls[0].url, '/chat/estado');
  assert.equal(calls[0].options.method, 'GET');
  assert.deepEqual(calls[0].options.headers, { Accept: 'application/json' });
  assert.equal(calls[0].options.redirect, 'error');
  assert.equal(calls[0].options.body, undefined);
  assert.equal(chat.estadoServicio, 'cargando');
  assert.equal(chat.puedeEnviar(), false);
  assert.equal(timers.get(chat._estadoTimer).delay, 5000);
  chat.destroy();
});

test('conserva el borrador y no envía antes de listo ni automáticamente al recuperarse', async () => {
  const chat = client();
  chat.draft = 'quiero cámaras';
  const initial = structuredClone(chat.messages);
  chat.onOpen(true);
  await settle();
  await chat.sendMessage();
  assert.deepEqual(chat.messages, initial);
  assert.equal(chat.draft, 'quiero cámaras');
  globalThis.fetch = async () => ({ ok: true, json: async () => ({ estado: 'listo' }) });
  chat.reintentarEstado();
  await settle();
  assert.equal(chat.puedeEnviar(), true);
  assert.equal(chat.draft, 'quiero cámaras');
  assert.deepEqual(chat.messages, initial);
  assert.equal(timers.get(chat._estadoTimer).delay, 15000);
  chat.destroy();
});

for (const payload of [{ estado: 'arbitrario' }, { token: 'dato-ficticio' }, null]) {
  test(`un cuerpo inesperado nunca habilita enviar: ${JSON.stringify(payload)}`, async () => {
    globalThis.fetch = async () => ({ ok: true, json: async () => payload });
    const chat = client();
    chat.onOpen(true);
    await settle();
    assert.equal(chat.estadoServicio, 'no_disponible');
    assert.equal(chat.puedeEnviar(), false);
    assert.equal(chat.textoEstado().includes('dato-ficticio'), false);
    chat.destroy();
  });
}

test('un error de red permite reintentar y HTTP 429 reduce las consultas', async () => {
  globalThis.fetch = async () => { throw new TypeError('Error interno no publicable'); };
  const chat = client();
  chat.onOpen(true);
  await settle();
  assert.equal(chat.estadoServicio, 'no_disponible');
  globalThis.fetch = async () => ({ ok: false, status: 429 });
  chat.reintentarEstado();
  await settle();
  assert.equal(timers.get(chat._estadoTimer).delay, 30000);
  chat.destroy();
});

test('cerrar aborta la petición y una respuesta tardía no revive el indicador ni su temporizador', async () => {
  let resolve;
  let signal;
  globalThis.fetch = (_url, options) => {
    signal = options.signal;
    return new Promise((done) => { resolve = done; });
  };
  const chat = client();
  chat.onOpen(true);
  chat.onOpen(false);
  assert.equal(signal.aborted, true);
  resolve({ ok: true, json: async () => ({ estado: 'listo' }) });
  await settle();
  assert.equal(chat.estadoServicio, 'comprobando');
  assert.equal(timers.size, 0);
  chat.destroy();
});

test('pestaña oculta suspende consultas; al volver comprueba de nuevo y destroy elimina listeners', async () => {
  const chat = client();
  chat.onOpen(true);
  await settle();
  document.hidden = true;
  listeners.get('visibilitychange')();
  assert.equal(timers.size, 0);
  assert.equal(calls.length, 1);
  document.hidden = false;
  listeners.get('visibilitychange')();
  await settle();
  assert.equal(calls.length, 2);
  chat.destroy();
  assert.equal(timers.size, 0);
  assert.equal(listeners.size, 0);
});

test('sin endpoint conserva el probador existente y rechaza URLs externas', () => {
  for (const endpoint of ['', 'http://127.0.0.1:8001/ready', 'https://otro.invalid/chat/estado']) {
    const chat = client(endpoint);
    assert.equal(chat.endpointEstado, '');
    assert.equal(chat.puedeEnviar(), true);
    assert.equal(calls.length, 0);
    chat.destroy();
  }
});
