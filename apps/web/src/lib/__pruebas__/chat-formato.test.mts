import assert from 'node:assert/strict';
import { test } from 'node:test';
import { colapsarPuntos, imagenRestaurable, restaurarRespuestasRapidas, separarEtiquetas } from '../chat-formato.ts';

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

test('colapsarPuntos: quita el punto repetido y respeta los puntos suspensivos', () => {
  assert.equal(colapsarPuntos('de 2:00 p. m. a 5:00 p. m.. Aviso: digital..'), 'de 2:00 p. m. a 5:00 p. m. Aviso: digital.');
  assert.equal(colapsarPuntos('Espera... listo.'), 'Espera... listo.');
  assert.equal(separarEtiquetas('Horario: 5 p. m.. Modalidad: virtual.'), 'Horario: 5 p. m. Modalidad: virtual.');
});

test('imagenRestaurable: solo portadas de cursos por https con nombre válido', () => {
  const buena = 'https://api.ejemplo.pe/storage/cursos/037d4074-1111-2222-3333-444455556666.png';
  assert.equal(imagenRestaurable(buena), buena);
  for (const mala of [
    'http://api.ejemplo.pe/storage/cursos/037d4074-1111-2222-3333-444455556666.png', `${buena}?x=1`,
    'https://api.ejemplo.pe/storage/otros/037d4074-1111-2222-3333-444455556666.png', 'https://x.pe/storage/cursos/a.svg',
    'javascript:alert(1)', null, 7,
  ]) assert.equal(imagenRestaurable(mala), null, String(mala));
});
