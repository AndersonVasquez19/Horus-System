import assert from 'node:assert/strict';
import { test } from 'node:test';
import { IMPORTE, MAX_CAPTURA, importeValido, normalizarImporte, tamanoLegible, validarCaptura } from '../captura.ts';

test('el importe acepta coma o punto decimal y recorta espacios', () => {
  assert.equal(normalizarImporte('25,50'), '25.50');
  assert.equal(normalizarImporte(' 25.50 '), '25.50');
  assert.equal(normalizarImporte('250'), '250');
  assert.equal(normalizarImporte(''), '');
});

test('importeValido: hasta 8 enteros y 2 decimales, mayor que cero', () => {
  for (const bueno of ['25,50', '25.5', '0.01', '99999999.99', '1']) assert.equal(importeValido(bueno), true, `«${bueno}»`);
  for (const malo of ['', '0', '0,00', '-5', '25,555', '100000000', 'abc', '1,250.50', '25,', ',5']) assert.equal(importeValido(malo), false, `«${malo}»`);
});

test('IMPORTE no admite ceros a la izquierda ni más de 8 enteros', () => {
  assert.equal(IMPORTE.test('007'), false);
  assert.equal(IMPORTE.test('0.5'), true);
  assert.equal(IMPORTE.test('123456789'), false);
});

test('validarCaptura: obligatorio por omisión, tipo permitido y tamaño máximo de 3 MB', () => {
  assert.equal(validarCaptura(null), 'Adjunta el comprobante de pago.');
  assert.equal(validarCaptura(null, false), null);
  assert.equal(validarCaptura({ type: 'image/png', size: 1000 }), null);
  assert.equal(validarCaptura({ type: 'application/pdf', size: MAX_CAPTURA }), null);
  assert.equal(validarCaptura({ type: 'application/pdf', size: MAX_CAPTURA + 1 }), 'El comprobante no puede pasar de 3 MB.');
  assert.equal(validarCaptura({ type: 'image/gif', size: 10 }), 'Sube una captura en JPG, PNG o WebP, o un PDF.');
  assert.equal(validarCaptura({ type: 'text/html', size: 10 }), 'Sube una captura en JPG, PNG o WebP, o un PDF.');
});

test('tamanoLegible', () => {
  assert.equal(tamanoLegible(100), '1 KB');
  assert.equal(tamanoLegible(820 * 1024), '820 KB');
  assert.equal(tamanoLegible(1.5 * 1024 * 1024), '1,5 MB');
});
