<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Convocatoria;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\ObligacionPago;
use App\Models\TransaccionPago;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Horus Academic, caja del administrador: «Matricular con pago» (matrícula + obligación + pago Yape + captura + confirmación, atómico).
 * SQLite en memoria: los disparadores de inmutabilidad, el CHECK y el bloqueo de filas son de PostgreSQL y NO se prueban aquí.
 */

/** @return array{0: Convocatoria, 1: Estudiante} edición abierta de un curso de S/ 250.00 y un estudiante activo */
function cpEscenario(int $cupos = 20, string $dni = '90000001'): array
{
    static $n = 0;
    $n++;
    $curso = Curso::create(['codigo' => 'CPG-'.$n, 'nombre' => 'Curso de Excel '.$n, 'descripcion' => 'x', 'categoria' => 'tecnologia', 'duracion_horas' => 30,
        'precio' => 250, 'modalidad' => 'virtual', 'horario' => 'Lun', 'cupos_maximos' => 50, 'cupos_disponibles' => 50, 'estado' => 'activo']);
    $c = new Convocatoria(['nombre' => null, 'modalidad' => 'virtual', 'fecha_inicio' => now()->addDay()->toDateString(), 'fecha_fin' => now()->addDays(40)->toDateString(), 'cupos_maximos' => 20]);
    $c->identificador = (string) Str::uuid();
    $c->codigo = 'CNV-2026-3'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    $c->curso_id = $curso->id;
    $c->estado = 'abierta';
    $c->cupos_disponibles = $cupos;
    $c->revision = 1;
    $c->save();

    return [$c, Estudiante::create(['dni' => $dni, 'nombres' => 'Ana', 'apellidos' => 'Quispe', 'estado' => 'activo'])];
}

/** Envía el formulario multipart como lo hace el BFF. */
function cpEnviar(object $t, ?User $usuario, array $datos, ?string $clave = 'clave-caja-0001', bool $conImagen = true)
{
    $cuerpo = $datos + ($conImagen ? ['imagen' => UploadedFile::fake()->image('yape.png', 300, 500)] : []);
    $cabeceras = ['X-Horus-Web-Token' => 'token-de-servicio-del-bff-para-pruebas-0123456789', 'Accept' => 'application/json'] + ($clave === null ? [] : ['Idempotency-Key' => $clave]);

    return (fn () => ($usuario === null ? $this : $this->actingAs($usuario))->post('/api/panel/v1/academico/matriculas/con-pago', $cuerpo, $cabeceras))->call($t);
}

function cpDatos(Convocatoria $c, Estudiante $e, array $extra = []): array
{
    return ['estudiante_id' => $e->id, 'convocatoria' => $c->identificador, 'fecha_pago' => now('America/Lima')->toDateString(), 'referencia' => 'OP-998877', ...$extra];
}

beforeEach(function (): void {
    Storage::fake('local');
    config(['horus-web.service_token' => 'token-de-servicio-del-bff-para-pruebas-0123456789']);
    // Estas pruebas cubren el reverso en sí; la aprobación por umbral tiene su propia prueba (AprobacionReversoTest).
    config(['panel.aprobacion_reverso_umbral' => 0]);
});

it('matricula, cobra, adjunta la captura y confirma en un solo paso, con el precio del curso por defecto', function (): void {
    [$c, $e] = cpEscenario();
    $admin = $this->usuarioConRol('admin');

    $r = cpEnviar($this, $admin, cpDatos($c, $e))->assertCreated();
    $r->assertJsonPath('data.estado', 'pagada')->assertJsonPath('data.monto_pagado', '250.00')->assertJsonMissingPath('data.id');

    $m = Matricula::first();
    expect($m->estado)->toBe('pagada')->and((float) $m->monto_pagado)->toBe(250.0)->and($m->convocatoria_id)->toBe($c->id)
        ->and($c->fresh()->cupos_disponibles)->toBe(19)
        ->and(ObligacionPago::count())->toBe(1)->and(TransaccionPago::first()->estado)->toBe('confirmada')
        ->and(DB::table('comprobantes_pago')->count())->toBe(1)->and(Storage::disk('local')->allFiles('comprobantes'))->toHaveCount(1)
        ->and(ObligacionPago::first()->concepto)->toContain($c->codigo);
    $this->artisan('horus:verificar-finanzas')->expectsOutputToContain('consistente')->assertSuccessful();
    expect(AuditLog::whereIn('action', ['matricula_creada', 'obligacion_pago_creada', 'pago_registrado', 'comprobante_adjuntado', 'pago_confirmado'])->count())->toBe(5)
        ->and(json_encode(AuditLog::all()->map->only(['action', 'changes'])->all()))->not->toContain('OP-998877');
});

