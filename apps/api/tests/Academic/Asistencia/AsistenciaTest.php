<?php

declare(strict_types=1);

use App\Models\Asistencia;
use App\Models\AuditLog;
use App\Models\Convocatoria;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\SesionClase;
use Illuminate\Support\Str;

/*
 * Horus Academic, Fase 6: asistencia. SQLite en memoria: el CHECK del estado y el bloqueo de filas (`lockForUpdate`) son de PostgreSQL
 * y NO se prueban aquí; la unicidad (sesión, matrícula) sí.
 */

function asisEdicion(): Convocatoria
{
    static $n = 0;
    $n++;
    $curso = Curso::create(['codigo' => 'ASI-'.$n, 'nombre' => 'Curso '.$n, 'descripcion' => 'x', 'categoria' => 'tecnologia', 'duracion_horas' => 10,
        'precio' => 100, 'modalidad' => 'virtual', 'horario' => 'Lun', 'cupos_maximos' => 50, 'cupos_disponibles' => 50, 'estado' => 'activo']);
    $c = new Convocatoria(['nombre' => null, 'modalidad' => 'virtual', 'fecha_inicio' => now()->subDays(3)->toDateString(),
        'fecha_fin' => now()->addDays(60)->toDateString(), 'cupos_maximos' => 40]);
    $c->identificador = (string) Str::uuid();
    $c->codigo = 'CNV-2026-7'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    $c->curso_id = $curso->id;
    $c->estado = 'en_curso';
    $c->cupos_disponibles = 40;
    $c->revision = 1;
    $c->save();

    return $c;
}

function asisMatricula(Convocatoria $c, string $dni, string $estado = 'pendiente'): Matricula
{
    $e = Estudiante::create(['dni' => $dni, 'nombres' => 'Ana '.$dni, 'apellidos' => 'Quispe '.$dni, 'estado' => 'activo']);

    return Matricula::create(['estudiante_id' => $e->id, 'curso_id' => $c->curso_id, 'convocatoria_id' => $c->id, 'fecha_matricula' => now()->toDateString(), 'estado' => $estado, 'monto_pagado' => 0]);
}

function asisSesion(Convocatoria $c, int $numero, string $estado = 'realizada', ?string $fecha = null): SesionClase
{
    $s = new SesionClase(['tema' => null]);
    $s->identificador = (string) Str::uuid();
    $s->convocatoria_id = $c->id;
    $s->numero = $numero;
    $s->fecha = $fecha ?? now('America/Lima')->toDateString();
    $s->hora_inicio = sprintf('%02d:00:00', 6 + $numero);
    $s->hora_fin = sprintf('%02d:00:00', 7 + $numero);
    $s->estado = $estado;
    $s->motivo_cancelacion = $estado === 'cancelada' ? 'Cancelada para la prueba' : null;
    $s->revision = 1;
    $s->save();

    return $s;
}

it('crea la tabla con asistencia única por sesión y matrícula', function (): void {
    $unicos = collect(Schema::getIndexes('asistencias'))->filter(fn ($i) => $i['unique'])->pluck('columns')->all();
    expect($unicos)->toContain(['sesion_clase_id', 'matricula_id']);
});

