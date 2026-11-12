import test from 'node:test';
import assert from 'node:assert/strict';
import { panelCotizaciones } from '../../resources/js/admin-cotizaciones.mjs';

const id = '11111111-1111-4111-8111-111111111111';
const clave = '22222222-2222-4222-8222-222222222222';
const etag = n => `"cotizacion-${id}-v${n}"`;
const detalle = (revision = 1) => ({ identificador: id, codigo: 'SOL-FICTICIO', revision, estado: 'recibida', contacto_nombre: 'Persona ficticia', contacto_email: 'persona@example.test', contacto_telefono: null, necesidad_cliente: 'Necesidad ficticia', servicio_uuid: null, responsable_id: null, proximo_seguimiento: null });
const respuesta = (datos, status = 200, version = 1) => new Response(JSON.stringify(datos), { status, headers: { ETag: etag(version), 'Content-Type': 'application/json' } });
function fixture(escribir = () => respuesta({ solicitud: { identificador: id, codigo: 'SOL-FICTICIO' } }, 200, 2), permisos = { crear: true, editar: true, gestionar: true }) {
  const llamadas = []; let revision = 1;
  const panel = panelCotizaciones({ endpoint: '/admin/cotizaciones', csrf: 'ficticio', permisos, uuid: () => clave,
    fetcher: async (url, opciones) => {
      llamadas.push({ url, ...opciones });
      if (opciones.method) return escribir(url, opciones);
      if (url.includes('/historial')) return respuesta({ data: [], total: 0, current_page: 1, last_page: 1 });
      return respuesta({ solicitud: detalle(revision) }, 200, revision);
    } });
  return { panel, llamadas, version: n => { revision = n; } };
}
test('envía CSRF, If-Match y un cuerpo permitido sin conceder lectura al recibo', async () => {
  const { panel, llamadas } = fixture(); await panel.abrir(id); panel.estado.borrador.necesidad_cliente = 'Borrador';
  await panel.guardar('informacion');
  const escritura = llamadas.find(c => c.method);
  assert.equal(escritura.headers['If-Match'], etag(1)); assert.equal(escritura.headers['X-CSRF-TOKEN'], 'ficticio');
  assert.equal(JSON.parse(escritura.body).necesidad_cliente, 'Borrador'); assert.equal(escritura.redirect, 'error');
  assert.equal(panel.estado.borrador.necesidad_cliente, 'Borrador');
});
test('412 conserva borrador y revisión hasta comparación y adopción explícitas', async () => {
  const { panel, version } = fixture(() => respuesta({}, 412)); await panel.abrir(id); panel.estado.borrador.contacto_nombre = 'Borrador';
  await panel.guardar('informacion'); assert.equal(panel.estado.etag, etag(1)); assert.equal(panel.estado.bloqueo, 'conflicto');
  version(2); await panel.comparar(); assert.equal(panel.estado.etag, etag(1)); assert.equal(panel.estado.borrador.contacto_nombre, 'Borrador');
  panel.adoptar(); assert.equal(panel.estado.etag, etag(2)); assert.equal(panel.estado.borrador.contacto_nombre, 'Borrador');
});
test('creación incierta bloquea reenvío y concilia misma clave y cuerpo aunque cambie el borrador', async () => {
  let intentos = 0;
  const { panel, llamadas } = fixture(() => { if (++intentos === 1) throw new Error('timeout'); return respuesta({ solicitud: { identificador: id, codigo: 'SOL-FICTICIO' } }); });
  panel.nuevo(); panel.estado.borrador = { ...detalle(), servicio_uuid: '' };
  await panel.guardar('informacion'); assert.equal(panel.estado.bloqueo, 'incierto');
  panel.estado.borrador.necesidad_cliente = 'Otro texto'; assert.equal(await panel.guardar('informacion'), false);
  assert.equal(panel.nuevo(), false); await panel.conciliar();
  const writes = llamadas.filter(c => c.method); assert.equal(writes.length, 2);
  assert.equal(writes[0].body, writes[1].body); assert.equal(writes[0].headers['Idempotency-Key'], writes[1].headers['Idempotency-Key']);
});
test('seguimiento incierto con 412 nunca cambia la clave por adoptar una revisión', async () => {
  let n = 0;
  const { panel, version, llamadas } = fixture(() => { if (++n === 1) throw new Error('timeout'); return respuesta({}, 412); });
  await panel.abrir(id); panel.estado.nota = 'Nota ficticia'; await panel.guardar('seguimiento'); await panel.conciliar();
  version(2); await panel.comparar(); panel.adoptar(); assert.equal(panel.estado.bloqueo, 'incierto');
  assert.equal(await panel.guardar('seguimiento'), false); await panel.conciliar();
  const writes = llamadas.filter(c => c.method); assert.equal(writes[2].headers['If-Match'], etag(2));
  assert.equal(writes[0].headers['Idempotency-Key'], writes[2].headers['Idempotency-Key']); assert.equal(writes[0].body, writes[2].body);
});
test('mutación sin clave incierta exige revisar y confirmar sin reenviar', async () => {
  const { panel, llamadas, version } = fixture(() => { throw new Error('timeout'); }); await panel.abrir(id);
  panel.estado.borrador.contacto_nombre = 'Borrador'; await panel.guardar('informacion'); assert.equal(await panel.conciliar(), false);
  version(2); await panel.comparar(); assert.equal(panel.revisado(), true); assert.equal(panel.estado.borrador.contacto_nombre, 'Borrador');
  assert.equal(llamadas.filter(c => c.method).length, 1);
});
for (const status of [401, 403, 419, 404, 409, 422, 428, 500]) test(`rechazo ${status} conserva borradores`, async () => {
  const { panel } = fixture(() => respuesta({}, status)); await panel.abrir(id); panel.estado.nota = 'Nota privada';
  await panel.guardar('seguimiento'); assert.equal(panel.estado.nota, 'Nota privada'); assert.ok(panel.estado.errores.length);
});
test('respuesta 2xx ilegible es incierta y no se reintenta automáticamente', async () => {
  const { panel, llamadas } = fixture(() => new Response('<html>', { status: 200 })); await panel.abrir(id); await panel.guardar('estado');
  assert.equal(panel.estado.bloqueo, 'incierto'); assert.equal(llamadas.filter(c => c.method).length, 1);
});
test('lector no puede invocar escrituras aunque manipule el cliente', async () => {
  const { panel, llamadas } = fixture(undefined, {}); await panel.abrir(id);
  assert.equal(panel.nuevo(), false); for (const a of ['informacion', 'estado', 'responsable', 'seguimiento']) assert.equal(await panel.guardar(a), false);
  assert.equal(llamadas.filter(c => c.method).length, 0);
});
test('doble envío durante petición no crea una segunda intención', async () => {
  let resolver; const { panel, llamadas } = fixture(() => new Promise(r => { resolver = r; })); await panel.abrir(id);
  const primero = panel.guardar('seguimiento'); assert.equal(await panel.guardar('seguimiento'), false);
  resolver(respuesta({ solicitud: { identificador: id, codigo: 'SOL-FICTICIO' } })); await primero;
  assert.equal(llamadas.filter(c => c.method).length, 1);
});
test('422 al conciliar una intención incierta no libera su clave ni permite otra escritura', async () => {
  let n = 0;
  const { panel } = fixture(() => { if (++n === 1) throw new Error('timeout'); return respuesta({}, 422); });
  await panel.abrir(id); panel.estado.nota = 'Nota ficticia'; await panel.guardar('seguimiento');
  await panel.conciliar(); assert.equal(panel.estado.bloqueo, 'incierto'); assert.equal(panel.estado.intencion.clave, clave);
  assert.equal(await panel.guardar('seguimiento'), false);
});
test('fallo de lectura se puede volver a consultar sin crear incertidumbre de escritura', async () => {
  let n = 0;
  const panel = panelCotizaciones({ endpoint: '/admin/cotizaciones', csrf: '', permisos: {}, fetcher: async url => {
    if (++n === 1) throw new Error('offline');
    return url.includes('historial') ? respuesta({ data: [] }) : respuesta({ solicitud: detalle() });
  } });
  assert.equal(await panel.abrir(id), false); assert.equal(panel.estado.bloqueo, ''); assert.equal(await panel.abrir(id), true);
});