it('acepta un importe distinto al precio y lo valida', function (): void {
    [$c, $e] = cpEscenario();
    $admin = $this->usuarioConRol('admin');

    cpEnviar($this, $admin, cpDatos($c, $e, ['monto' => '-5']), 'clave-caja-0001')->assertStatus(422)->assertJsonValidationErrors('monto');
    cpEnviar($this, $admin, cpDatos($c, $e, ['monto' => '10.999']), 'clave-caja-0002')->assertStatus(422)->assertJsonValidationErrors('monto');
    expect(Matricula::count())->toBe(0);
    cpEnviar($this, $admin, cpDatos($c, $e, ['monto' => '180.50']), 'clave-caja-0003')->assertCreated()->assertJsonPath('data.monto_pagado', '180.50');
});

it('es idempotente: misma clave y datos devuelven lo creado; otros datos, 409; sin clave, 428', function (): void {
    [$c, $e] = cpEscenario();
    $admin = $this->usuarioConRol('admin');

    cpEnviar($this, $admin, cpDatos($c, $e))->assertCreated();
    cpEnviar($this, $admin, cpDatos($c, $e))->assertOk()->assertJsonPath('message', 'Esta matrícula con pago ya estaba registrada.');
    cpEnviar($this, $admin, cpDatos($c, $e, ['monto' => '100']))->assertStatus(409);
    expect(Matricula::count())->toBe(1)->and(TransaccionPago::count())->toBe(1)->and($c->fresh()->cupos_disponibles)->toBe(19)
        ->and(Storage::disk('local')->allFiles('comprobantes'))->toHaveCount(1);
    cpEnviar($this, $admin, cpDatos($c, $e), null)->assertStatus(428);
    cpEnviar($this, $admin, cpDatos($c, $e), 'corta')->assertStatus(428);
});

it('si algo falla no queda nada: ni matrícula, ni cupo, ni obligación, ni pago, ni archivo', function (): void {
    $admin = $this->usuarioConRol('admin');

    // Sin cupos: la matrícula falla DENTRO de la transacción, después de guardar la imagen.
    [$sinCupo, $e1] = cpEscenario(cupos: 0, dni: '90000001');
    cpEnviar($this, $admin, cpDatos($sinCupo, $e1))->assertStatus(422);

    // Estudiante ya matriculado en el curso.
    [$c, $e2] = cpEscenario(dni: '90000002');
    cpEnviar($this, $admin, cpDatos($c, $e2), 'clave-caja-0002')->assertCreated();
    $antes = ['m' => Matricula::count(), 'o' => ObligacionPago::count(), 't' => TransaccionPago::count(), 'cupos' => $c->fresh()->cupos_disponibles, 'archivos' => count(Storage::disk('local')->allFiles('comprobantes'))];
    cpEnviar($this, $admin, cpDatos($c, $e2), 'clave-caja-0003')->assertStatus(422);

    expect(Matricula::where('convocatoria_id', $sinCupo->id)->count())->toBe(0)
        ->and(Matricula::count())->toBe($antes['m'])->and(ObligacionPago::count())->toBe($antes['o'])->and(TransaccionPago::count())->toBe($antes['t'])
        ->and($c->fresh()->cupos_disponibles)->toBe($antes['cupos'])->and(Storage::disk('local')->allFiles('comprobantes'))->toHaveCount($antes['archivos']);
});

