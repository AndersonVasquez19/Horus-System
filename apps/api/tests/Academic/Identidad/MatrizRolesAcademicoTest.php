<?php

declare(strict_types=1);

use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * Matriz rol × acción del panel académico EXISTENTE (docs/horus-academic/03-matriz-permisos.md §5), derivada de las constantes
 * del seeder y comprobada contra la API real: lo que el seeder concede responde distinto de 403 y lo que no, 403. Así una
 * ruta nueva o un permiso mal enlazado en el middleware rompe esta prueba. Los permisos se validan en el backend; el
 * frontend no cuenta.
 *
 * Acciones de escritura: se envía un cuerpo vacío. Con permiso la respuesta es 422 (o 409), nunca 403; sin permiso es 403
 * antes de validar. Las rutas con identificador usan registros REALES: con un id inexistente el *route model binding*
 * responde 404 antes de que el middleware de permisos pueda responder 403 (comportamiento vigente de la API, anotado en el
 * informe de la fase). Se prueba primero con los roles sin permiso y el admin al final, porque una acción permitida (p. ej.
 * eliminar) cambia el registro.
 */

/** @return array{estudiante: int, curso: int, matricula: int} */
function registrosReales(): array
{
    $estudiante = Estudiante::create(['dni' => '71000001', 'nombres' => 'Ana', 'apellidos' => 'Ficticia', 'estado' => 'activo']);
    $curso = Curso::create([
        'codigo' => 'MATRIZ-1', 'nombre' => 'Curso de matriz', 'descripcion' => 'x', 'categoria' => 'tecnologia', 'duracion_horas' => 10,
        'precio' => 100, 'modalidad' => 'virtual', 'horario' => 'Lun', 'cupos_maximos' => 5, 'cupos_disponibles' => 5, 'estado' => 'activo',
    ]);
    $matricula = Matricula::create([
        'estudiante_id' => $estudiante->id, 'curso_id' => $curso->id, 'fecha_matricula' => now()->toDateString(),
        'estado' => 'pendiente', 'monto_pagado' => 0,
    ]);

    return ['estudiante' => $estudiante->id, 'curso' => $curso->id, 'matricula' => $matricula->id];
}

/** @return array<string, list<string>> */
function permisosPorRol(): array
{
    return [
        'secretaria' => RolesAndPermissionsSeeder::SECRETARIA_PERMISSIONS,
        'coordinador' => RolesAndPermissionsSeeder::COORDINADOR_PERMISSIONS,
        'admin' => RolesAndPermissionsSeeder::PERMISSIONS,
    ];
}

dataset('acciones_academicas', [
    'resumen' => ['GET', 'academico/resumen', RolesAndPermissionsSeeder::PANEL_ACADEMICO],
    'estudiantes.ver' => ['GET', 'academico/estudiantes', 'estudiantes.ver'],
    'estudiantes.crear' => ['POST', 'academico/estudiantes', 'estudiantes.crear'],
    'estudiantes.editar' => ['PUT', 'academico/estudiantes/{estudiante}', 'estudiantes.editar'],
    'estudiantes.eliminar' => ['DELETE', 'academico/estudiantes/{estudiante}', 'estudiantes.eliminar'],
    'estudiantes.restaurar' => ['POST', 'academico/estudiantes/{estudiante}/restaurar', 'estudiantes.restaurar'],
    'cursos.ver' => ['GET', 'academico/cursos', 'cursos.ver'],
    'cursos.crear' => ['POST', 'academico/cursos', 'cursos.crear'],
    'cursos.editar' => ['PUT', 'academico/cursos/{curso}', 'cursos.editar'],
    'cursos.eliminar' => ['DELETE', 'academico/cursos/{curso}', 'cursos.eliminar'],
    'cursos.publicar' => ['PUT', 'academico/cursos/{curso}/publicacion', 'cursos.publicar'],
    'matriculas.ver' => ['GET', 'academico/matriculas', 'matriculas.ver'],
    'matriculas.crear' => ['POST', 'academico/matriculas', 'matriculas.crear'],
    'matriculas.anular' => ['POST', 'academico/matriculas/{matricula}/anular', 'matriculas.anular'],
    'matriculas.exportar' => ['POST', 'academico/matriculas/exportar', 'matriculas.exportar'],
    'convocatorias.ver' => ['GET', 'academico/convocatorias', 'convocatorias.ver'],
    'convocatorias.crear' => ['POST', 'academico/convocatorias', 'convocatorias.crear'],
    'reportes.ver' => ['GET', 'academico/reportes', 'reportes.ver'],
]);