it('exige autenticación y el permiso de cada operación', function (): void {
    $c = asisEdicion();
    $m = asisMatricula($c, '50000001');
    $s = asisSesion($c, 1);
    $admin = $this->usuarioConRol('admin', 'a@example.invalid');
    $coord = $this->usuarioConRol('coordinador', 'c@example.invalid');
    $sec = $this->usuarioConRol('secretaria', 's@example.invalid');
    $lote = ['asistencias' => [['matricula_id' => $m->id, 'estado' => 'presente']]];

    $this->panelComo(null, 'GET', "academico/sesiones/{$s->identificador}/asistencias")->assertUnauthorized();
    $this->panelComo($sec, 'GET', "academico/sesiones/{$s->identificador}/asistencias")->assertOk();
    $this->panelComo($sec, 'GET', "academico/convocatorias/{$c->identificador}/asistencias/resumen")->assertOk();
    $this->panelComo($sec, 'PUT', "academico/sesiones/{$s->identificador}/asistencias", $lote)->assertForbidden();
    $this->panelComo($sec, 'PUT', "academico/sesiones/{$s->identificador}/asistencias/{$m->id}", ['estado' => 'ausente', 'motivo' => 'x'])->assertForbidden();
    $this->panelComo($sec, 'POST', "academico/convocatorias/{$c->identificador}/asistencias/exportar")->assertForbidden();
    $this->panelComo($coord, 'PUT', "academico/sesiones/{$s->identificador}/asistencias", $lote)->assertOk();
    $this->panelComo($admin, 'POST', "academico/convocatorias/{$c->identificador}/asistencias/exportar")->assertOk();
});

it('registra por lote, es idempotente y no sobrescribe sin corrección', function (): void {
    $c = asisEdicion();
    $a = asisMatricula($c, '50000001');
    $b = asisMatricula($c, '50000002');
    $s = asisSesion($c, 1);
    $admin = $this->usuarioConRol('admin');
    $url = "academico/sesiones/{$s->identificador}/asistencias";
    $lote = ['asistencias' => [['matricula_id' => $a->id, 'estado' => 'presente'], ['matricula_id' => $b->id, 'estado' => 'ausente', 'observacion' => 'Avisó tarde']]];

    $this->panelComo($admin, 'PUT', $url, $lote)->assertOk()->assertJsonPath('data.registradas', 2)->assertJsonPath('data.sin_cambios', 0);
    $this->panelComo($admin, 'PUT', $url, $lote)->assertOk()->assertJsonPath('data.registradas', 0)->assertJsonPath('data.sin_cambios', 2);
    expect(Asistencia::count())->toBe(2);

    // Un estado distinto a uno registrado -> 409 y el lote entero no se aplica.
    $c2 = asisMatricula($c, '50000003');
    $this->panelComo($admin, 'PUT', $url, ['asistencias' => [['matricula_id' => $c2->id, 'estado' => 'presente'], ['matricula_id' => $a->id, 'estado' => 'ausente']]])->assertStatus(409);
    expect(Asistencia::count())->toBe(2)->and(Asistencia::where('matricula_id', $a->id)->value('estado'))->toBe('presente');

    expect(AuditLog::where('action', 'asistencia_registrada')->count())->toBe(1)
        ->and(json_encode(AuditLog::where('action', 'asistencia_registrada')->get()->map->only('changes')->all()))->not->toContain('Avisó');
});

it('valida matrículas, estados, duplicados y el estado de la sesión', function (): void {
    $c = asisEdicion();
    $otra = asisEdicion();
    $ok = asisMatricula($c, '50000001');
    $anulada = asisMatricula($c, '50000002', 'anulada');
    $ajena = asisMatricula($otra, '50000003');
    $s = asisSesion($c, 1);
    $futura = asisSesion($c, 2, 'programada', now('America/Lima')->addDays(3)->toDateString());
    $cancelada = asisSesion($c, 3, 'cancelada');
    $admin = $this->usuarioConRol('admin');
    $en = fn (SesionClase $x, array $items) => $this->panelComo($admin, 'PUT', "academico/sesiones/{$x->identificador}/asistencias", ['asistencias' => $items]);

    $en($s, [['matricula_id' => $anulada->id, 'estado' => 'presente']])->assertStatus(422)->assertJsonValidationErrors('asistencias.0.matricula_id');
    $en($s, [['matricula_id' => $ajena->id, 'estado' => 'presente']])->assertStatus(422)->assertJsonValidationErrors('asistencias.0.matricula_id');
    $en($s, [['matricula_id' => $ok->id, 'estado' => 'volando']])->assertStatus(422)->assertJsonValidationErrors('asistencias.0.estado');
    $en($s, [['matricula_id' => $ok->id, 'estado' => 'presente'], ['matricula_id' => $ok->id, 'estado' => 'ausente']])->assertStatus(422);
    $this->panelComo($admin, 'PUT', "academico/sesiones/{$s->identificador}/asistencias", ['asistencias' => []])->assertStatus(422);
    $en($futura, [['matricula_id' => $ok->id, 'estado' => 'presente']])->assertStatus(409);
    $en($cancelada, [['matricula_id' => $ok->id, 'estado' => 'presente']])->assertStatus(409);

    $c->forceFill(['estado' => 'cerrada'])->save();
    $en($s, [['matricula_id' => $ok->id, 'estado' => 'presente']])->assertStatus(409);
    expect(Asistencia::count())->toBe(0);
});

