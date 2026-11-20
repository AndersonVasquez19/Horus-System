import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
  LIMITE_ENTERO,
  LIMITE_PRECIO,
  crearEsquemaUsuario,
  cuerpoEstudiante,
  cuerpoUsuario,
  esquemaCurso,
  esquemaEstudiante,
  esquemaRol,
} from '../esquemas.ts';

/** Mensajes de error por campo (`ruta.punteada` → primer mensaje). */
function errores(resultado: { success: boolean; error?: { issues: { path: (string | number)[]; message: string }[] } }) {
  const salida: Record<string, string> = {};
  for (const incidencia of resultado.error?.issues ?? []) {
    const clave = incidencia.path.join('.');
    if (!(clave in salida)) salida[clave] = incidencia.message;
  }
  return salida;
}

const estudianteValido = {
  dni: '12345678',
  nombres: 'Ana',
  apellidos: 'Quispe',
  email: '',
  telefono: '',
  fecha_nacimiento: '',
  direccion: '',
  estado: 'activo',
};

const cursoValido = {
  codigo: 'JS-101',
  nombre: 'JavaScript',
  descripcion: 'Curso básico',
  categoria: 'tecnologia',
  modalidad: 'presencial',
  estado: 'activo',
  horario: 'Lun y mié 6 pm',
  duracion_horas: '40',
  precio: '250.00',
  cupos_maximos: '10',
  enlace_grupo_whatsapp: '',
};

test('estudiante: acepta lo mínimo y deja los opcionales vacíos', () => {
  assert.equal(esquemaEstudiante.safeParse(estudianteValido).success, true);
});

test('estudiante: el DNI exige exactamente 8 dígitos', () => {
  for (const dni of ['1234567', '123456789', '1234567a', '', '1234 5678']) {
    assert.equal(errores(esquemaEstudiante.safeParse({ ...estudianteValido, dni })).dni, 'El DNI debe tener 8 dígitos.', `dni «${dni}»`);
  }
  assert.equal(esquemaEstudiante.safeParse({ ...estudianteValido, dni: ' 12345678 ' }).success, true, 'se recortan los espacios');
});

test('estudiante: nombres y apellidos obligatorios, y correo con forma mínima si se escribe', () => {
  const e = errores(esquemaEstudiante.safeParse({ ...estudianteValido, nombres: '  ', apellidos: '', email: 'sin-arroba' }));
  assert.equal(e.nombres, 'Indica los nombres.');
  assert.equal(e.apellidos, 'Indica los apellidos.');
  assert.equal(e.email, 'Escribe un correo válido.');
  assert.equal(esquemaEstudiante.safeParse({ ...estudianteValido, email: 'ana@example.com' }).success, true);
});

test('estudiante: respeta los largos máximos de los campos', () => {
  assert.equal(errores(esquemaEstudiante.safeParse({ ...estudianteValido, nombres: 'a'.repeat(101) })).nombres, 'Hasta 100 caracteres.');
  assert.equal(errores(esquemaEstudiante.safeParse({ ...estudianteValido, telefono: '9'.repeat(21) })).telefono, 'Hasta 20 caracteres.');
  assert.equal(errores(esquemaEstudiante.safeParse({ ...estudianteValido, direccion: 'x'.repeat(256) })).direccion, 'Hasta 255 caracteres.');
});

test('estudiante: los opcionales vacíos viajan como null y lo demás ya recortado', () => {
  const datos = esquemaEstudiante.parse({ ...estudianteValido, nombres: '  Ana  ', email: '   ' });
  const cuerpo = cuerpoEstudiante(datos);
  assert.equal(cuerpo.nombres, 'Ana');
  assert.equal(cuerpo.email, null);
  assert.equal(cuerpo.telefono, null);
  assert.equal(cuerpo.fecha_nacimiento, null);
  assert.equal(cuerpo.estado, 'activo');
  assert.deepEqual(Object.keys(cuerpo).sort(), ['apellidos', 'direccion', 'dni', 'email', 'estado', 'fecha_nacimiento', 'nombres', 'telefono']);
});

test('curso: un curso completo es válido', () => {
  assert.equal(esquemaCurso.safeParse(cursoValido).success, true);
});

test('curso: los campos de texto obligatorios no pueden quedar vacíos', () => {
  const e = errores(esquemaCurso.safeParse({ ...cursoValido, codigo: '', nombre: ' ', descripcion: '', horario: '' }));
  assert.equal(e.codigo, 'Indica el código.');
  assert.equal(e.nombre, 'Indica el nombre.');
  assert.equal(e.descripcion, 'Indica la descripción.');
  assert.equal(e.horario, 'Indica el horario.');
});

