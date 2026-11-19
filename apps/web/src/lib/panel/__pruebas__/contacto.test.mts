import assert from 'node:assert/strict';
import { test } from 'node:test';
import { esEnlaceGrupoWhatsApp, normalizarTelefonoPeru, urlMailto, urlWhatsApp } from '../contacto.ts';

test('normaliza solo móviles peruanos', () => {
  assert.equal(normalizarTelefonoPeru('987 654 321'), '51987654321');
  assert.equal(normalizarTelefonoPeru('+51 987654321'), '51987654321');
  assert.equal(normalizarTelefonoPeru('0051987654321'), '51987654321');
  assert.equal(normalizarTelefonoPeru('14155552671'), null, 'número extranjero');
  assert.equal(normalizarTelefonoPeru('12345678'), null);
  assert.equal(normalizarTelefonoPeru(''), null);
  assert.equal(normalizarTelefonoPeru(null), null);
});

test('wa.me codifica el mensaje y rechaza números inválidos', () => {
  assert.equal(urlWhatsApp('987654321', 'Hola & adiós'), 'https://wa.me/51987654321?text=Hola%20%26%20adi%C3%B3s');
  assert.equal(urlWhatsApp('987654321'), 'https://wa.me/51987654321');
  assert.equal(urlWhatsApp('14155552671'), null);
});

test('mailto codifica asunto y cuerpo y no admite inyección de cabeceras', () => {
  assert.equal(urlMailto('ana@example.com', 'Hola mundo', 'a&b'), 'mailto:ana@example.com?subject=Hola%20mundo&body=a%26b');
  assert.equal(urlMailto('ana@example.com'), 'mailto:ana@example.com');
  assert.equal(urlMailto('ana@example.com?bcc=x@y.com'), null);
  assert.equal(urlMailto('a@b.com,c@d.com'), null);
  assert.equal(urlMailto('sin-arroba'), null);
  assert.equal(urlMailto(null), null);
});

test('enlace de grupo de WhatsApp válido', () => {
  assert.equal(esEnlaceGrupoWhatsApp('https://chat.whatsapp.com/ABCDEFGHIJ1234567890'), true);
  assert.equal(esEnlaceGrupoWhatsApp('http://chat.whatsapp.com/ABCDEFGHIJ1234567890'), false);
  assert.equal(esEnlaceGrupoWhatsApp('https://evil.example/ABCDEFGHIJ1234567890'), false);
});
