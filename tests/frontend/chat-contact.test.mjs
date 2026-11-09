import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { chatClient, safeChatLinks } from '../../resources/js/chat-client.js';

const knowledge = JSON.parse(await readFile(new URL('../../chatbot-python/data/knowledge.json', import.meta.url), 'utf8'));
const contactAnswer = knowledge.records.find(record => record.id === 'contacto_canales').answer;
const whatsapp = { url: 'https://wa.me/51972021374', label: 'Contactar por WhatsApp', whatsapp: true };

test('la respuesta institucional de contacto ofrece WhatsApp aunque solo incluya el teléfono', () => {
  assert.deepEqual(safeChatLinks(contactAnswer), [
    { url: '/contactos', label: 'Contactar con Horus', whatsapp: false },
    whatsapp,
  ]);
});

test('el botón reconoce el teléfono oficial y mantiene un destino fijo', () => {
  for (const phone of ['+51 972 021 374', '+51972021374', '+51 972-021-374']) {
    assert.deepEqual(safeChatLinks(`Puedes contactar al ${phone}.`), [whatsapp]);
  }
  for (const text of ['+51 999 999 999', '+51 972 021 3740', '+52 972 021 374', '¿Tienen WhatsApp?', 'https://evil.example/whatsapp']) {
    assert.deepEqual(safeChatLinks(text), []);
  }
});

test('el contacto no duplica ni sustituye la acción de cotización o matrícula', () => {
  for (const intent of ['solicitar%20una%20cotizaci%C3%B3n', 'consultar%20sobre%20la%20matr%C3%ADcula']) {
    const url = `https://wa.me/51972021374?text=Hola%2C%20deseo%20${intent}`;
    const links = safeChatLinks(`Contacta al +51 972 021 374 o continúa por ${url}`);
    assert.equal(links.length, 1);
    assert.equal(links[0].url, url);
  }
});

test('una consulta de contacto recibe el botón y lo conserva al restaurar la conversación', async (t) => {
  const previous = new Map(['fetch', 'document', 'sessionStorage'].map(name => [name, Object.getOwnPropertyDescriptor(globalThis, name)]));
  t.after(() => {
    for (const [name, descriptor] of previous) {
      if (descriptor) Object.defineProperty(globalThis, name, descriptor);
      else delete globalThis[name];
    }
  });
  const storage = new Map();
  globalThis.sessionStorage = {
    getItem: key => storage.get(key) ?? null,
    setItem: (key, value) => storage.set(key, value),
    removeItem: key => storage.delete(key),
  };
  globalThis.document = { activeElement: null, body: {} };
  const createClient = () => {
    const chat = chatClient();
    chat.$el = { offsetParent: null, dataset: { endpoint: '/chat/mensaje', csrfToken: 'fixture' } };
    chat.$refs = { messages: { scrollHeight: 100, scrollTop: 0, querySelector: () => null } };
    chat.$nextTick = callback => callback();
    chat.init();
    return chat;
  };
  let requests = 0;
  globalThis.fetch = async (_url, options) => {
    requests++;
    assert.equal(JSON.parse(options.body).mensaje, '¿Me das un contacto?');
    return { ok: true, json: async () => ({ respuesta: contactAnswer, fuente: 'advisor' }) };
  };
  const chat = createClient();
  chat.draft = '¿Me das un contacto?';
  await chat.sendMessage();
  assert.deepEqual(chat.messages.at(-1).links.at(-1), whatsapp);
  const restored = createClient();
  assert.deepEqual(restored.messages.at(-1).links.at(-1), whatsapp);
  assert.equal(requests, 1);
});
