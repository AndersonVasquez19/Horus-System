import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { FRASES, mensajeWhatsapp, ocultarEnlaceWhatsapp, urlWhatsappChat } from '../chat-whatsapp.ts';

const MATRICULA = 'Para matricularte sigue estos pasos:\n1. Elige el curso.';
const RECLAMO = 'Puedes registrar un reclamo o queja en el Libro de Reclamaciones virtual.';
const OTRA = 'Cursos y capacitaciones vigentes: Matemática.';

test('mensajeWhatsapp: matrícula con y sin curso', () => {
  assert.equal(mensajeWhatsapp(MATRICULA, null), 'Hola Horus Group, consulté el asistente web y deseo matricularme. ¿Me confirman vacante, tarifa e inicio?');
  assert.equal(
    mensajeWhatsapp(MATRICULA, 'Matemática'),
    'Hola Horus Group, consulté el asistente web y deseo matricularme en el curso «Matemática». ¿Me confirman vacante, tarifa e inicio?',
  );
});

test('mensajeWhatsapp: un reclamo nunca pide matrícula, aunque haya un curso en foco', () => {
  const esperado = 'Hola Horus Group, consulté el asistente web y necesito ayuda con un reclamo.';
  assert.equal(mensajeWhatsapp(RECLAMO, null), esperado);
  assert.equal(mensajeWhatsapp(RECLAMO, 'Matemática'), esperado);
});

test('mensajeWhatsapp: otra consulta sobre un curso y caso general', () => {
  assert.equal(mensajeWhatsapp(OTRA, 'Matemática'), 'Hola Horus Group, consulté el asistente web y deseo más información sobre el curso «Matemática».');
  assert.equal(mensajeWhatsapp(OTRA, null), 'Hola Horus Group, consulté el asistente web y deseo más información.');
});

test('urlWhatsappChat: número oficial y mensaje codificado', () => {
  const url = new URL(urlWhatsappChat(OTRA, 'A & B'));
  assert.equal(url.origin + url.pathname, 'https://wa.me/51972021374');
  assert.equal(url.searchParams.get('text'), mensajeWhatsapp(OTRA, 'A & B'));
});

test('las frases que eligen el mensaje son las mismas que usa Laravel para las etapas', () => {
  const laravel = readFileSync(new URL('../../../../api/app/Support/SenalesAsistente.php', import.meta.url), 'utf8');
  for (const frase of [...FRASES.matricula, ...FRASES.reclamos]) assert.ok(laravel.includes(`'${frase}'`), frase);
});

test('ocultarEnlaceWhatsapp: acorta el enlace y conserva la puntuación', () => {
  assert.equal(
    ocultarEnlaceWhatsapp('Escríbenos: https://wa.me/51972021374?text=Hola%2C%20deseo. Gracias'),
    'Escríbenos: enlace de WhatsApp. Gracias',
  );
  assert.equal(ocultarEnlaceWhatsapp('Oficial: https://wa.me/51972021374?text=Hola%2C%20matr%C3%…'), 'Oficial: enlace de WhatsApp…');
  assert.equal(ocultarEnlaceWhatsapp('Sin enlaces.'), 'Sin enlaces.');
});
