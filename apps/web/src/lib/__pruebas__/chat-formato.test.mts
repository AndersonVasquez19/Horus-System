import assert from 'node:assert/strict';
import { test } from 'node:test';
import { restaurarRespuestasRapidas, separarEtiquetas } from '../chat-formato.ts';

test('separarEtiquetas: añade el punto que falta antes de la primera etiqueta', () => {
  assert.equal(
    separarEtiquetas('Comunicacion: Se centra en cómo las personas se comunican y lograr objetivos Modalidad: presencial. Duración: 2 semanas.'),
    'Comunicacion: Se centra en cómo las personas se comunican y lograr objetivos. Modalidad: presencial. Duración: 2 semanas.',
  );
});

test('separarEtiquetas: no duplica puntuación ni toca textos sin etiquetas', () => {
  for (const ya of ['Resumen. Modalidad: virtual.', 'Resumen: Modalidad: virtual.', '¿Dudas? Horario: lunes.', 'Texto sin datos']) {
    assert.equal(separarEtiquetas(ya), ya);
  }
});

test('separarEtiquetas: varias etiquetas pegadas', () => {
  assert.equal(separarEtiquetas('Curso básico Horario: lunes Certificación: digital'), 'Curso básico. Horario: lunes. Certificación: digital');
});

test('restaurarRespuestasRapidas: conserva botones válidos y acota los textos', () => {
  const salida = restaurarRespuestasRapidas([
    { label: '  Ver horario ', query: '¿Cuál es el horario?' },
    { label: 'x'.repeat(200), query: 'y'.repeat(900) },
    { label: '', query: 'sin etiqueta' },
    { label: 'sin consulta', query: '' },
    { label: 3, query: 'tipo malo' },
    null,
  ]);
  assert.equal(salida.length, 2);
  assert.deepEqual(salida[0], { label: 'Ver horario', query: '¿Cuál es el horario?' });
  assert.equal(salida[1].label.length, 80);
  assert.equal(salida[1].query.length, 300);
});

test('restaurarRespuestasRapidas: lo que no es un arreglo da vacío y hay un máximo', () => {
  assert.deepEqual(restaurarRespuestasRapidas('x'), []);
  assert.deepEqual(restaurarRespuestasRapidas(undefined), []);
  assert.equal(restaurarRespuestasRapidas(Array.from({ length: 20 }, (_, i) => ({ label: `b${i}`, query: `q${i}` }))).length, 6);
});
