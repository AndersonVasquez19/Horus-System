<?php

declare(strict_types=1);

use App\Models\Convocatoria;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\NotificacionInterna;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Plan de mejora del panel, Fase 3.4: riesgo de deserción (faltas seguidas, asistencia baja y deuda vencida) de los matriculados en
 * ediciones en curso. Puntajes calculados a mano.
 */

function rsgEdicion(string $estado = 'en_curso'): Convocatoria
{
    $curso = Curso::query()->firstOrCreate(['codigo' => 'RSG-1'], ['nombre' => 'Curso riesgo', 'descripcion' => 'x', 'categoria' => 'tecnologia', 'duracion_horas' => 20,
        'precio' => 300, 'modalidad' => 'virtual', 'horario' => 'Lun', 'cupos_maximos' => 10, 'cupos_disponibles' => 10, 'estado' => 'activo']);
    $c = new Convocatoria(['nombre' => null, 'modalidad' => 'virtual', 'fecha_inicio' => now()->subDays(30)->toDateString(),
        'fecha_fin' => now()->addDays(20)->toDateString(), 'cupos_maximos' => 10]);
    $c->identificador = (string) Str::uuid();
    $c->codigo = 'CNV-2026-'.random_int(1000, 9999);
    $c->curso_id = $curso->id;
    $c->estado = $estado;
    $c->cupos_disponibles = 10;
    $c->revision = 1;
    $c->save();

    return $c;
}

