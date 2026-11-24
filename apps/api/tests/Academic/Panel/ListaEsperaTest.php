<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Convocatoria;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\ListaEspera;
use App\Models\Matricula;
use App\Models\NotificacionInterna;
use App\Services\RecordatoriosService;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Plan de mejora del panel, Fase 3.7: lista de espera de una edición llena, en orden de llegada, con aviso diario cuando hay cupo.
 */

function lisEdicion(int $libres = 0, string $estado = 'abierta'): Convocatoria
{
    $curso = Curso::query()->firstOrCreate(['codigo' => 'LIS-1'], ['nombre' => 'Curso espera', 'descripcion' => 'x', 'categoria' => 'tecnologia', 'duracion_horas' => 20,
        'precio' => 300, 'modalidad' => 'virtual', 'horario' => 'Lun', 'cupos_maximos' => 10, 'cupos_disponibles' => 10, 'estado' => 'activo']);
    $c = new Convocatoria(['nombre' => null, 'modalidad' => 'virtual', 'fecha_inicio' => now()->addDays(10)->toDateString(),
        'fecha_fin' => now()->addDays(40)->toDateString(), 'cupos_maximos' => 5]);
    $c->identificador = (string) Str::uuid();
    $c->codigo = 'CNV-2026-'.random_int(1000, 9999);
    $c->curso_id = $curso->id;
    $c->estado = $estado;
    $c->cupos_disponibles = $libres;
    $c->save();

    return $c;
}

function lisEstudiante(string $estado = 'activo'): Estudiante
{
    return Estudiante::create(['dni' => (string) random_int(70000000, 79999999), 'nombres' => 'Es', 'apellidos' => 'Pera', 'estado' => $estado]);
}

it('agrega en orden de llegada y lista con posición', function (): void {
    $c = lisEdicion();
    $u = $this->usuarioConRol('secretaria');
    $a = lisEstudiante();
    $b = lisEstudiante();

    $this->panelComo($u, 'POST', "academico/convocatorias/{$c->identificador}/lista-espera", ['estudiante_id' => $a->id, 'nota' => 'Prefiere noche'])->assertCreated()->assertJsonPath('data.posicion', 1);
    $this->panelComo($u, 'POST', "academico/convocatorias/{$c->identificador}/lista-espera", ['estudiante_id' => $b->id])->assertCreated()->assertJsonPath('data.posicion', 2);

    $r = $this->panelComo($u, 'GET', "academico/convocatorias/{$c->identificador}/lista-espera")->assertOk();
    expect($r->json('data.total'))->toBe(2)->and($r->json('data.hay_cupo'))->toBeFalse()
        ->and($r->json('data.items.0.posicion'))->toBe(1)->and($r->json('data.items.0.nota'))->toBe('Prefiere noche')
        ->and($r->json('data.items.1.estudiante.id'))->toBe($b->id);
    expect(AuditLog::query()->where('action', 'lista_espera_agregada')->count())->toBe(2);
});

it('rechaza si hay cupo, si la edición no admite matrículas, si ya espera o ya está matriculado, o el estudiante está inactivo', function (): void {
    $u = $this->usuarioConRol('secretaria');
    $e = lisEstudiante();

    $conCupo = lisEdicion(2);
    $this->panelComo($u, 'POST', "academico/convocatorias/{$conCupo->identificador}/lista-espera", ['estudiante_id' => $e->id])->assertStatus(409);

    $cerrada = lisEdicion(0, 'cerrada');
    $this->panelComo($u, 'POST', "academico/convocatorias/{$cerrada->identificador}/lista-espera", ['estudiante_id' => $e->id])->assertStatus(409);

    $llena = lisEdicion();
    $url = "academico/convocatorias/{$llena->identificador}/lista-espera";
    $this->panelComo($u, 'POST', $url, ['estudiante_id' => lisEstudiante('inactivo')->id])->assertStatus(409);
    $this->panelComo($u, 'POST', $url, ['estudiante_id' => $e->id])->assertCreated();
    $this->panelComo($u, 'POST', $url, ['estudiante_id' => $e->id])->assertStatus(409);

    $matriculado = lisEstudiante();
    Matricula::create(['estudiante_id' => $matriculado->id, 'curso_id' => $llena->curso_id, 'convocatoria_id' => $llena->id, 'fecha_matricula' => now()->toDateString(), 'estado' => 'pendiente', 'monto_pagado' => 0]);
    $this->panelComo($u, 'POST', $url, ['estudiante_id' => $matriculado->id])->assertStatus(409);
    $this->panelComo($u, 'POST', $url, [])->assertStatus(422);
});