it('exige la captura y que sea una imagen válida; la fecha no puede ser futura', function (): void {
    [$c, $e] = cpEscenario();
    $admin = $this->usuarioConRol('admin');

    cpEnviar($this, $admin, cpDatos($c, $e), 'clave-caja-0001', false)->assertStatus(422)->assertJsonValidationErrors('imagen');
    cpEnviar($this, $admin, cpDatos($c, $e, ['imagen' => UploadedFile::fake()->create('virus.pdf', 10, 'application/pdf')]), 'clave-caja-0002', false)->assertStatus(422)->assertJsonValidationErrors('imagen');
    cpEnviar($this, $admin, cpDatos($c, $e, ['fecha_pago' => now()->addDays(2)->toDateString()]), 'clave-caja-0003')->assertStatus(422)->assertJsonValidationErrors('fecha_pago');
    expect(Matricula::count())->toBe(0)->and(Storage::disk('local')->allFiles('comprobantes'))->toBe([]);
});

it('solo lo hace el administrador: coordinador, secretaria y anónimos no', function (): void {
    [$c, $e] = cpEscenario();
    $coord = $this->usuarioConRol('coordinador', 'c@example.invalid');
    $sec = $this->usuarioConRol('secretaria', 's@example.invalid');

    cpEnviar($this, null, cpDatos($c, $e))->assertUnauthorized();
    cpEnviar($this, $coord, cpDatos($c, $e))->assertForbidden();
    cpEnviar($this, $sec, cpDatos($c, $e))->assertForbidden();
    expect(Matricula::count())->toBe(0)
        ->and($coord->can('pagos.planificar'))->toBeFalse()->and($coord->can('pagos.confirmar'))->toBeFalse()->and($sec->can('pagos.planificar'))->toBeFalse()->and($sec->can('pagos.confirmar'))->toBeFalse();
});

it('el estado de la matrícula sigue al libro: pagada al cobrar todo y pendiente si se reversa el pago', function (): void {
    [$c, $e] = cpEscenario();
    $admin = $this->usuarioConRol('admin');
    cpEnviar($this, $admin, cpDatos($c, $e))->assertCreated();
    $tx = TransaccionPago::first()->identificador;
    expect(Matricula::first()->estado)->toBe('pagada');

    $this->panelComo($admin, 'POST', "academico/pagos/transacciones/{$tx}/reversar", ['motivo' => 'Yape devuelto al cliente'])->assertOk();
    expect(Matricula::first()->estado)->toBe('pendiente')->and((float) Matricula::first()->monto_pagado)->toBe(0.0)
        ->and(AuditLog::where('action', 'matricula_estado_derivado')->count())->toBe(2);
    $this->artisan('horus:verificar-finanzas')->expectsOutputToContain('consistente')->assertSuccessful();
});

it('rechaza «matricular con pago» si la captura ya respalda otro pago vigente, sin dejar nada creado', function (): void {
    [$c, $e] = cpEscenario(20, '90000011');
    [, $otro] = cpEscenario(20, '90000012');
    $admin = $this->usuarioConRol('admin');

    cpEnviar($this, $admin, cpDatos($c, $e), 'clave-caja-dup-01')->assertCreated();
    $antes = ['matriculas' => Matricula::count(), 'archivos' => count(Storage::disk('local')->allFiles('comprobantes'))];

    // Mismo archivo (mismo tamaño y contenido) para otro estudiante: se rechaza.
    cpEnviar($this, $admin, cpDatos($c, $otro, ['referencia' => 'OP-OTRA']), 'clave-caja-dup-02')
        ->assertStatus(422)->assertJsonValidationErrors('imagen');

    expect(Matricula::count())->toBe($antes['matriculas'])
        ->and(count(Storage::disk('local')->allFiles('comprobantes')))->toBe($antes['archivos'])
        ->and(TransaccionPago::count())->toBe(1);
});
