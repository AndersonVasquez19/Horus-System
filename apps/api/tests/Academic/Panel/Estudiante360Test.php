<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Convocatoria;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\NotaEstudiante;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Plan de mejora del panel, Fase 3.3: estudiante 360° (línea de tiempo con permisos por tipo de evento y notas internas de seguimiento).
 */

/** @return array{estudiante: Estudiante, matricula: Matricula} */
function s360Escenario(): array
{
    $curso = Curso::create(['codigo' => 'S360-1', 'nombre' => 'Curso 360', 'descripcion' => 'x', 'categoria' => 'tecnologia', 'duracion_horas' => 20,
        'precio' => 300, 'modalidad' => 'virtual', 'horario' => 'Lun', 'cupos_maximos' => 10, 'cupos_disponibles' => 10, 'estado' => 'activo']);
    $c = new Convocatoria(['nombre' => null, 'modalidad' => 'virtual', 'fecha_inicio' => now()->subDays(20)->toDateString(),
        'fecha_fin' => now()->addDays(20)->toDateString(), 'cupos_maximos' => 10]);
    $c->identificador = (string) Str::uuid();
    $c->codigo = 'CNV-2026-'.random_int(1000, 9999);
    $c->curso_id = $curso->id;
    $c->estado = 'en_curso';
    $c->cupos_disponibles = 10;
    $c->revision = 1;
    $c->save();
    $e = Estudiante::create(['dni' => '78000001', 'nombres' => 'Lola', 'apellidos' => 'Tiempo', 'estado' => 'activo']);
    $m = Matricula::create(['estudiante_id' => $e->id, 'curso_id' => $curso->id, 'convocatoria_id' => $c->id, 'fecha_matricula' => now()->subDays(15)->toDateString(),
        'estado' => 'pendiente', 'monto_pagado' => 0]);

    $o = DB::table('obligaciones_pago')->insertGetId(['identificador' => (string) Str::uuid(), 'matricula_id' => $m->id, 'concepto' => 'Matrícula',
        'plan' => 'completo', 'monto_total' => '300.00', 'moneda' => 'PEN', 'estado' => 'vigente', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('metodos_pago')->insertOrIgnore(['codigo' => 'yape', 'nombre' => 'Yape', 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('transacciones_pago')->insert(['identificador' => (string) Str::uuid(), 'obligacion_pago_id' => $o,
        'metodo_pago_id' => (int) DB::table('metodos_pago')->where('codigo', 'yape')->value('id'), 'tipo' => 'pago', 'estado' => 'confirmada', 'monto' => '100.00',
        'fecha_pago' => now('America/Lima')->subDays(10)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('seguimientos_cobranza')->insert(['identificador' => (string) Str::uuid(), 'obligacion_pago_id' => $o, 'canal' => 'llamada', 'resultado' => 'contactado',
        'created_at' => now()->subDays(2), 'updated_at' => now()]);
    $sesion = DB::table('sesiones_clase')->insertGetId(['identificador' => (string) Str::uuid(), 'convocatoria_id' => $c->id, 'numero' => 1,
        'fecha' => now('America/Lima')->subDays(5)->toDateString(), 'hora_inicio' => '09:00:00', 'hora_fin' => '11:00:00', 'estado' => 'realizada', 'revision' => 1,
        'created_at' => now(), 'updated_at' => now()]);
    DB::table('asistencias')->insert(['sesion_clase_id' => $sesion, 'matricula_id' => $m->id, 'estado' => 'ausente', 'created_at' => now(), 'updated_at' => now()]);

    return ['estudiante' => $e, 'matricula' => $m];
}

it('arma la línea de tiempo con matrícula, pago, falta y contacto de cobranza, del más reciente al más antiguo', function (): void {
    $e = s360Escenario()['estudiante'];

    $r = $this->panelComo($this->usuarioConRol('admin'), 'GET', "academico/estudiantes/{$e->id}/360")->assertOk()->json('data');

    expect(array_column($r['eventos'], 'tipo'))->toBe(['cobranza', 'asistencia', 'pago', 'matricula'])
        ->and($r['eventos'][1]['titulo'])->toBe('Faltó a la sesión 1')
        ->and($r['eventos'][2]['titulo'])->toBe('Pago de S/ 100.00')
        ->and($r['eventos'][3]['titulo'])->toContain('Curso 360')
        ->and($r['truncado'])->toBeFalse();
});

it('cada evento depende del permiso de su módulo', function (): void {
    $e = s360Escenario()['estudiante'];
    // Secretaría tiene pagos.ver y asistencias.ver, no certificados.* de gestión: ve pagos y faltas; coordinación igual.
    $secretaria = $this->usuarioConRol('secretaria');
    Role::findByName('secretaria', 'web')->revokePermissionTo(['pagos.ver', 'asistencias.ver']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $r = $this->panelComo($secretaria->fresh(), 'GET', "academico/estudiantes/{$e->id}/360")->assertOk()->json('data');

    expect(array_column($r['eventos'], 'tipo'))->toBe(['matricula']);
});

it('agrega notas internas, las muestra en la línea de tiempo y audita sin el texto', function (): void {
    $e = s360Escenario()['estudiante'];
    $u = $this->usuarioConRol('secretaria');

    $nota = $this->panelComo($u, 'POST', "academico/estudiantes/{$e->id}/notas", ['texto' => 'Pidió cambiar de turno por trabajo'])->assertCreated()->json('data');
    expect($nota['autor'])->toBe($u->name);

    $r = $this->panelComo($u, 'GET', "academico/estudiantes/{$e->id}/360")->json('data');
    $interna = collect($r['eventos'])->firstWhere('tipo', 'nota_interna');
    expect($interna['detalle'])->toBe('Pidió cambiar de turno por trabajo')->and($interna['autor'])->toBe($u->name);

    $auditoria = AuditLog::query()->where('action', 'nota_estudiante_agregada')->firstOrFail();
    expect(json_encode($auditoria->changes))->not->toContain('turno');
});

it('valida la nota y no admite etiquetas HTML', function (): void {
    $e = s360Escenario()['estudiante'];
    $u = $this->usuarioConRol('coordinador');

    $this->panelComo($u, 'POST', "academico/estudiantes/{$e->id}/notas", ['texto' => 'ab'])->assertStatus(422);
    $this->panelComo($u, 'POST', "academico/estudiantes/{$e->id}/notas", ['texto' => '<b>hola</b> mundo'])->assertStatus(422);
    $this->panelComo($u, 'POST', "academico/estudiantes/{$e->id}/notas", ['texto' => str_repeat('a', 501)])->assertStatus(422);
    $this->panelComo($u, 'POST', 'academico/estudiantes/999999/notas', ['texto' => 'valida'])->assertNotFound();
    expect(NotaEstudiante::query()->count())->toBe(0);
});

it('una nota no se modifica ni se borra', function (): void {
    $e = s360Escenario()['estudiante'];
    $this->panelComo($this->usuarioConRol('admin'), 'POST', "academico/estudiantes/{$e->id}/notas", ['texto' => 'Nota fija'])->assertCreated();
    $n = NotaEstudiante::query()->firstOrFail();

    $n->texto = 'cambiada';
    expect(fn () => $n->save())->toThrow(LogicException::class)->and(fn () => $n->delete())->toThrow(LogicException::class);
});
