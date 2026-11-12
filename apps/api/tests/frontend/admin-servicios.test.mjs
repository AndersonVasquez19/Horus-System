import test from 'node:test';
import assert from 'node:assert/strict';
import { panelServicios, textoServicio } from '../../resources/js/admin-servicios.mjs';

const detalle = (revision = 1) => ({ servicio: { id: 1, codigo: 'TEC', nombre: 'Interno', categoria: null, estado: 'activo' }, publicacion: null, estado_editorial: 'sin_ficha', revision });
const respuesta = (status, data, revision = 1) => ({ ok: status < 400, status, headers: { get: () => `"servicio-1-v${revision}"` }, json: async () => data });
const crear = (fetcher) => panelServicios({ endpoint: '/admin/servicios', csrf: 'ficticio', fetcher });

test('creation sends only administrative fields and CSRF, never editorial authorization', async () => {
  let sent;
  const panel = crear(async (url, options) => { sent = { url, ...options }; return respuesta(201, detalle()); });
  panel.nuevo();
  panel.estado.admin = { codigo: 'TEC', nombre: 'Interno', categoria: 'TI', autorizado_por: 999 };
  await panel.guardar('crear');
  assert.equal(sent.url, '/admin/servicios');
  assert.equal(sent.headers['X-CSRF-TOKEN'], 'ficticio');
  assert.deepEqual(JSON.parse(sent.body), { codigo: 'TEC', nombre: 'Interno', categoria: 'TI', estado: 'inactivo' });
  assert.equal(panel.estado.detalle.servicio.id, 1);
  assert.equal(panel.estado.listadoPendiente, true);
});

test('names and codes remain literal, including state words and inherited object keys', () => {
  for (const valor of ['activo', 'constructor', 'toString', '__proto__']) {
    assert.equal(textoServicio('nombre', valor), valor);
    assert.equal(textoServicio('codigo', valor), valor);
  }
  assert.equal(textoServicio('estado', 'activo'), 'Activo');
  assert.equal(textoServicio('estado', 'constructor'), 'constructor');
});

test('a failed GET remains retryable and never creates uncertain-write state', async () => {
  let calls = 0;
  const panel = crear(async () => ++calls === 1 ? respuesta(503, {}) : respuesta(200, detalle()));
  await panel.abrir(1);
  assert.equal(panel.estado.bloqueo, '');
  await panel.abrir(1);
  assert.equal(panel.estado.detalle.servicio.id, 1);
  assert.equal(calls, 2);
});

test('a network failure on GET clears loading state and preserves the draft', async () => {
  const panel = crear(async () => { throw new Error('offline'); });
  panel.nuevo(); panel.estado.admin.nombre = 'Mi borrador';
  await panel.abrir(1);
  assert.equal(panel.estado.mensaje, '');
  assert.equal(panel.estado.ocupado, false);
  assert.equal(panel.estado.bloqueo, '');
  assert.equal(panel.estado.admin.nombre, 'Mi borrador');
});

test('422 preserves draft; double submit issues one request and carries If-Match', async () => {
  let resolve;
  let calls = 0;
  const panel = crear(async (url, options) => {
    calls++;
    if (!options.method) return respuesta(200, detalle());
    assert.equal(options.headers['If-Match'], '"servicio-1-v1"');
    return new Promise(done => { resolve = done; });
  });
  await panel.abrir(1);
  panel.estado.admin.nombre = 'Mi texto';
  const escritura = panel.guardar('administrativo');
  await panel.guardar('administrativo');
  resolve(respuesta(422, { errors: { nombre: ['Revisa el nombre.'] } }));
  await escritura;
  assert.equal(calls, 2);
  assert.equal(panel.estado.admin.nombre, 'Mi texto');
  assert.deepEqual(panel.estado.errores, ['Revisa el nombre.']);
  assert.equal(panel.estado.etag, '"servicio-1-v1"');
});

test('412 retains draft and old ETag; comparison requires explicit adoption and never writes', async () => {
  const methods = [];
  const panel = crear(async (url, options) => {
    methods.push(options.method || 'GET');
    if (options.method) return respuesta(412, {});
    return respuesta(200, detalle(methods.length === 1 ? 1 : 2), methods.length === 1 ? 1 : 2);
  });
  await panel.abrir(1);
  panel.estado.admin.nombre = 'Mi borrador';
  await panel.guardar('administrativo');
  assert.equal(panel.estado.bloqueo, 'conflicto');
  await panel.guardar('administrativo');
  await panel.comparar();
  assert.equal(panel.estado.etag, '"servicio-1-v1"');
  assert.equal(panel.estado.admin.nombre, 'Mi borrador');
  panel.aceptarVersion();
  assert.equal(panel.estado.etag, '"servicio-1-v2"');
  assert.equal(panel.estado.admin.nombre, 'Mi borrador');
  assert.deepEqual(methods, ['GET', 'PUT', 'GET']);
});

test('network failure or 500 blocks repeated writes without discarding draft', async () => {
  for (const fail of [() => { throw new Error('offline'); }, () => respuesta(500, {})]) {
    let writes = 0;
    const panel = crear(async (url, options) => {
      if (!options.method) return respuesta(200, detalle());
      writes++;
      return fail();
    });
    await panel.abrir(1);
    panel.estado.admin.nombre = 'Conservar';
    await panel.guardar('administrativo');
    await panel.guardar('administrativo');
    assert.equal(panel.estado.bloqueo, 'incierto');
    assert.equal(panel.estado.admin.nombre, 'Conservar');
    assert.equal(writes, 1);
  }
});

test('publication cannot authorize unsaved text and retirement keeps unsaved editorial draft', async () => {
  const writes = [];
  const publicacion = { titulo: 'Guardado', resumen: 'Resumen', descripcion: 'Detalle', categoria: null, vigente_desde: '2026-10-29', vigente_hasta: '2026-11-30', estado: 'publicada' };
  const panel = crear(async (url, options) => {
    if (options.method) writes.push(options.method);
    return respuesta(200, { ...detalle(), publicacion: { ...publicacion, estado: options.method ? 'retirada' : 'publicada' } }, options.method ? 2 : 1);
  });
  await panel.abrir(1);
  panel.estado.editorial.titulo = 'No guardado';
  await panel.guardar('autorizar');
  assert.equal(writes.length, 0);
  await panel.guardar('retirar');
  assert.deepEqual(writes, ['DELETE']);
  assert.equal(panel.estado.editorial.titulo, 'No guardado');
});

for (const status of [401, 403, 419]) {
  test(`HTTP ${status} blocks access without automatic retry`, async () => {
    let calls = 0;
    const panel = crear(async () => { calls++; return respuesta(status, {}); });
    panel.nuevo(); panel.estado.admin.nombre = 'Borrador';
    await panel.guardar('crear'); await panel.guardar('crear');
    assert.equal(calls, 1); assert.equal(panel.estado.admin.nombre, 'Borrador'); assert.equal(panel.estado.bloqueo, 'acceso');
  });
}
