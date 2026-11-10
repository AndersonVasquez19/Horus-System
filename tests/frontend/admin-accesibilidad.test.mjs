import assert from 'node:assert/strict';
import test from 'node:test';
import { adminShell, debeEstarInerte, CONSULTA_ESCRITORIO } from '../../resources/js/admin-shell.mjs';
import { avisoPanel, duracionAviso, DURACION_EXITO_MS } from '../../resources/js/admin-avisos.mjs';
import { confirmModal, normalizarConfirmacion } from '../../resources/js/confirm-modal.mjs';

const enfocable = () => ({ enfocado: 0, focus() { this.enfocado += 1; } });

function montar(componente, $el = {}, $refs = {}) {
  const pendientes = [];
  Object.assign(componente, { $el, $refs, $nextTick: (fn) => pendientes.push(fn) });
  return { componente, vaciar: () => { while (pendientes.length) pendientes.shift()(); } };
}

// Menú lateral -------------------------------------------------------------------------------------------

test('el menú solo queda inerte cerrado en móvil', () => {
  assert.equal(debeEstarInerte(false, false), true);
  assert.equal(debeEstarInerte(true, false), false);
  assert.equal(debeEstarInerte(false, true), false);
  assert.equal(debeEstarInerte(true, true), false);
});

test('en móvil abrir enfoca el primer enlace y cerrar devuelve el foco al botón', () => {
  const consultas = [];
  const enlace = enfocable();
  const boton = enfocable();
  const sidebar = { querySelector: () => enlace };
  const { componente, vaciar } = montar(
    adminShell({ matchMedia: (q) => { consultas.push(q); return { matches: false, addEventListener() {} }; } }),
    {},
    { sidebar, botonMenu: boton },
  );
  componente.init();
  assert.deepEqual(consultas, [CONSULTA_ESCRITORIO]);
  assert.equal(componente.menuInerte, true);

  componente.alternarMenu();
  vaciar();
  assert.equal(componente.sidebarOpen, true);
  assert.equal(componente.menuInerte, false);
  assert.equal(enlace.enfocado, 1);

  componente.cerrarMenu();
  vaciar();
  assert.equal(componente.sidebarOpen, false);
  assert.equal(boton.enfocado, 1);

  componente.cerrarMenu(); // ya cerrado: no mueve el foco
  vaciar();
  assert.equal(boton.enfocado, 1);
});

test('al pasar a escritorio el menú se cierra y deja de estar inerte', () => {
  let alCambiar;
  const { componente } = montar(adminShell({ matchMedia: () => ({ matches: false, addEventListener: (_t, fn) => { alCambiar = fn; } }) }));
  componente.init();
  componente.sidebarOpen = true;
  alCambiar({ matches: true });
  assert.equal(componente.esEscritorio, true);
  assert.equal(componente.sidebarOpen, false);
  assert.equal(componente.menuInerte, false);
});

// Avisos -------------------------------------------------------------------------------------------------

function relojFalso() {
  let t = 0;
  let siguiente = 1;
  const tareas = new Map();
  return {
    setTimeout: (fn, ms) => { const id = siguiente++; tareas.set(id, { fn, en: t + ms }); return id; },
    clearTimeout: (id) => tareas.delete(id),
    now: () => t,
    avanzar(ms) {
      t += ms;
      for (const [id, tarea] of [...tareas]) if (tarea.en <= t) { tareas.delete(id); tarea.fn(); }
    },
    pendientes: () => tareas.size,
  };
}

test('solo los avisos de éxito tienen cierre automático', () => {
  assert.equal(duracionAviso('success'), DURACION_EXITO_MS);
  assert.equal(duracionAviso('error'), null);
  assert.equal(duracionAviso(undefined), null);
});

test('la región viva nace vacía y recibe el texto en el siguiente ciclo', () => {
  const reloj = relojFalso();
  const { componente, vaciar } = montar(avisoPanel(reloj), { dataset: { tipo: 'success', mensaje: 'Guardado.' } });
  componente.init();
  assert.equal(componente.mensaje, '');
  vaciar();
  assert.equal(componente.mensaje, 'Guardado.');
});

test('el aviso de éxito se cierra solo y la pausa conserva el tiempo restante', () => {
  const reloj = relojFalso();
  const { componente, vaciar } = montar(avisoPanel(reloj), { dataset: { tipo: 'success', mensaje: 'Listo' } });
  componente.init();
  vaciar();

  reloj.avanzar(2000);
  componente.pausar();
  reloj.avanzar(60000);
  assert.equal(componente.visible, true);

  componente.reanudar();
  reloj.avanzar(DURACION_EXITO_MS - 2000 - 1);
  assert.equal(componente.visible, true);
  reloj.avanzar(1);
  assert.equal(componente.visible, false);
});

test('el aviso de error no se cierra solo, pero sí con el botón', () => {
  const reloj = relojFalso();
  const { componente, vaciar } = montar(avisoPanel(reloj), { dataset: { tipo: 'error', mensaje: 'Falló' } });
  componente.init();
  vaciar();
  assert.equal(reloj.pendientes(), 0);
  reloj.avanzar(600000);
  assert.equal(componente.visible, true);
  componente.cerrar();
  assert.equal(componente.visible, false);
});

// Confirmación -------------------------------------------------------------------------------------------

function dialogoFalso() {
  return {
    open: false,
    llamadas: [],
    showModal() { this.open = true; this.llamadas.push('showModal'); },
    close() { this.open = false; this.llamadas.push('close'); this.alCerrar?.(); },
  };
}

test('normaliza el detalle con valores predeterminados', () => {
  assert.deepEqual(normalizarConfirmacion({ title: 'Eliminar', action: '/admin/x/1', method: '', extra: 1 }), {
    title: 'Eliminar', message: '', action: '/admin/x/1', method: 'DELETE', confirmText: 'Sí, continuar',
  });
  assert.equal(normalizarConfirmacion(null).title, '¿Estás seguro?');
});

test('abre como modal nativo, enfoca Cancelar y devuelve el foco al disparador al cerrar', () => {
  const disparador = enfocable();
  const cancelar = enfocable();
  const dialogo = dialogoFalso();
  const { componente, vaciar } = montar(confirmModal({ document: { activeElement: disparador } }), dialogo, { cancelBtn: cancelar });
  dialogo.alCerrar = () => componente.alCerrar();

  componente.abrir({ title: '¿Eliminar curso?', message: 'No se puede deshacer.', action: '/admin/cursos/3' });
  vaciar();
  assert.deepEqual(dialogo.llamadas, ['showModal']);
  assert.equal(componente.title, '¿Eliminar curso?');
  assert.equal(componente.method, 'DELETE');
  assert.equal(cancelar.enfocado, 1);

  componente.cerrar();
  assert.equal(dialogo.open, false);
  assert.equal(disparador.enfocado, 1);
});

test('un clic en el fondo cierra; un clic dentro no', () => {
  const dialogo = dialogoFalso();
  const { componente } = montar(confirmModal({ document: { activeElement: null } }), dialogo, {});
  componente.abrir({});
  componente.clicFondo({ target: {} });
  assert.equal(dialogo.open, true);
  componente.clicFondo({ target: dialogo });
  assert.equal(dialogo.open, false);
});