it('corrige con motivo y audita estados sin el texto libre', function (): void {
    $c = asisEdicion();
    $m = asisMatricula($c, '50000001');
    $s = asisSesion($c, 1);
    $admin = $this->usuarioConRol('admin');
    $coord = $this->usuarioConRol('coordinador', 'c@example.invalid');
    $this->panelComo($admin, 'PUT', "academico/sesiones/{$s->identificador}/asistencias", ['asistencias' => [['matricula_id' => $m->id, 'estado' => 'ausente']]])->assertOk();
    $url = "academico/sesiones/{$s->identificador}/asistencias/{$m->id}";

    $this->panelComo($admin, 'PUT', $url, ['estado' => 'justificada'])->assertStatus(422)->assertJsonValidationErrors('motivo');
    $this->panelComo($admin, 'PUT', $url, ['estado' => 'ausente', 'motivo' => 'x'])->assertStatus(422)->assertJsonValidationErrors('estado');
    $this->panelComo($coord, 'PUT', $url, ['estado' => 'justificada', 'motivo' => 'Presentó certificado médico'])->assertOk()->assertJsonPath('data.estado', 'justificada');
    expect(Asistencia::first()->estado)->toBe('justificada')->and(Asistencia::first()->motivo_correccion)->toBe('Presentó certificado médico');
    $log = AuditLog::where('action', 'asistencia_corregida')->first();
    expect($log->changes['estado_anterior'])->toBe('ausente')->and($log->changes['estado_nuevo'])->toBe('justificada')
        ->and(json_encode($log->changes))->not->toContain('certificado');

    $otra = asisMatricula($c, '50000002');
    $this->panelComo($admin, 'PUT', "academico/sesiones/{$s->identificador}/asistencias/{$otra->id}", ['estado' => 'presente', 'motivo' => 'x'])->assertNotFound();
});

it('lista solo matrículas vigentes de la edición con su asistencia', function (): void {
    $c = asisEdicion();
    $a = asisMatricula($c, '50000001');
    asisMatricula($c, '50000002', 'anulada');
    asisMatricula(asisEdicion(), '50000003');
    $s = asisSesion($c, 1);
    $admin = $this->usuarioConRol('admin');
    $this->panelComo($admin, 'PUT', "academico/sesiones/{$s->identificador}/asistencias", ['asistencias' => [['matricula_id' => $a->id, 'estado' => 'tardanza']]])->assertOk();

    $r = $this->panelComo($admin, 'GET', "academico/sesiones/{$s->identificador}/asistencias")->assertOk();
    $r->assertJsonCount(1, 'data.matriculas')->assertJsonPath('data.matriculas.0.estado', 'tardanza')->assertJsonPath('data.sesion.numero', 1);
    expect($r->headers->get('Cache-Control'))->toContain('no-store');
});

