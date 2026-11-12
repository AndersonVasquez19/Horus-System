import assert from 'node:assert/strict';
import test from 'node:test';
import { iniciarConfirmaciones, mensajeDeConfirmacion } from '../../resources/js/confirm-forms.mjs';

const formulario = (confirm) => ({
  dataset: confirm === undefined ? {} : { confirm },
  closest(selector) {
    return selector === 'form[data-confirm]' && confirm !== undefined ? this : null;
  },
});

function entorno(respuesta) {
  const escuchadores = {};
  const documento = { addEventListener: (tipo, fn) => { escuchadores[tipo] = fn; } };
  const preguntas = [];
  iniciarConfirmaciones(documento, (mensaje) => { preguntas.push(mensaje); return respuesta; });
  const enviar = (destino) => {
    const evento = { target: destino, prevenido: false, preventDefault() { this.prevenido = true; } };
    escuchadores.submit(evento);
    return evento;
  };
  return { enviar, preguntas };
}

test('devuelve el mensaje solo si el formulario lo define y no está vacío', () => {
  assert.equal(mensajeDeConfirmacion(formulario('¿Eliminar?')), '¿Eliminar?');
  assert.equal(mensajeDeConfirmacion(formulario(undefined)), null);
  assert.equal(mensajeDeConfirmacion(formulario('   ')), null);
  assert.equal(mensajeDeConfirmacion(null), null);
});

test('cancelar la confirmación impide el envío', () => {
  const { enviar, preguntas } = entorno(false);
  const evento = enviar(formulario('¿Eliminar este rol?'));
  assert.deepEqual(preguntas, ['¿Eliminar este rol?']);
  assert.equal(evento.prevenido, true);
});

test('aceptar la confirmación deja continuar el envío', () => {
  const { enviar } = entorno(true);
  assert.equal(enviar(formulario('¿Seguro?')).prevenido, false);
});

test('un formulario sin data-confirm no pregunta ni se bloquea', () => {
  const { enviar, preguntas } = entorno(false);
  const evento = enviar(formulario(undefined));
  assert.deepEqual(preguntas, []);
  assert.equal(evento.prevenido, false);
});

test('impide el doble envío consecutivo en formularios POST y deshabilita el botón', () => {
  const { enviar } = entorno(true);
  const boton = { disabled: false, attrs: {}, setAttribute(k, v) { this.attrs[k] = v; } };
  const formPost = {
    tagName: 'FORM',
    method: 'POST',
    dataset: {},
    attrs: {},
    setAttribute(k, v) { this.attrs[k] = v; },
    querySelector() { return boton; },
    closest() { return null; },
  };
  const primerEnvio = enviar(formPost);
  assert.equal(primerEnvio.prevenido, false);
  assert.equal(formPost.dataset.enviando, '1');
  assert.equal(formPost.attrs['aria-busy'], 'true');
  assert.equal(boton.disabled, true);

  const segundoEnvio = enviar(formPost);
  assert.equal(segundoEnvio.prevenido, true);
});
