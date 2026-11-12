import assert from 'node:assert/strict';
import test from 'node:test';
import { RETARDO_MS, selectorAsincrono } from '../../resources/js/selector-asincrono.mjs';

const json = (datos, status = 200) => ({ ok: status >= 200 && status < 300, status, json: async () => datos });
const opcion = (id, texto = `Opción ${id}`) => ({ id, texto, eliminado: false });

function montar({ responder = () => json({ opciones: [] }), dataset = {} } = {}) {
  const llamadas = [];
  const temporizadores = new Map();
  let siguiente = 1;
  const contiene = { valor: false };
  const componente = selectorAsincrono({
    fetch: async (url, opciones) => { llamadas.push({ url, opciones }); return responder(url, opciones); },
    setTimeout: (funcion, ms) => { const id = siguiente++; temporizadores.set(id, { funcion, ms }); return id; },
    clearTimeout: (id) => { temporizadores.delete(id); },
    document: { getElementById: () => ({ scrollIntoView() {} }) },
  });
  Object.assign(componente, {
    $el: {
      dataset: { id: 'estudiante_id', url: '/admin/matriculas/opciones/estudiantes', contexto: 'crear', valor: '', texto: '', ...dataset },
      contains: () => contiene.valor,
    },
    $nextTick: (funcion) => funcion(),
  });
  componente.init();
  const vencer = async () => {
    const pendientes = [...temporizadores.values()];
    temporizadores.clear();
    for (const { funcion } of pendientes) await funcion();
  };
  return { componente, llamadas, temporizadores, vencer, contiene };
}

const tecla = (key) => {
  const evento = { key, prevenido: false, detenido: false, preventDefault() { this.prevenido = true; }, stopPropagation() { this.detenido = true; } };
  return evento;
};

test('toma la opción inicial del servidor', () => {
  const { componente } = montar({ dataset: { valor: '7', texto: '70000001 · Ana Uno' } });
  assert.equal(componente.valor, '7');
  assert.equal(componente.texto, '70000001 · Ana Uno');
  assert.equal(componente.textoElegido, '70000001 · Ana Uno');
  assert.equal(componente.abierto, false);
});

test('al abrir consulta una sola vez con el contexto y sin texto', async () => {
  const { componente, llamadas } = montar({ responder: () => json({ opciones: [opcion(1), opcion(2)] }) });
  await componente.abrir();
  await componente.abrir();

  assert.equal(llamadas.length, 1);
  assert.equal(llamadas[0].url, '/admin/matriculas/opciones/estudiantes?q=&contexto=crear');
  assert.equal(llamadas[0].opciones.credentials, 'same-origin');
  assert.equal(llamadas[0].opciones.headers.Accept, 'application/json');
  assert.equal(componente.abierto, true);
  assert.equal(componente.opciones.length, 2);
  assert.equal(componente.anuncio, '2 resultados disponibles.');
  assert.equal(componente.cargando, false);
});

test('al abrir con una opción elegida no busca por su etiqueta', async () => {
  const { componente, llamadas } = montar({ dataset: { valor: '7', texto: '70000001 · Ana Uno' } });
  await componente.abrir();
  assert.match(llamadas[0].url, /\?q=&contexto=crear$/);
});

test('teclear espera una pausa y solo consulta el último texto', async () => {
  const { componente, llamadas, temporizadores, vencer } = montar({ responder: () => json({ opciones: [opcion(3)] }) });
  componente.texto = 'a';
  componente.escribir();
  componente.texto = 'an';
  componente.escribir();
  componente.texto = '  ana ';
  componente.escribir();

  assert.equal(llamadas.length, 0);
  assert.equal(temporizadores.size, 1);
  assert.equal([...temporizadores.values()][0].ms, RETARDO_MS);

  await vencer();
  assert.equal(llamadas.length, 1);
  assert.equal(llamadas[0].url, '/admin/matriculas/opciones/estudiantes?q=ana&contexto=crear');
  assert.equal(componente.abierto, true);
  assert.equal(componente.opciones[0].id, 3);
});