it('calcula el porcentaje: presente y tardanza suman, la falta justificada no penaliza y solo cuentan las sesiones realizadas', function (): void {
    $c = asisEdicion();
    $a = asisMatricula($c, '50000001');
    $b = asisMatricula($c, '50000002');
    $x = asisMatricula($c, '50000003');
    $admin = $this->usuarioConRol('admin');
    $resumen = fn () => $this->panelComo($admin, 'GET', "academico/convocatorias/{$c->identificador}/asistencias/resumen")->assertOk();

    expect($resumen()->json('data.sesiones_realizadas'))->toBe(0)->and($resumen()->json('data.matriculas.0.porcentaje'))->toBeNull();

    $s = [asisSesion($c, 1), asisSesion($c, 2), asisSesion($c, 3), asisSesion($c, 4, 'programada')];
    $marcar = function (SesionClase $sesion, array $estados) use ($admin) {
        $this->panelComo($admin, 'PUT', "academico/sesiones/{$sesion->identificador}/asistencias", ['asistencias' => collect($estados)->map(fn ($e, $id) => ['matricula_id' => $id, 'estado' => $e])->values()->all()])->assertOk();
    };
    $marcar($s[0], [$a->id => 'presente', $b->id => 'presente']);
    $marcar($s[1], [$a->id => 'tardanza', $b->id => 'justificada']);
    $marcar($s[2], [$a->id => 'ausente', $b->id => 'ausente']);
    $marcar($s[3], [$a->id => 'presente']);

    $r = $resumen();
    $r->assertJsonPath('data.sesiones_realizadas', 3);
    $filas = collect($r->json('data.matriculas'))->keyBy('matricula_id');
    expect($filas[$a->id]['porcentaje'])->toEqual(66.67)->and($filas[$a->id]['presentes'])->toBe(1)->and($filas[$a->id]['tardanzas'])->toBe(1)
        ->and($filas[$b->id]['porcentaje'])->toEqual(50)->and($filas[$b->id]['justificadas'])->toBe(1)
        ->and($filas[$x->id]['porcentaje'])->toEqual(0)->and($filas[$x->id]['sin_registro'])->toBe(3);
});

it('exporta un XLSX sin conservarlo y deja constancia', function (): void {
    $c = asisEdicion();
    $m = asisMatricula($c, '50000001');
    $s = asisSesion($c, 1);
    $admin = $this->usuarioConRol('admin');
    $this->panelComo($admin, 'PUT', "academico/sesiones/{$s->identificador}/asistencias", ['asistencias' => [['matricula_id' => $m->id, 'estado' => 'presente']]])->assertOk();

    $r = $this->panelComo($admin, 'POST', "academico/convocatorias/{$c->identificador}/asistencias/exportar")->assertOk();
    expect($r->headers->get('content-disposition'))->toContain('asistencia-')->and($r->headers->get('Cache-Control'))->toContain('no-store')
        ->and(AuditLog::where('action', 'asistencia_exportada')->count())->toBe(1);
});

it('responde 404 ante identificadores mal formados', function (): void {
    $admin = $this->usuarioConRol('admin');
    $this->panelComo($admin, 'GET', 'academico/sesiones/no-es-uuid/asistencias')->assertNotFound();
    $this->panelComo($admin, 'GET', 'academico/convocatorias/no-es-uuid/asistencias/resumen')->assertNotFound();
    $this->panelComo($admin, 'GET', 'academico/sesiones/'.Str::uuid().'/asistencias')->assertNotFound();
});

it('actualiza la observacion cuando se vuelve a enviar el mismo estado sin duplicar filas', function (): void {
    $c = asisEdicion();
    $m = asisMatricula($c, '50000010');
    $s = asisSesion($c, 1);
    $admin = $this->usuarioConRol('admin');

    $this->panelComo($admin, 'PUT', "academico/sesiones/{$s->identificador}/asistencias", [
        'asistencias' => [['matricula_id' => $m->id, 'estado' => 'presente', 'observacion' => 'Inicial']],
    ])->assertOk();

    $this->panelComo($admin, 'PUT', "academico/sesiones/{$s->identificador}/asistencias", [
        'asistencias' => [['matricula_id' => $m->id, 'estado' => 'presente', 'observacion' => 'Actualizada']],
    ])->assertOk();

    expect(Asistencia::count())->toBe(1)
        ->and(Asistencia::first()->observacion)->toBe('Actualizada');
});
