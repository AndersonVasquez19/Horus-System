import test from 'node:test';
import assert from 'node:assert/strict';
import { esEnlaceFormulario } from '../../resources/js/admin-modals.mjs';

const base = 'https://horus.example/admin/cursos?estado=activo';

test('reconoce altas, ediciones y publicación conservando parámetros de navegación', () => {
  for (const href of ['/admin/estudiantes/create', '/admin/cursos/17/edit', '/admin/cursos/17/publicacion?origen=listado', '/admin/matriculas/create', '/admin/usuarios/2/edit']) {
    assert.equal(esEnlaceFormulario(href, base), true, href);
  }
});

test('no intercepta otros sitios, exportaciones, bajas, autenticación ni consultas', () => {
  for (const href of ['https://otro.example/admin/cursos/create', '//otro.example/admin/cursos/create', '/admin/cursos', '/admin/cursos/1', '/admin/matriculas/exportar/csv', '/admin/cursos/1/restaurar', '/login', '/educacion/cursos', 'javascript:alert(1)']) {
    assert.equal(esEnlaceFormulario(href, base), false, href);
  }
});
