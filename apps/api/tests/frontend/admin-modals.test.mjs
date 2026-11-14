import test from 'node:test';
import assert from 'node:assert/strict';
import { esEnlaceFormulario } from '../../resources/js/admin-modals.mjs';

const base = 'https://horus.example/admin/usuarios?estado=activo';

test('reconoce altas, ediciones y publicación conservando parámetros de navegación', () => {
  for (const href of ['/admin/usuarios/create', '/admin/usuarios/2/edit', '/admin/usuarios/2/edit?origen=listado']) {
    assert.equal(esEnlaceFormulario(href, base), true, href);
  }
});

test('no intercepta otros sitios, exportaciones, bajas, autenticación ni consultas', () => {
  for (const href of ['https://otro.example/admin/usuarios/create', '//otro.example/admin/usuarios/create', '/admin/usuarios', '/admin/usuarios/1', '/admin/estudiantes/create', '/admin/cursos/17/edit', '/admin/matriculas/create', '/admin/matriculas/1/edit', '/login', '/educacion/cursos', 'javascript:alert(1)']) {
    assert.equal(esEnlaceFormulario(href, base), false, href);
  }
});