test('el texto del usuario viaja codificado en la URL', async () => {
  const { componente, llamadas } = montar();
  await componente.buscar('50% & más_');
  assert.equal(llamadas[0].url, `/admin/matriculas/opciones/estudiantes?q=${encodeURIComponent('50% & más_').replace(/%20/g, '+')}&contexto=crear`);
});

test('una respuesta vieja no pisa a la más reciente', async () => {
  const pendientes = [];
  const { componente } = montar({
    responder: () => new Promise((resolver) => { pendientes.push(resolver); }),
  });
  const primera = componente.buscar('a');
  const segunda = componente.buscar('an');

  pendientes[1](json({ opciones: [opcion(2, 'Nueva')] }));
  await segunda;
  pendientes[0](json({ opciones: [opcion(1, 'Vieja')] }));
  await primera;

  assert.deepEqual(componente.opciones.map((o) => o.texto), ['Nueva']);
  assert.equal(componente.cargando, false);
});

test('una consulta anterior se cancela con AbortController y su fallo no se muestra', async () => {
  const señales = [];
  const { componente } = montar({
    responder: (url, opciones) => new Promise((resolver, rechazar) => {
      señales.push(opciones.signal);
      opciones.signal.addEventListener('abort', () => { const error = new Error('abortado'); error.name = 'AbortError'; rechazar(error); });
    }),
  });
  const primera = componente.buscar('a');
  componente.buscar('an').catch(() => {});
  await primera;

  assert.equal(señales[0].aborted, true);
  assert.equal(componente.error, '');
});

test('un fallo del servidor muestra un error y deja la lista vacía', async () => {
  const { componente } = montar({ responder: () => json({}, 500) });
  componente.opciones = [opcion(1)];
  await componente.abrir();

  assert.equal(componente.error, 'No se pudieron cargar las opciones. Inténtalo de nuevo.');
  assert.equal(componente.anuncio, componente.error);
  assert.deepEqual(componente.opciones, []);
  assert.equal(componente.cargando, false);
  assert.equal(componente.sinResultados, false);
});

test('una respuesta con forma inesperada se trata como sin resultados', async () => {
  const { componente } = montar({ responder: () => json({ opciones: 'no es un arreglo' }) });
  await componente.abrir();
  assert.deepEqual(componente.opciones, []);
  assert.equal(componente.sinResultados, true);
  assert.equal(componente.anuncio, 'Sin resultados.');
});

test('elegir fija el valor y la etiqueta, cierra y lo anuncia', async () => {
  const { componente } = montar({ responder: () => json({ opciones: [opcion(5, '70000005 · Eva Cinco')] }) });
  await componente.abrir();
  componente.elegir(componente.opciones[0]);

  assert.equal(componente.valor, '5');
  assert.equal(componente.texto, '70000005 · Eva Cinco');
  assert.equal(componente.textoElegido, '70000005 · Eva Cinco');
  assert.equal(componente.abierto, false);
  assert.equal(componente.anuncio, 'Seleccionado: 70000005 · Eva Cinco');
});

test('un texto escrito sin elegir vuelve a la última opción elegida al salir', () => {
  const { componente } = montar({ dataset: { valor: '7', texto: '70000001 · Ana Uno' } });
  componente.abierto = true;
  componente.texto = 'xyz';
  componente.cerrar();

  assert.equal(componente.valor, '7');
  assert.equal(componente.texto, '70000001 · Ana Uno');
  assert.equal(componente.abierto, false);
});

test('vaciar el texto limpia la selección (filtro «Todos»)', () => {
  const { componente } = montar({ dataset: { valor: '7', texto: 'C-1 · Curso' } });
  componente.abierto = true;
  componente.texto = '   ';
  componente.cerrar();

  assert.equal(componente.valor, '');
  assert.equal(componente.texto, '');
  assert.equal(componente.textoElegido, '');
});

test('salir solo cierra si el foco abandona el selector', () => {
  const { componente, contiene } = montar({ dataset: { valor: '7', texto: 'X' } });
  componente.abierto = true;

  contiene.valor = true;
  componente.salir({ relatedTarget: {} });
  assert.equal(componente.abierto, true);

  contiene.valor = false;
  componente.salir({ relatedTarget: {} });
  assert.equal(componente.abierto, false);

  componente.abierto = true;
  componente.salir({ relatedTarget: null });
  assert.equal(componente.abierto, false);
});

