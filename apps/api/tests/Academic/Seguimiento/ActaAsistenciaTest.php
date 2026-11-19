<?php

declare(strict_types=1);

use App\Models\Asistencia;
use App\Models\Convocatoria;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\SesionClase;
use Illuminate\Support\Str;

/*
 * Horus Academic, Fase 8 (S4): el acta muestra el porcentaje de asistencia solo a quien puede ver la asistencia. SQLite en memoria: el
 * disparador de suma de pesos (`evaluaciones_peso_total`) es de PostgreSQL y NO se prueba aquí; la regla se prueba en el servicio.
 */

function actaEdicionConAsistencia(): array
{
    $curso = Curso::create(['codigo' => 'ACT-1', 'nombre' => 'Curso', 'descripcion' => 'x', 'categoria' => 'tecnologia', 'duracion_horas' => 10,
        'precio' => 100, 'modalidad' => 'virtual', 'horario' => 'Lun', 'cupos_maximos' => 50, 'cupos_disponibles' => 50, 'estado' => 'activo']);
    $c = new Convocatoria(['nombre' => null, 'modalidad' => 'virtual', 'fecha_inicio' => now()->subDays(3)->toDateString(), 'fecha_fin' => now()->addDays(30)->toDateString(), 'cupos_maximos' => 40]);
    $c->identificador = (string) Str::uuid();
    $c->codigo = 'CNV-2026-5001';
    $c->curso_id = $curso->id;
    $c->estado = 'en_curso';
    $c->cupos_disponibles = 40;
    $c->revision = 1;
    $c->save();
    $e = Estudiante::create(['dni' => '70000001', 'nombres' => 'Ana', 'apellidos' => 'Quispe', 'estado' => 'activo']);
    $m = Matricula::create(['estudiante_id' => $e->id, 'curso_id' => $curso->id, 'convocatoria_id' => $c->id, 'fecha_matricula' => now()->toDateString(), 'estado' => 'pendiente', 'monto_pagado' => 0]);
    foreach ([1, 2] as $n) {
        $s = new SesionClase(['tema' => null]);
        $s->identificador = (string) Str::uuid();
        $s->convocatoria_id = $c->id;
        $s->numero = $n;
        $s->fecha = now('America/Lima')->toDateString();
        $s->hora_inicio = sprintf('%02d:00:00', 6 + $n);
        $s->hora_fin = sprintf('%02d:00:00', 7 + $n);
        $s->estado = 'realizada';
        $s->revision = 1;
        $s->save();
        $a = new Asistencia;
        $a->sesion_clase_id = $s->id;
        $a->matricula_id = $m->id;
        $a->estado = $n === 1 ? 'presente' : 'ausente';
        $a->save();
    }

    return [$c, $m];
}

it('incluye el porcentaje de asistencia en el acta solo con asistencias.ver', function (): void {
    [$c, $m] = actaEdicionConAsistencia();
    $admin = $this->usuarioConRol('admin', 'a@example.invalid');
    // Solo permisos directos (sin rol): puede ver calificaciones pero no asistencia.
    $sinPermiso = App\Models\User::factory()->create(['email' => 's@example.invalid']);
    $sinPermiso->givePermissionTo(['panel.academico.acceder', 'convocatorias.ver', 'calificaciones.ver']);

    $r = $this->panelComo($admin, 'GET', "academico/convocatorias/{$c->identificador}/calificaciones")->assertOk();
    $r->assertJsonPath('data.asistencia_visible', true);
    expect($r->json('data.matriculas.0.asistencia'))->toEqual(50);

    $r = $this->panelComo($sinPermiso, 'GET', "academico/convocatorias/{$c->identificador}/calificaciones")->assertOk();
    $r->assertJsonPath('data.asistencia_visible', false)->assertJsonPath('data.matriculas.0.asistencia', null);
});
