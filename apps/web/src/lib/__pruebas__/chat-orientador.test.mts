import assert from 'node:assert/strict';
import { test } from 'node:test';
import { armarMenuOrientador, cursosValidos } from '../chat-orientador.ts';

const curso = (titulo: string, modalidad = 'virtual', hasta = '2026-12-22') => ({ titulo, modalidad, vigente_hasta: hasta });

test('sin cursos publicados: solo asesoramiento y WhatsApp, ningún curso inventado', () => {
  const menu = armarMenuOrientador([]);
  assert.equal(menu.conWhatsapp, true);
  assert.deepEqual(menu.opciones.map((o) => o.label), ['Asesoramiento académico y de tesis']);
  assert.match(menu.texto, /no hay cursos publicados/);
});

test('catálogo no consultable: lo dice, no lista cursos y ofrece WhatsApp', () => {
  const menu = armarMenuOrientador(null);
  assert.equal(menu.conWhatsapp, true);
  assert.match(menu.texto, /No pude consultar/);
  assert.ok(!menu.opciones.some((o) => /Atención al cliente|Marketing/.test(o.label)));
});

test('un curso: aparece con su modalidad real y sin «Comparar»', () => {
  const menu = armarMenuOrientador([curso('Comunicacion', 'presencial')]);
  assert.equal(menu.conWhatsapp, false);
  assert.deepEqual(menu.opciones.map((o) => o.label), ['Comunicacion (Presencial)', 'Asesoramiento académico y de tesis']);
  assert.equal(menu.opciones[0].query, 'Quiero información del curso Comunicacion');
});

test('la modalidad sale del dato, incluido híbrido; una modalidad desconocida no se inventa', () => {
  const menu = armarMenuOrientador([curso('A', 'hibrido'), curso('B', 'otra')]);
  assert.equal(menu.opciones[0].label, 'A (Híbrido)');
  assert.equal(menu.opciones[1].label, 'B');
});

test('más de cuatro cursos: muestra cuatro, los que vencen antes, y ofrece comparar todos', () => {
  const cursos = [
    curso('C1', 'virtual', '2027-01-10'), curso('C2', 'virtual', '2026-12-01'), curso('C3', 'virtual', '2026-12-05'),
    curso('C4', 'virtual', '2026-12-09'), curso('C5', 'virtual', '2026-12-02'), curso('C6', 'virtual', '2027-02-01'),
  ];
  const menu = armarMenuOrientador(cursos);
  assert.deepEqual(menu.opciones.slice(0, 4).map((o) => o.query.replace('Quiero información del curso ', '')), ['C2', 'C5', 'C3', 'C4']);
  assert.ok(menu.opciones.some((o) => o.label === 'Comparar todos los cursos y horarios'));
  assert.equal(menu.opciones.length, 6);
});

test('cursosValidos descarta entradas sin título, duplicados y datos que no son arreglo', () => {
  assert.deepEqual(cursosValidos('x'), []);
  assert.deepEqual(cursosValidos(null), []);
  const lista = cursosValidos([{ titulo: '  Redes ', modalidad: 'virtual' }, { titulo: 'redes' }, { titulo: '' }, 7, { modalidad: 'x' }]);
  assert.equal(lista.length, 1);
  assert.equal(lista[0].titulo, 'Redes');
});
