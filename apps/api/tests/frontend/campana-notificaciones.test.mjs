import assert from 'node:assert/strict';
import test from 'node:test';
import { campanaNotificaciones, etiquetaCampana, textoContador } from '../../resources/js/campana-notificaciones.mjs';

const documentoConCsrf = { querySelector: (s) => (s === 'meta[name="csrf-token"]' ? { getAttribute: () => 'token-falso' } : null) };

function montar(fetch, pendientes = '3') {
  const llamadas = [];
  const boton = { enfocado: 0, focus() { this.enfocado += 1; } };
  const componente = campanaNotificaciones({
    fetch: async (url, opciones) => { llamadas.push({ url, opciones }); return fetch(url, opciones); },
    document: documentoConCsrf,
  });
  Object.assign(componente, {
    $el: { dataset: { pendientes, urlRecientes: '/admin/notificaciones/recientes', urlLeidas: '/admin/notificaciones/leidas' } },
    $refs: { boton },
  });
  componente.init();
  return { componente, llamadas, boton };
}

const json = (datos, status = 200) => ({ ok: status >= 200 && status < 300, status, json: async () => datos });

test('contador y etiqueta accesible', () => {
  assert.equal(textoContador(0), '0');
  assert.equal(textoContador(7), '7');
  assert.equal(textoContador(150), '99+');
  assert.equal(etiquetaCampana(0), 'Notificaciones, ninguna sin leer');
  assert.equal(etiquetaCampana(1), 'Notificaciones, 1 sin leer');
  assert.equal(etiquetaCampana(4), 'Notificaciones, 4 sin leer');
});

test('toma el contador inicial del servidor y carga las recientes solo al abrir', async () => {
  const { componente, llamadas } = montar(() => json({ pendientes: 2, notificaciones: [{ id: 1, titulo: 'A' }, { id: 2, titulo: 'B' }] }));
  assert.equal(componente.pendientes, 3);
  assert.equal(llamadas.length, 0);

  await componente.alternar();
  assert.equal(componente.abierto, true);
  assert.equal(llamadas.length, 1);
  assert.equal(llamadas[0].url, '/admin/notificaciones/recientes');
  assert.equal(llamadas[0].opciones.credentials, 'same-origin');
  assert.equal(llamadas[0].opciones.headers.Accept, 'application/json');
  assert.equal(componente.items.length, 2);
  assert.equal(componente.pendientes, 2);
  assert.equal(componente.cargado, true);
  assert.equal(componente.error, '');
});

test('un fallo al cargar muestra un error y deja de cargar', async () => {
  const { componente } = montar(() => json({}, 500));
  await componente.abrir();
  assert.match(componente.error, /No se pudieron cargar/);
  assert.equal(componente.cargando, false);
  assert.deepEqual(componente.items, []);
});

test('una respuesta de red rechazada también se informa', async () => {
  const { componente } = montar(() => { throw new TypeError('red'); });
  await componente.abrir();
  assert.match(componente.error, /No se pudieron cargar/);
});

test('marcar todas envía PATCH con CSRF, vacía la lista y anuncia el resultado', async () => {
  const { componente, llamadas } = montar(() => json({ marcadas: 3, pendientes: 0 }));
  componente.items = [{ id: 1 }];
  await componente.marcarTodas();
  const [{ url, opciones }] = llamadas;
  assert.equal(url, '/admin/notificaciones/leidas');
  assert.equal(opciones.method, 'PATCH');
  assert.equal(opciones.headers['X-CSRF-TOKEN'], 'token-falso');
  assert.equal(opciones.headers.Accept, 'application/json');
  assert.equal(componente.pendientes, 0);
  assert.deepEqual(componente.items, []);
  assert.match(componente.anuncio, /marcaron como leídas/);
  assert.equal(componente.ocupado, false);
});

test('si marcar todas falla (p. ej. sesión expirada) conserva la lista y el contador', async () => {
  const { componente } = montar(() => json({}, 419));
  componente.items = [{ id: 1 }];
  await componente.marcarTodas();
  assert.equal(componente.pendientes, 3);
  assert.equal(componente.items.length, 1);
  assert.match(componente.error, /No se pudieron marcar/);
});

test('cerrar con Escape devuelve el foco al botón; con clic fuera no', async () => {
  const { componente, boton } = montar(() => json({ pendientes: 0, notificaciones: [] }));
  await componente.abrir();
  componente.cerrar(false);
  assert.equal(boton.enfocado, 0);
  await componente.abrir();
  componente.cerrar(true);
  assert.equal(componente.abierto, false);
  assert.equal(boton.enfocado, 1);
  componente.cerrar(true); // ya cerrada
  assert.equal(boton.enfocado, 1);
});
