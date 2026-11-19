<?php

declare(strict_types=1);

use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;

function datosBusqueda(): array
{
    $curso = Curso::create([
        'codigo' => 'BUS-1', 'nombre' => 'Curso Buscable', 'descripcion' => 'x', 'categoria' => 'tecnologia',
        'duracion_horas' => 10, 'precio' => '100.00', 'modalidad' => 'virtual', 'horario' => 'Lun',
        'cupos_maximos' => 10, 'cupos_disponibles' => 10, 'estado' => 'activo',
    ]);
    $est = Estudiante::create(['dni' => '70123456', 'nombres' => 'Lucía', 'apellidos' => 'Quispe Zamora', 'estado' => 'activo']);
    $mat = Matricula::create(['estudiante_id' => $est->id, 'curso_id' => $curso->id, 'fecha_matricula' => now()->toDateString(), 'estado' => 'pendiente', 'monto_pagado' => 0]);

    return [$curso, $est, $mat];
}

test('la búsqueda global encuentra estudiante por DNI y apellido, y matrícula por número', function (): void {
    $admin = $this->usuarioConRol('admin', 'busca-admin@example.test');
    [, $est, $mat] = datosBusqueda();

    $porDni = $this->panelComo($admin, 'GET', 'academico/busqueda?q=70123456')->assertOk();
    expect($porDni->json('data.grupos.estudiantes.0.id'))->toBe($est->id)
        ->and($porDni->json('data.grupos.estudiantes.0.detalle'))->toBe('DNI 70123456');

    $porApellido = $this->panelComo($admin, 'GET', 'academico/busqueda?q=quispe')->assertOk();
    expect($porApellido->json('data.grupos.estudiantes'))->toHaveCount(1);

    $porNumero = $this->panelComo($admin, 'GET', 'academico/busqueda?q=%23'.$mat->id)->assertOk();
    expect(collect($porNumero->json('data.grupos.matriculas'))->pluck('id')->all())->toContain($mat->id);
});

test('la búsqueda exige 2 caracteres, no devuelve montos y su respuesta no se guarda en caché', function (): void {
    $admin = $this->usuarioConRol('admin', 'busca-min@example.test');
    datosBusqueda();

    $corta = $this->panelComo($admin, 'GET', 'academico/busqueda?q=q')->assertOk();
    expect($corta->json('data.grupos'))->toBe([]);

    $respuesta = $this->panelComo($admin, 'GET', 'academico/busqueda?q=Quispe')->assertOk();
    expect($respuesta->headers->get('Cache-Control'))->toContain('no-store')
        ->and($respuesta->getContent())->not->toContain('monto');

    $this->panelComo($admin, 'GET', 'academico/busqueda?q='.str_repeat('a', 101))->assertUnprocessable();
});

test('cada grupo de la búsqueda se limita a 5 resultados', function (): void {
    $admin = $this->usuarioConRol('admin', 'busca-limite@example.test');
    foreach (range(1, 8) as $i) {
        Estudiante::create(['dni' => '7100000'.$i, 'nombres' => 'Nombre'.$i, 'apellidos' => 'Repetido', 'estado' => 'activo']);
    }

    $r = $this->panelComo($admin, 'GET', 'academico/busqueda?q=Repetido')->assertOk();
    expect($r->json('data.grupos.estudiantes'))->toHaveCount(5);
});
