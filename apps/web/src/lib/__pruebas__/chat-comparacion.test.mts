import assert from 'node:assert/strict';
import { test } from 'node:test';
import { compararCursos } from '../chat-comparacion.ts';

// Formato real del motor (listado detallado con el catálogo de prueba).
const OFIMATICA =
  'Ofimática para oficinas: Aprende Excel y Word para el trabajo diario. Modalidad: virtual. Duración: 8 semanas. Inicio: 5 de diciembre de 2026. Horario: sábados de 9 a 12. Certificación: Certificado digital.';
const REDES =
  'Redes para principiantes: Conoce redes y cableado desde cero. Modalidad: presencial. Duración: 4 semanas. Inicio: 5 de Diciembre de 2026. Horario: 2:00 p. m. a 5:00 p. m.. Certificación: certificado digital';
const tarjeta = (titulo: string) => ({ tipo: 'curso', titulo });
const TARJETAS = [tarjeta('Ofimática para oficinas'), tarjeta('Redes para principiantes')];

test('separa lo común de lo distinto, sin contar mayúsculas, tildes ni el punto final', () => {
  const r = compararCursos(`${OFIMATICA}\n${REDES}`, TARJETAS);
  assert.ok(r);
  assert.deepEqual(r.comunes, [
    { etiqueta: 'Inicio', valor: '5 de diciembre de 2026' },
    { etiqueta: 'Certificación', valor: 'Certificado digital' },
  ]);
  assert.deepEqual(r.cursos.map((c) => c.titulo), ['Ofimática para oficinas', 'Redes para principiantes']);
  assert.deepEqual(r.cursos[1].diferencias, [
    { etiqueta: 'Modalidad', valor: 'presencial' },
    { etiqueta: 'Duración', valor: '4 semanas' },
    { etiqueta: 'Horario', valor: '2:00 p. m. a 5:00 p. m.' },
  ]);
});

test('un dato que falta en un curso no es común y se marca como ausente', () => {
  const sinHorario = REDES.replace(' Horario: 2:00 p. m. a 5:00 p. m..', '');
  const r = compararCursos(`${OFIMATICA}\n${sinHorario}`, TARJETAS);
  assert.ok(r);
  assert.deepEqual(r.cursos[1].diferencias.find((d) => d.etiqueta === 'Horario'), { etiqueta: 'Horario', valor: null });
});

test('una falta de ortografía cuenta como diferencia', () => {
  const r = compararCursos(`${OFIMATICA}\n${REDES.replace('certificado digital', 'Certifificado digital')}`, TARJETAS);
  assert.ok(r && r.cursos[1].diferencias.some((d) => d.etiqueta === 'Certificación'));
});

test('ante cualquier duda devuelve null y el chat muestra el texto de siempre', () => {
  const casos: [string, { tipo: string; titulo: string }[] | undefined][] = [
    [`${OFIMATICA}\n${REDES}`, undefined],
    [OFIMATICA, [TARJETAS[0]]],
    // Una línea que no es una ficha: reemplazar el texto la descartaría.
    [`Estas son las opciones.\n${OFIMATICA}\n${REDES}`, TARJETAS],
    // Una tarjeta sin su ficha.
    [`${OFIMATICA}\n${OFIMATICA}`, TARJETAS],
    // Una ficha sin datos reconocibles.
    [`${OFIMATICA}\nRedes para principiantes: Conoce redes desde cero.`, TARJETAS],
    // Un dato repetido: no se sabe cuál es el bueno.
    [`${OFIMATICA}\n${REDES} Horario: otro.`, TARJETAS],
    [`${OFIMATICA}\n${REDES}`, [TARJETAS[0], { tipo: 'servicio', titulo: 'Redes para principiantes' }]],
  ];
  for (const [texto, tarjetas] of casos) assert.equal(compararCursos(texto, tarjetas), null, texto.slice(0, 60));
});
