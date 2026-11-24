<?php

declare(strict_types=1);

use App\Models\AprobacionInterna;
use App\Models\AuditLog;
use App\Models\Convocatoria;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Services\GestionAprobaciones;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * Plan de mejora del panel, Fase 3.6: reapertura de un acta cerrada con motivo, aprobación de Gerencia (rol admin) y un solo uso.
 */

function reaEdicionCerrada(): Convocatoria
{
    $curso = Curso::query()->firstOrCreate(['codigo' => 'REA-1'], ['nombre' => 'Curso reapertura', 'descripcion' => 'x', 'categoria' => 'tecnologia', 'duracion_horas' => 20,
        'precio' => 300, 'modalidad' => 'virtual', 'horario' => 'Lun', 'cupos_maximos' => 10, 'cupos_disponibles' => 10, 'estado' => 'activo']);
    $c = new Convocatoria(['nombre' => null, 'modalidad' => 'virtual', 'fecha_inicio' => now()->subDays(30)->toDateString(),
        'fecha_fin' => now()->subDay()->toDateString(), 'cupos_maximos' => 10]);
    $c->identificador = (string) Str::uuid();
    $c->codigo = 'CNV-2026-'.random_int(1000, 9999);
    $c->curso_id = $curso->id;
    $c->estado = 'cerrada';
    $c->cupos_disponibles = 10;
    $c->revision = 3;
    $c->save();
    $e = Estudiante::create(['dni' => (string) random_int(70000000, 79999999), 'nombres' => 'Rea', 'apellidos' => 'Acta', 'estado' => 'activo']);
    Matricula::create(['estudiante_id' => $e->id, 'curso_id' => $curso->id, 'convocatoria_id' => $c->id, 'fecha_matricula' => now()->toDateString(),
        'estado' => 'pagada', 'monto_pagado' => 0, 'nota_final' => '15.00', 'resultado' => 'aprobado']);

    return $c;
}

const REA_MOTIVO = 'Se registró mal una nota del examen final';

it('solicitar exige edición cerrada, motivo y una sola solicitud pendiente', function (): void {
    $c = reaEdicionCerrada();
    $u = $this->usuarioConRol('coordinador');
    $url = "academico/convocatorias/{$c->identificador}/acta/reapertura";

    $this->panelComo($u, 'POST', $url, ['motivo' => 'corto'])->assertStatus(422);
    $this->panelComo($u, 'POST', $url, ['motivo' => REA_MOTIVO])->assertCreated();
    $this->panelComo($u, 'POST', $url, ['motivo' => REA_MOTIVO])->assertStatus(409);

    $abierta = reaEdicionCerrada();
    $abierta->forceFill(['estado' => 'en_curso'])->save();
    $this->panelComo($u, 'POST', "academico/convocatorias/{$abierta->identificador}/acta/reapertura", ['motivo' => REA_MOTIVO])->assertStatus(409);

    $solicitud = AprobacionInterna::query()->firstOrFail();
    expect($solicitud->tipo)->toBe('reapertura_acta')->and($solicitud->estado)->toBe('pendiente')->and($solicitud->descripcion)->toBe(REA_MOTIVO);
    expect(AuditLog::query()->where('action', 'acta_reapertura_solicitada')->count())->toBe(1);
});

it('sin aprobación de Gerencia no se reabre; con una rechazada tampoco', function (): void {
    $c = reaEdicionCerrada();
    $coord = $this->usuarioConRol('coordinador');
    $admin = $this->usuarioConRol('admin');

    $this->panelComo($coord, 'POST', "academico/convocatorias/{$c->identificador}/acta/reabrir")->assertStatus(409);

    $this->panelComo($coord, 'POST', "academico/convocatorias/{$c->identificador}/acta/reapertura", ['motivo' => REA_MOTIVO])->assertCreated();
    $this->panelComo($coord, 'POST', "academico/convocatorias/{$c->identificador}/acta/reabrir")->assertStatus(409);

    app(GestionAprobaciones::class)->rechazar($admin, (int) AprobacionInterna::query()->firstOrFail()->id, 'No procede');
    $this->panelComo($coord, 'POST', "academico/convocatorias/{$c->identificador}/acta/reabrir")->assertStatus(409);
    expect($c->fresh()->estado)->toBe('cerrada');
});

it('con la aprobación reabre: edición en curso, notas finales limpias, revisión +1, y la aprobación sirve una sola vez', function (): void {
    $c = reaEdicionCerrada();
    $coord = $this->usuarioConRol('coordinador');
    $admin = $this->usuarioConRol('admin');

    $this->panelComo($coord, 'POST', "academico/convocatorias/{$c->identificador}/acta/reapertura", ['motivo' => REA_MOTIVO])->assertCreated();
    app(GestionAprobaciones::class)->aprobar($admin, (int) AprobacionInterna::query()->firstOrFail()->id, 'Aprobado');

    $this->panelComo($coord, 'POST', "academico/convocatorias/{$c->identificador}/acta/reabrir")->assertOk();

    $c->refresh();
    $m = Matricula::query()->where('convocatoria_id', $c->id)->firstOrFail();
    expect($c->estado)->toBe('en_curso')->and($c->revision)->toBe(4)
        ->and($m->nota_final)->toBeNull()->and($m->resultado)->toBeNull();
    $auditoria = AuditLog::query()->where('action', 'acta_reabierta')->firstOrFail();
    expect($auditoria->user_id)->toBe($coord->id)->and($auditoria->changes['matriculas'])->toBe(1);

    // Misma aprobación, otra vez cerrada: ya fue usada.
    $c->forceFill(['estado' => 'cerrada'])->save();
    $this->panelComo($coord, 'POST', "academico/convocatorias/{$c->identificador}/acta/reabrir")->assertStatus(409);
});

it('el solicitante no aprueba su propia solicitud y solo Gerencia (admin) resuelve', function (): void {
    $c = reaEdicionCerrada();
    $admin = $this->usuarioConRol('admin');
    $this->panelComo($admin, 'POST', "academico/convocatorias/{$c->identificador}/acta/reapertura", ['motivo' => REA_MOTIVO])->assertCreated();
    $id = (int) AprobacionInterna::query()->firstOrFail()->id;

    expect(fn () => app(GestionAprobaciones::class)->aprobar($admin, $id, null))->toThrow(HttpException::class);

    $coord = $this->usuarioConRol('coordinador');
    expect(fn () => app(GestionAprobaciones::class)->aprobar($coord, $id, null))->toThrow(HttpException::class);
    expect(AprobacionInterna::query()->findOrFail($id)->estado)->toBe('pendiente');
});

it('secretaría no puede solicitar ni reabrir', function (): void {
    $c = reaEdicionCerrada();
    $s = $this->usuarioConRol('secretaria');

    $this->panelComo($s, 'POST', "academico/convocatorias/{$c->identificador}/acta/reapertura", ['motivo' => REA_MOTIVO])->assertForbidden();
    $this->panelComo($s, 'POST', "academico/convocatorias/{$c->identificador}/acta/reabrir")->assertForbidden();
});
