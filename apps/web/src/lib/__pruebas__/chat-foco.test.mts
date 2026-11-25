import assert from 'node:assert/strict';
import { test } from 'node:test';
import { resolverFoco } from '../chat-foco.ts';
import { mensajeWhatsapp } from '../chat-whatsapp.ts';

const CONOCIDOS = ['Comunicación', 'Matemática', 'Ciencias Sociales'];
const resolver = (cambios: Partial<Parameters<typeof resolverFoco>[0]>) =>
  resolverFoco({ respuesta: 'Con gusto.', pregunta: 'hola', foco: 'Matemática', conocidos: CONOCIDOS, tarjetas: undefined, ...cambios });

test('barra en Matemática y respuesta de Comunicación: cambian la barra y el mensaje de WhatsApp', () => {
  const respuesta = '«Comunicación»: inicia el 30 de noviembre de 2026.';
  const r = resolver({ pregunta: '¿Cuál es el horario?', respuesta });
  assert.deepEqual(r, { curso: 'Comunicación', foco: 'Comunicación' });
  const mensaje = mensajeWhatsapp(respuesta, r.curso);
  assert.ok(mensaje.includes('«Comunicación»') && !mensaje.includes('Matemática'), mensaje);
});

test('reconoce la ficha, la matrícula de un curso y la tarjeta única', () => {
  for (const respuesta of [
    'Ciencias Sociales: Analiza el pasado. Modalidad: presencial.',
    'Para el curso «Ciencias Sociales» (modalidad presencial): continúa por WhatsApp.',
  ]) assert.equal(resolver({ respuesta }).curso, 'Ciencias Sociales', respuesta);
  assert.equal(resolver({ respuesta: 'Te muestro la opción.', tarjetas: [{ tipo: 'curso', titulo: 'Comunicación' }] }).curso, 'Comunicación');
});

test('sin barra activa, la respuesta nombra el curso para WhatsApp pero no crea la barra', () => {
  assert.deepEqual(resolver({ foco: null, respuesta: '«Comunicación»: es presencial.' }), { curso: 'Comunicación', foco: null });
});

test('varios cursos u otro asunto que no se reconoce: sin barra y sin nombrar ninguno', () => {
  const nada = { curso: null, foco: null };
  assert.deepEqual(resolver({ respuesta: '«Matemática»: dura 8 semanas.\n«Comunicación»: dura 2 semanas.' }), nada);
  assert.deepEqual(resolver({ respuesta: 'Cursos y capacitaciones vigentes: Comunicación; Matemática; Ciencias Sociales.' }), nada);
  assert.deepEqual(resolver({ respuesta: 'Te muestro.', tarjetas: CONOCIDOS.map((titulo) => ({ tipo: 'curso', titulo })) }), nada);
  assert.deepEqual(resolver({ respuesta: '«Curso retirado»: no está vigente.' }), nada);
});

test('si la respuesta no nombra cursos, vale la pregunta que dice «curso X», con el nombre oficial', () => {
  const r = resolver({ pregunta: 'quiero matricularme en el CURSO de comunicacion', respuesta: 'Un asesor coordinará tu matrícula.' });
  assert.deepEqual(r, { curso: 'Comunicación', foco: 'Comunicación' });
});

test('una mención suelta de otro curso en la pregunta es una duda: se quita el foco', () => {
  assert.deepEqual(resolver({ pregunta: 'quiero mejorar mi comunicación' }), { curso: null, foco: null });
});

test('sin señales se mantiene el curso actual', () => {
  assert.deepEqual(resolver({ pregunta: '¿Dónde están?', respuesta: 'Estamos en Cajamarca.' }), { curso: 'Matemática', foco: 'Matemática' });
  assert.deepEqual(resolver({ pregunta: 'más de matemática' }), { curso: 'Matemática', foco: 'Matemática' });
  assert.deepEqual(resolver({ foco: null }), { curso: null, foco: null });
});

const GENERAL = 'Para consultar la matrícula, continúa por el WhatsApp oficial de Horus Group.';

test('preguntar por un curso que no está publicado quita el curso anterior', () => {
  const nada = { curso: null, foco: null };
  for (const pregunta of [
    'quiero matricularme en el curso Excel avanzado', 'quiero inscribirme a robótica', 'como me inscribo en Python',
    'matrícula para cocina', '¿tienen el curso de contabilidad?', 'me interesa el taller de oratoria',
  ]) assert.deepEqual(resolver({ pregunta, respuesta: GENERAL }), nada, pregunta);
});

test('si el asistente dice que no tiene ese curso, no se conserva ninguno', () => {
  for (const inicio of ['No encontré', 'Por ahora no tengo']) {
    assert.deepEqual(resolver({ pregunta: 'horario de inglés', respuesta: `${inicio} un curso o capacitación sobre ese tema.` }),
      { curso: null, foco: null });
  }
});

test('pedir la matrícula del curso en foco lo conserva', () => {
  const igual = { curso: 'Matemática', foco: 'Matemática' };
  for (const pregunta of [
    'quiero matricularme', 'Quiero matricularme en este curso', '¿Cómo puedo matricularme?', 'como me matriculo', 'matrícula',
    'quiero inscribirme en matemática por favor', 'Quiero matricularme en el curso Matemática', '¿cuánto dura el curso?',
    '¿este curso es virtual?', '¿el curso de este mes tiene certificado?',
  ]) assert.deepEqual(resolver({ pregunta, respuesta: GENERAL }), igual, pregunta);
});