test('curso: duración y cupos son enteros entre 1 y el límite de PostgreSQL', () => {
  assert.equal(esquemaCurso.safeParse({ ...cursoValido, duracion_horas: String(LIMITE_ENTERO), cupos_maximos: String(LIMITE_ENTERO) }).success, true);
  for (const valor of ['0', '-3', '1.5', 'abc', String(LIMITE_ENTERO + 1)]) {
    const e = errores(esquemaCurso.safeParse({ ...cursoValido, duracion_horas: valor, cupos_maximos: valor }));
    assert.ok(e.duracion_horas, `duración «${valor}» debe rechazarse`);
    assert.ok(e.cupos_maximos, `cupos «${valor}» debe rechazarse`);
  }
  assert.equal(errores(esquemaCurso.safeParse({ ...cursoValido, duracion_horas: '' })).duracion_horas, 'Indica la duración en horas.');
});

test('curso: el precio admite hasta 2 decimales y hasta 99999999.99', () => {
  for (const precio of ['0', '0.5', '250', '250.00', String(LIMITE_PRECIO)]) {
    assert.equal(esquemaCurso.safeParse({ ...cursoValido, precio }).success, true, `precio «${precio}»`);
  }
  for (const precio of ['-1', '12.345', '1e3', 'gratis', '100000000', '99999999.991']) {
    assert.ok(errores(esquemaCurso.safeParse({ ...cursoValido, precio })).precio, `precio «${precio}» debe rechazarse`);
  }
  assert.equal(errores(esquemaCurso.safeParse({ ...cursoValido, precio: '' })).precio, 'Indica el precio.');
});

test('curso: no inventa reglas que Laravel no tiene (cupos > 10 pasan; Laravel decide)', () => {
  assert.equal(esquemaCurso.safeParse({ ...cursoValido, cupos_maximos: '50' }).success, true);
});

const usuarioNuevo = { name: 'Luis', email: 'luis@example.com', password: 'Clave-Segura-123', password_confirmation: 'Clave-Segura-123', temporal: true, roles: ['secretaria'] };

test('usuario nuevo: exige contraseña y que coincida con su confirmación', () => {
  const alta = crearEsquemaUsuario(false);
  assert.equal(alta.safeParse(usuarioNuevo).success, true);
  assert.equal(errores(alta.safeParse({ ...usuarioNuevo, password: '', password_confirmation: '' })).password, 'Indica una contraseña.');
  assert.equal(errores(alta.safeParse({ ...usuarioNuevo, password_confirmation: 'otra' })).password_confirmation, 'Las contraseñas no coinciden.');
});

test('usuario existente: la contraseña es opcional; si se escribe, debe coincidir', () => {
  const edicion = crearEsquemaUsuario(true);
  assert.equal(edicion.safeParse({ ...usuarioNuevo, password: '', password_confirmation: '' }).success, true, 'vacía = conservar');
  assert.equal(errores(edicion.safeParse({ ...usuarioNuevo, password_confirmation: 'otra' })).password_confirmation, 'Las contraseñas no coinciden.');
});

test('usuario: nombre y correo obligatorios, correo con forma mínima', () => {
  const e = errores(crearEsquemaUsuario(false).safeParse({ ...usuarioNuevo, name: '', email: 'luis' }));
  assert.equal(e.name, 'Indica el nombre.');
  assert.equal(e.email, 'Escribe un correo válido.');
});

test('usuario: el cuerpo omite contraseñas al editar sin cambiarlas y roles si no se pueden asignar', () => {
  const sinClave = { ...usuarioNuevo, password: '', password_confirmation: '' };
  assert.deepEqual(cuerpoUsuario(sinClave, { edicion: true, puedeAsignarRoles: false }), { name: 'Luis', email: 'luis@example.com' });
  assert.deepEqual(cuerpoUsuario(sinClave, { edicion: true, puedeAsignarRoles: true }), { name: 'Luis', email: 'luis@example.com', roles: ['secretaria'] });
  assert.deepEqual(cuerpoUsuario(usuarioNuevo, { edicion: false, puedeAsignarRoles: true }), {
    name: 'Luis',
    email: 'luis@example.com',
    password: 'Clave-Segura-123',
    password_confirmation: 'Clave-Segura-123',
    temporal: true,
    roles: ['secretaria'],
  });
});

test('rol: el nombre es obligatorio; los permisos pueden ir vacíos (Laravel decide)', () => {
  assert.equal(esquemaRol.safeParse({ name: 'Auditor', permissions: [] }).success, true);
  assert.equal(errores(esquemaRol.safeParse({ name: '  ', permissions: [] })).name, 'Indica el nombre del rol.');
  assert.equal(errores(esquemaRol.safeParse({ name: 'x'.repeat(256), permissions: [] })).name, 'Hasta 255 caracteres.');
});