it('quitar saca a la persona, las posiciones se corren y no se repite', function (): void {
    $c = lisEdicion();
    $u = $this->usuarioConRol('secretaria');
    $a = lisEstudiante();
    $b = lisEstudiante();
    $url = "academico/convocatorias/{$c->identificador}/lista-espera";
    $this->panelComo($u, 'POST', $url, ['estudiante_id' => $a->id])->assertCreated();
    $this->panelComo($u, 'POST', $url, ['estudiante_id' => $b->id])->assertCreated();
    $primera = ListaEspera::query()->where('estudiante_id', $a->id)->firstOrFail();

    $this->panelComo($u, 'DELETE', "{$url}/{$primera->identificador}")->assertOk();
    $this->panelComo($u, 'DELETE', "{$url}/{$primera->identificador}")->assertStatus(409);
    expect($this->panelComo($u, 'GET', $url)->json('data.items.0.posicion'))->toBe(1);

    // Quien salió puede volver a anotarse.
    $this->panelComo($u, 'POST', $url, ['estudiante_id' => $a->id])->assertCreated()->assertJsonPath('data.posicion', 2);
    expect(AuditLog::query()->where('action', 'lista_espera_quitada')->count())->toBe(1);
});

it('quien se matricula sale de la lista al listar', function (): void {
    $c = lisEdicion();
    $u = $this->usuarioConRol('secretaria');
    $a = lisEstudiante();
    $url = "academico/convocatorias/{$c->identificador}/lista-espera";
    $this->panelComo($u, 'POST', $url, ['estudiante_id' => $a->id])->assertCreated();

    Matricula::create(['estudiante_id' => $a->id, 'curso_id' => $c->curso_id, 'convocatoria_id' => $c->id, 'fecha_matricula' => now()->toDateString(), 'estado' => 'pendiente', 'monto_pagado' => 0]);

    expect($this->panelComo($u, 'GET', $url)->json('data.total'))->toBe(0);
    expect(ListaEspera::query()->firstOrFail()->estado)->toBe('matriculado');
});

it('permisos: ver con convocatorias.ver, escribir solo con matriculas.crear', function (): void {
    $c = lisEdicion();
    $url = "academico/convocatorias/{$c->identificador}/lista-espera";
    $e = lisEstudiante();

    $this->panelComo(null, 'GET', $url)->assertUnauthorized();
    $this->panelComo($this->usuarioConRol('secretaria'), 'GET', $url)->assertOk();
    $docente = $this->usuarioConRol('secretaria');
    $rol = Role::findByName('secretaria');
    $rol->revokePermissionTo('matriculas.crear');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->panelComo($docente->fresh(), 'POST', $url, ['estudiante_id' => $e->id])->assertForbidden();
});

it('el aviso diario avisa una vez al día cuando hay cupo y personas esperando', function (): void {
    config(['recordatorios.internos_enabled' => true]);
    $c = lisEdicion();
    $u = $this->usuarioConRol('secretaria');
    $this->panelComo($u, 'POST', "academico/convocatorias/{$c->identificador}/lista-espera", ['estudiante_id' => lisEstudiante()->id])->assertCreated();

    // Sin cupo no hay aviso.
    expect(app(RecordatoriosService::class)->ejecutar(false, 200)['espera'])->toBe(0);

    $c->forceFill(['cupos_disponibles' => 1])->save();
    expect(app(RecordatoriosService::class)->ejecutar(false, 200)['espera'])->toBe(1);
    $avisos = NotificacionInterna::query()->where('tipo', 'recordatorio_espera')->count();
    expect($avisos)->toBeGreaterThan(0);

    // El mismo día no se repite.
    app(RecordatoriosService::class)->ejecutar(false, 200);
    expect(NotificacionInterna::query()->where('tipo', 'recordatorio_espera')->count())->toBe($avisos);
});
