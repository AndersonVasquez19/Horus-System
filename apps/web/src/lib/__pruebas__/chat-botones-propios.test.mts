import assert from 'node:assert/strict';
import test from 'node:test';
import { botonesDeCursoNoPublicado } from '../chat-sugerencias.ts';
import { whatsappGuardado, WHATSAPP_BASE } from '../chat-whatsapp.ts';

test('tras «no tengo ese curso» se ofrece ver los cursos disponibles', () => {
  for (const apertura of ['No encontré', 'Por ahora no tengo']) {
    assert.deepEqual(
      botonesDeCursoNoPublicado(`${apertura} un curso o capacitación sobre ese tema. También puedes consultarlo con un asesor.`),
      [{ label: 'Ver cursos disponibles', query: '¿Qué cursos ofrecen?' }],
    );
  }
  assert.equal(botonesDeCursoNoPublicado('«Redes»: el horario es 2 pm a 5pm.'), null);
});

test('el botón de WhatsApp guardado se reconstruye con el número oficial y su mensaje', () => {
  const guardado = `${WHATSAPP_BASE}?text=${encodeURIComponent('Hola, deseo solicitar una cotización de cableado')}`;
  assert.equal(whatsappGuardado(['/educacion/cursos', guardado]), guardado);
});

test('de lo guardado no se acepta otro destino ni parámetros añadidos', () => {
  for (const url of [
    'https://wa.me/51999999999?text=hola',
    'https://wa.me/51972021374.sitio.example?text=hola',
    'https://sitio.example/https://wa.me/51972021374?text=hola',
    'javascript:alert(1)',
    `${WHATSAPP_BASE}?text=`,
    WHATSAPP_BASE,
    42,
    null,
  ]) {
    assert.equal(whatsappGuardado([url]), null, String(url));
  }
  assert.equal(whatsappGuardado([`${WHATSAPP_BASE}?text=hola&phone=51999999999#x`]), `${WHATSAPP_BASE}?text=hola`);
});

test('un mensaje guardado demasiado largo se recorta', () => {
  const url = whatsappGuardado([`${WHATSAPP_BASE}?text=${'a'.repeat(2000)}`]);
  assert.equal(new URL(url ?? '').searchParams.get('text')?.length, 400);
});