test('teclado: flechas, Inicio, Fin y Enter eligen sin enviar el formulario', async () => {
  const { componente } = montar({ responder: () => json({ opciones: [opcion(1), opcion(2), opcion(3)] }) });

  const abrir = tecla('ArrowDown');
  componente.teclado(abrir);
  assert.equal(abrir.prevenido, true);
  await new Promise((resolver) => setImmediate(resolver));
  assert.equal(componente.abierto, true);
  assert.equal(componente.opciones.length, 3);

  componente.teclado(tecla('ArrowDown'));
  assert.equal(componente.activo, 0);
  componente.teclado(tecla('ArrowDown'));
  assert.equal(componente.activo, 1);
  assert.equal(componente.idActivo, 'estudiante_id-opcion-1');
  componente.teclado(tecla('ArrowUp'));
  assert.equal(componente.activo, 0);
  componente.teclado(tecla('End'));
  assert.equal(componente.activo, 2);
  componente.teclado(tecla('Home'));
  assert.equal(componente.activo, 0);
  componente.teclado(tecla('ArrowUp'));
  assert.equal(componente.activo, 0);

  componente.teclado(tecla('ArrowDown'));
  const enter = tecla('Enter');
  componente.teclado(enter);
  assert.equal(enter.prevenido, true);
  assert.equal(componente.valor, '2');
  assert.equal(componente.abierto, false);
});

test('teclado: Enter con la lista cerrada no se intercepta y con la abierta sin elegir no envía', () => {
  const { componente } = montar();
  const cerrado = tecla('Enter');
  componente.teclado(cerrado);
  assert.equal(cerrado.prevenido, false);

  componente.abierto = true;
  const abierto = tecla('Enter');
  componente.teclado(abierto);
  assert.equal(abierto.prevenido, true);
  assert.equal(componente.valor, '');
});

test('teclado: Escape cierra solo la lista y no llega al diálogo; cerrada, lo deja pasar', () => {
  const { componente } = montar({ dataset: { valor: '7', texto: 'X' } });

  const cerrada = tecla('Escape');
  componente.teclado(cerrada);
  assert.equal(cerrada.prevenido, false);
  assert.equal(cerrada.detenido, false);

  componente.abierto = true;
  const abierta = tecla('Escape');
  componente.teclado(abierta);
  assert.equal(abierta.prevenido, true);
  assert.equal(abierta.detenido, true);
  assert.equal(componente.abierto, false);
});

test('teclado: Tab cierra y las flechas arriba/abajo no actúan con la lista cerrada (salvo abrir)', () => {
  const { componente } = montar();
  const arriba = tecla('ArrowUp');
  componente.teclado(arriba);
  assert.equal(arriba.prevenido, false);

  componente.abierto = true;
  componente.teclado(tecla('Tab'));
  assert.equal(componente.abierto, false);
});

test('atributos accesibles: ids de la lista y de cada opción', () => {
  const { componente } = montar({ dataset: { id: 'curso_id' } });
  assert.equal(componente.idLista, 'curso_id-lista');
  assert.equal(componente.idOpcion(3), 'curso_id-opcion-3');
  assert.equal(componente.idActivo, '');
  componente.opciones = [opcion(1)];
  componente.activo = 0;
  assert.equal(componente.idActivo, 'curso_id-opcion-0');
  componente.activo = 4;
  assert.equal(componente.idActivo, '');
});

test('dentro de un manejador $el cambia: el componente sigue usando su elemento raíz', async () => {
  const { componente, llamadas } = montar({ responder: () => json({ opciones: [opcion(1)] }) });
  // En @focus, @input… Alpine expone como $el el elemento que dispara el evento (el campo), no la raíz.
  componente.$el = { dataset: {}, contains: () => false };

  await componente.abrir();
  assert.equal(llamadas[0].url, '/admin/matriculas/opciones/estudiantes?q=&contexto=crear');
  assert.equal(componente.idLista, 'estudiante_id-lista');
  assert.equal(componente.idOpcion(0), 'estudiante_id-opcion-0');
});
