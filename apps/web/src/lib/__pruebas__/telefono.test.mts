import { test } from 'node:test';
import assert from 'node:assert/strict';
import { filtrarTelefono, normalizarTelefono } from '../telefono.ts';

test('acepta nueve dígitos con o sin espacios, guiones y prefijo +51', () => {
  for (const escrito of ['976543210', '976 543 210', '976-543-210', '+51 976 543 210', '+51976543210']) {
    assert.equal(normalizarTelefono(escrito), '976543210', escrito);
  }
});

test('nunca recorta un número largo ni corto para hacerlo pasar', () => {
  for (const escrito of ['519765432101', '51976543210', '+1 976 543 210 0', '+54 976543210', '97654321', '9765432100', '', '+51']) {
    assert.equal(normalizarTelefono(escrito), null, escrito);
  }
});

test('al escribir solo se quitan los caracteres que no son de un teléfono, sin recortar', () => {
  assert.equal(filtrarTelefono('+51 976-543-210'), '+51 976-543-210');
  assert.equal(filtrarTelefono('97a6(5)'), '9765');
  assert.equal(filtrarTelefono('9765432100'), '9765432100');
});