it('concede o niega cada acción según la matriz del seeder', function (string $metodo, string $ruta, string $permiso): void {
    $ids = registrosReales();
    $ruta = strtr($ruta, ['{estudiante}' => (string) $ids['estudiante'], '{curso}' => (string) $ids['curso'], '{matricula}' => (string) $ids['matricula']]);

    foreach (permisosPorRol() as $rol => $permisos) {
        $esperado403 = ! in_array($permiso, $permisos, true);
        $estado = $this->panelComo($this->usuarioConRol($rol, "{$rol}-".md5($ruta.$metodo).'@example.invalid'), $metodo, $ruta)->getStatusCode();

        if ($esperado403) {
            expect($estado)->toBe(403, "{$rol} no debería poder {$metodo} {$ruta} ({$permiso})");
        } else {
            expect($estado)->not->toBe(403, "{$rol} debería poder {$metodo} {$ruta} ({$permiso})")
                ->and($estado)->not->toBe(401);
        }
    }
})->with('acciones_academicas');

it('exige sesión: sin usuario responde 401 en JSON, sin redirigir', function (string $metodo, string $ruta): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->panelComo(null, $metodo, $ruta)->assertUnauthorized()->assertJsonPath('code', 'no_autenticado');
})->with([
    ['GET', 'academico/estudiantes'],
    ['POST', 'academico/estudiantes'],
    ['GET', 'academico/convocatorias'],
    ['POST', 'academico/matriculas'],
    ['GET', 'academico/resumen'],
]);

it('una cuenta sin rol (registro público) no entra a ninguna acción del panel', function (string $metodo, string $ruta): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $sinRol = User::factory()->create();

    expect($sinRol->getRoleNames())->toHaveCount(0);
    $this->panelComo($sinRol, $metodo, $ruta)->assertForbidden();
})->with([
    ['GET', 'academico/estudiantes'],
    ['GET', 'academico/convocatorias'],
    ['GET', 'academico/resumen'],
    ['POST', 'academico/matriculas'],
]);

it('los permisos de calificaciones respetan el rol: la secretaria registra pero no corrige ni exporta', function (): void {
    $secretaria = $this->usuarioConRol('secretaria', 'secretaria-calif@example.invalid');
    $id = '00000000-0000-4000-8000-000000000000';

    // `ver` y `registrar`: pasa el middleware (404 por la edición inexistente, nunca 403).
    expect($this->panelComo($secretaria, 'GET', "academico/convocatorias/{$id}/calificaciones")->getStatusCode())->not->toBe(403);
    expect($this->panelComo($secretaria, 'PUT', "academico/convocatorias/{$id}/calificaciones")->getStatusCode())->not->toBe(403);
    // `corregir`, `exportar` y cerrar el acta (convocatorias.cerrar): 403.
    $this->panelComo($secretaria, 'POST', "academico/convocatorias/{$id}/evaluaciones")->assertForbidden();
    $this->panelComo($secretaria, 'POST', "academico/convocatorias/{$id}/acta/exportar")->assertForbidden();
    $this->panelComo($secretaria, 'POST', "academico/convocatorias/{$id}/acta/cerrar")->assertForbidden();
});

it('los permisos concedidos por el seeder existen y los tres roles base conservan su entrada al panel', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    foreach (permisosPorRol() as $rol => $permisos) {
        expect($permisos)->toContain(RolesAndPermissionsSeeder::PANEL_ACADEMICO);
        expect(array_diff($permisos, RolesAndPermissionsSeeder::PERMISSIONS))->toBe([], "{$rol} tiene permisos fuera del catálogo");
    }
    // El panel de servicios tecnológicos se retiró: la única entrada es la académica.
    expect(RolesAndPermissionsSeeder::PANEL_PERMISSIONS)->toBe([RolesAndPermissionsSeeder::PANEL_ACADEMICO]);
});
