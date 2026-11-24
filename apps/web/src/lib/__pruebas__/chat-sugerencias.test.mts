import assert from 'node:assert/strict';
import { test } from 'node:test';
import { botonesDeSugerencias, CLAVES_SUGERENCIA, sanearSugerencias } from '../chat-sugerencias.ts';

test('sanearSugerencias: acepta listas cerradas de 1 a 4 claves sin repetidos', () => {
  assert.deepEqual(sanearSugerencias(['schedule', 'modality', 'enrollment']), ['schedule', 'modality', 'enrollment']);
  assert.deepEqual(sanearSugerencias(['compare']), ['compare']);
  assert.deepEqual(sanearSugerencias(['schedule', 'modality', 'enrollment', 'compare']).length, 4);
});

test('sanearSugerencias: descarta entera cualquier lista dudosa, sin corregirla', () => {
  for (const mala of [
    ['schedule', 'hackear'], ['schedule', 'schedule'], ['schedule', 'modality', 'enrollment', 'details', 'compare'],
    [], 'schedule', null, undefined, { a: 'schedule' }, [1], ['Ver el horario del curso'], ['schedule', null],
  ]) {
    assert.deepEqual(sanearSugerencias(mala), [], JSON.stringify(mala));
  }
});

test('botonesDeSugerencias: cada clave tiene etiqueta y consulta sin título de curso, y devuelve copias', () => {
  const botones = botonesDeSugerencias(CLAVES_SUGERENCIA);
  assert.equal(botones.length, CLAVES_SUGERENCIA.length);
  for (const b of botones) {
    assert.ok(b.label.length > 0 && b.label.length <= 30 && b.query.length > 0 && b.query.length <= 80);
    assert.doesNotMatch(b.query, /curso [A-ZÁÉÍÓÚ]/);
  }
  botones[0].label = 'cambiado';
  assert.notEqual(botonesDeSugerencias(['schedule'])[0].label, 'cambiado');
});