/** @param list<string> $asistencias estados de las sesiones 1..n en orden cronológico */
function rsgMatricula(Convocatoria $c, string $dni, array $asistencias): Matricula
{
    $e = Estudiante::create(['dni' => $dni, 'nombres' => 'Nombre'.$dni, 'apellidos' => 'Apellido'.$dni, 'estado' => 'activo']);
    $m = Matricula::create(['estudiante_id' => $e->id, 'curso_id' => $c->curso_id, 'convocatoria_id' => $c->id,
        'fecha_matricula' => now()->toDateString(), 'estado' => 'pendiente', 'monto_pagado' => 0]);
    foreach ($asistencias as $i => $estado) {
        $numero = $i + 1;
        $sesion = (int) DB::table('sesiones_clase')->where('convocatoria_id', $c->id)->where('numero', $numero)->value('id');
        if ($sesion === 0) {
            $sesion = DB::table('sesiones_clase')->insertGetId(['identificador' => (string) Str::uuid(), 'convocatoria_id' => $c->id, 'numero' => $numero,
                'fecha' => now('America/Lima')->subDays(20 - $numero)->toDateString(), 'hora_inicio' => '09:00:00', 'hora_fin' => '11:00:00',
                'estado' => 'realizada', 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('asistencias')->insert(['sesion_clase_id' => $sesion, 'matricula_id' => $m->id, 'estado' => $estado, 'created_at' => now(), 'updated_at' => now()]);
    }

    return $m;
}

function rsgMora(Matricula $m, int $diasVencida): void
{
    $o = DB::table('obligaciones_pago')->insertGetId(['identificador' => (string) Str::uuid(), 'matricula_id' => $m->id, 'concepto' => 'Matrícula',
        'plan' => 'completo', 'monto_total' => '100.00', 'moneda' => 'PEN', 'estado' => 'vigente', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('cuotas_obligacion')->insert(['obligacion_pago_id' => $o, 'numero' => 1, 'monto' => '100.00',
        'vence' => now('America/Lima')->subDays($diasVencida)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
}

/**
 * Ediciones en curso, tres sesiones realizadas (p=presente, a=ausente):
 *  Ana  a,a,a      → 3 faltas seguidas 40 + asistencia 0 % 20 = 60 (alto)
 *  Beto p,p,p + deuda de 40 días → 35 (medio)
 *  Carla p,a,a     → 2 seguidas 25 + asistencia 33,3 % 20 = 45 (medio)
 *  Dani p,p,p      → 0 (no se lista)
 *  Eva  a,p,p      → la falta quedó atrás: 1 de 3 = 66,7 % → 20 (no se lista, bajo 30)
 *  Fran a,a,a + deuda de 10 días → 40 + 20 + 25 = 85 (alto)
 */
function rsgEscenario(): array
{
    $c = rsgEdicion();
    $r = [
        'ana' => rsgMatricula($c, '75000001', ['ausente', 'ausente', 'ausente']),
        'beto' => rsgMatricula($c, '75000002', ['presente', 'presente', 'presente']),
        'carla' => rsgMatricula($c, '75000003', ['presente', 'ausente', 'ausente']),
        'dani' => rsgMatricula($c, '75000004', ['presente', 'presente', 'presente']),
        'eva' => rsgMatricula($c, '75000005', ['ausente', 'presente', 'presente']),
        'fran' => rsgMatricula($c, '75000006', ['ausente', 'ausente', 'ausente']),
    ];
    rsgMora($r['beto'], 40);
    rsgMora($r['fran'], 10);
    $cerrada = rsgEdicion('cerrada');
    rsgMatricula($cerrada, '75000007', ['ausente', 'ausente', 'ausente']); // edición cerrada: fuera

    return $r;
}

it('puntúa por faltas seguidas, asistencia baja y deuda vencida, de mayor a menor riesgo', function (): void {
    rsgEscenario();

    $r = $this->panelComo($this->usuarioConRol('coordinador'), 'GET', 'academico/riesgo-desercion')->assertOk()->json('data');
    $porNombre = collect($r['rows'])->keyBy('estudiante');

    expect(collect($r['rows'])->pluck('puntaje')->all())->toBe([85, 60, 45, 35])
        ->and(collect($r['rows'])->pluck('nivel')->all())->toBe(['alto', 'alto', 'medio', 'medio'])
        ->and($r['resumen'])->toBe(['alto' => 2, 'medio' => 2])
        ->and(array_keys($porNombre->all()))->toBe(['Nombre75000006 Apellido75000006', 'Nombre75000001 Apellido75000001', 'Nombre75000003 Apellido75000003', 'Nombre75000002 Apellido75000002'])
        ->and(collect($porNombre['Nombre75000006 Apellido75000006']['factores'])->pluck('codigo')->all())->toBe(['faltas', 'asistencia', 'deuda'])
        ->and($porNombre['Nombre75000006 Apellido75000006']['factores'][0]['texto'])->toBe('3 faltas seguidas')
        ->and(collect($porNombre['Nombre75000002 Apellido75000002']['factores'])->pluck('codigo')->all())->toBe(['deuda']);
});

it('quien no puede ver matrículas no accede (permiso retirado al rol)', function (): void {
    rsgEscenario();
    $sinPermiso = $this->usuarioConRol('secretaria');
    Role::findByName('secretaria', 'web')->revokePermissionTo('matriculas.ver');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $sinPermiso = $sinPermiso->fresh();

    $this->panelComo($sinPermiso, 'GET', 'academico/riesgo-desercion')->assertForbidden();
});

it('sin matriculados en ediciones en curso no hay filas', function (): void {
    $r = $this->panelComo($this->usuarioConRol('admin'), 'GET', 'academico/riesgo-desercion')->assertOk()->json('data');

    expect($r)->toBe(['rows' => [], 'resumen' => ['alto' => 0, 'medio' => 0]]);
});

it('el recordatorio diario avisa a quien edita matrículas una vez al día, sin nombres', function (): void {
    rsgEscenario();
    $coordinador = $this->usuarioConRol('coordinador');
    $secretaria = $this->usuarioConRol('secretaria');
    config(['recordatorios.internos_enabled' => true]);

    $this->artisan('horus:recordatorios-diarios --dry-run')->assertSuccessful();
    expect(NotificacionInterna::query()->where('tipo', 'recordatorio_riesgo')->count())->toBe(0);

    $this->artisan('horus:recordatorios-diarios')->assertSuccessful();
    $this->artisan('horus:recordatorios-diarios')->assertSuccessful();
    $avisos = NotificacionInterna::query()->where('tipo', 'recordatorio_riesgo')->get();

    expect($avisos->where('user_id', $coordinador->id))->toHaveCount(1)
        ->and($avisos->where('user_id', $secretaria->id))->toHaveCount(0)
        ->and($avisos->first()->titulo)->toContain('2 estudiante(s)')
        ->and($avisos->first()->mensaje)->not->toContain('Nombre75');
});
