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
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/*
 * Horus Academic, Fase 7: libro de pagos. SQLite en memoria: los CHECK, los disparadores de inmutabilidad y el bloqueo de filas
 * (`lockForUpdate`) son de PostgreSQL y NO se prueban aquí; las reglas se prueban en el servicio, que es la barrera en SQLite.
 */

// Ninguna prueba de este archivo puede escribir capturas en el disco real (`storage/app/private/comprobantes`).
beforeEach(function (): void {
    Storage::fake('local');
});

function pagoMatricula(string $dni = '60000001'): Matricula
{
    static $n = 0;
    $n++;
    $curso = Curso::create(['codigo' => 'PAG-'.$n, 'nombre' => 'Curso '.$n, 'descripcion' => 'x', 'categoria' => 'tecnologia', 'duracion_horas' => 10,
        'precio' => 100, 'modalidad' => 'virtual', 'horario' => 'Lun', 'cupos_maximos' => 50, 'cupos_disponibles' => 50, 'estado' => 'activo']);
    $c = new Convocatoria(['nombre' => null, 'modalidad' => 'virtual', 'fecha_inicio' => now()->subDay()->toDateString(), 'fecha_fin' => now()->addDays(30)->toDateString(), 'cupos_maximos' => 40]);
    $c->identificador = (string) Str::uuid();
    $c->codigo = 'CNV-2026-6'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    $c->curso_id = $curso->id;
    $c->estado = 'en_curso';
    $c->cupos_disponibles = 40;
    $c->revision = 1;
    $c->save();
    $e = Estudiante::create(['dni' => $dni, 'nombres' => 'Ana', 'apellidos' => 'Quispe', 'estado' => 'activo']);

    return Matricula::create(['estudiante_id' => $e->id, 'curso_id' => $curso->id, 'convocatoria_id' => $c->id, 'fecha_matricula' => now()->toDateString(), 'estado' => 'pendiente', 'monto_pagado' => 0]);
}

function pagoObligacion(object $t, $usuario, Matricula $m, string $plan = 'completo', string $total = '300.00', array $cuotas = []): string
{
    $r = (fn () => $this->panelComo($usuario, 'POST', "academico/matriculas/{$m->id}/obligacion", ['concepto' => 'Matrícula y mensualidades', 'plan' => $plan, 'monto_total' => $total, ...($cuotas ? ['cuotas' => $cuotas] : [])]))->call($t);
    $r->assertCreated();

    return $r->json('data.identificador');
}

function pagoRegistrar(object $t, $usuario, string $obligacion, string $monto, string $clave, array $extra = [])
{
    return (fn () => $this->panelComo($usuario, 'POST', "academico/pagos/obligaciones/{$obligacion}/pagos", ['metodo' => 'yape', 'monto' => $monto, 'fecha_pago' => now('America/Lima')->toDateString(), 'referencia' => 'OP-12345', ...$extra], ['Idempotency-Key' => $clave]))->call($t);
}

/** Adjunta una captura de comprobante (multipart, como lo hace el BFF) y devuelve la respuesta. */
function pagoComprobante(object $t, $usuario, string $tx, ?UploadedFile $archivo = null)
{
    return (fn () => $this->actingAs($usuario)->post('/api/panel/v1/academico/pagos/transacciones/'.$tx.'/comprobante',
        ['imagen' => $archivo ?? UploadedFile::fake()->image('captura.png', 300, 400)],
        ['X-Horus-Web-Token' => self::TOKEN_BFF, 'Accept' => 'application/json']))->call($t);
}

/**
 * Persona con permisos de pagos concedidos A MANO (los roles coordinador y secretaria ya no los traen: solo admin). Modela una
 * delegación explícita del administrador.
 *
 * @param  list<string>  $permisos
 */
function pagoOperador(object $t, string $email, array $permisos): User
{
    (fn () => $this->seed(RolesAndPermissionsSeeder::class))->call($t);
    $u = User::factory()->create(['email' => $email]);
    $u->givePermissionTo(['panel.academico.acceder', ...$permisos]);

    return $u;
}

it('solo admin lleva todos los permisos de pagos: secretaría y coordinación solo ven y registran', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $de = fn (string $rol) => Role::findByName($rol, 'web')->permissions->pluck('name')->filter(fn ($p) => str_starts_with($p, 'pagos.'))->sort()->values()->all();
    expect($de('coordinador'))->toBe(['pagos.registrar', 'pagos.ver'])->and($de('secretaria'))->toBe(['pagos.registrar', 'pagos.ver']);
    expect(Role::findByName('admin', 'web')->hasAllPermissions(RolesAndPermissionsSeeder::PAGOS_PERMISSIONS))->toBeTrue();
});

it('crea las tablas con los métodos manuales (Yape, Plin, transferencia, depósito y efectivo) y una obligación vigente por matrícula', function (): void {
    expect(DB::table('metodos_pago')->pluck('codigo')->sort()->values()->all())->toBe(['deposito', 'efectivo', 'plin', 'transferencia', 'yape']);
    $unicos = collect(Schema::getIndexes('obligaciones_pago'))->filter(fn ($i) => $i['unique'])->pluck('name')->all();
    expect($unicos)->toContain('obligaciones_pago_una_vigente');
});

it('exige autenticación y el permiso de cada operación', function (): void {
    $m = pagoMatricula();
    $admin = $this->usuarioConRol('admin', 'a@example.invalid');
    $coord = pagoOperador($this, 'c@example.invalid', ['pagos.ver', 'pagos.planificar', 'pagos.registrar']);
    $sec = pagoOperador($this, 's@example.invalid', ['pagos.ver', 'pagos.registrar']);
    $this->panelComo(null, 'GET', 'academico/pagos/metodos')->assertUnauthorized();
    $this->panelComo($sec, 'GET', 'academico/pagos/metodos')->assertOk()->assertJsonFragment(['codigo' => 'yape', 'nombre' => 'Yape']);
    $this->panelComo($sec, 'POST', "academico/matriculas/{$m->id}/obligacion", ['concepto' => 'Mensualidad', 'plan' => 'completo', 'monto_total' => '100'])->assertForbidden();

    $id = pagoObligacion($this, $coord, $m);
    pagoRegistrar($this, $sec, $id, '50', 'clave-secretaria-1')->assertCreated();
    $tx = TransaccionPago::first()->identificador;
    $this->panelComo($sec, 'POST', "academico/pagos/transacciones/{$tx}/confirmar")->assertForbidden();
    $this->panelComo($coord, 'POST', "academico/pagos/transacciones/{$tx}/confirmar")->assertForbidden();
    $this->panelComo($coord, 'POST', "academico/pagos/transacciones/{$tx}/rechazar", ['motivo' => 'Motivo suficiente'])->assertForbidden();
    $this->panelComo($coord, 'POST', "academico/pagos/transacciones/{$tx}/reversar", ['motivo' => 'Motivo suficiente'])->assertForbidden();
    $this->panelComo($admin, 'POST', "academico/pagos/transacciones/{$tx}/confirmar")->assertStatus(409);
    pagoComprobante($this, $sec, $tx)->assertOk();
    $this->panelComo($admin, 'POST', "academico/pagos/transacciones/{$tx}/confirmar")->assertOk();
});

it('valida la obligación: totales, cuotas, matrícula y unicidad', function (): void {
    $m = pagoMatricula();
    $admin = $this->usuarioConRol('admin');
    $url = "academico/matriculas/{$m->id}/obligacion";
    $base = ['concepto' => 'Mensualidades', 'plan' => 'fraccionado', 'monto_total' => '300.00'];
    $hoy = now()->toDateString();

    $this->panelComo($admin, 'POST', $url, [...$base, 'monto_total' => '-5'])->assertStatus(422)->assertJsonValidationErrors('monto_total');
    $this->panelComo($admin, 'POST', $url, [...$base, 'monto_total' => '10.999'])->assertStatus(422)->assertJsonValidationErrors('monto_total');
    $this->panelComo($admin, 'POST', $url, [...$base, 'cuotas' => [['monto' => '300', 'vence' => $hoy]]])->assertStatus(422)->assertJsonValidationErrors('cuotas');
    $this->panelComo($admin, 'POST', $url, [...$base, 'cuotas' => [['monto' => '100', 'vence' => $hoy], ['monto' => '100', 'vence' => $hoy]]])->assertStatus(422)->assertJsonValidationErrors('cuotas');
    $this->panelComo($admin, 'POST', $url, [...$base, 'cuotas' => [['monto' => '200', 'vence' => '2026-12-01'], ['monto' => '100', 'vence' => '2026-11-01']]])->assertStatus(422)->assertJsonValidationErrors('cuotas.1.vence');
    $this->panelComo($admin, 'POST', $url, [...$base, 'plan' => 'completo', 'cuotas' => [['monto' => '300', 'vence' => $hoy], ['monto' => '0', 'vence' => $hoy]]])->assertStatus(422);

    $ok = $this->panelComo($admin, 'POST', $url, [...$base, 'cuotas' => [['monto' => '100.50', 'vence' => '2026-12-01'], ['monto' => '199.50', 'vence' => '2027-01-01']]])->assertCreated();
    $ok->assertJsonCount(2, 'data.cuotas')->assertJsonPath('data.monto_total', '300.00')->assertJsonPath('data.estado', 'pendiente')->assertJsonPath('data.saldo', '300.00')->assertJsonMissingPath('data.id');
    $this->panelComo($admin, 'POST', $url, [...$base, 'plan' => 'completo'])->assertStatus(422)->assertJsonValidationErrors('plan');

    $anulada = pagoMatricula('60000002');
    $anulada->forceFill(['estado' => 'anulada'])->save();
    $this->panelComo($admin, 'POST', "academico/matriculas/{$anulada->id}/obligacion", [...$base, 'plan' => 'completo'])->assertStatus(409);
    $this->panelComo($admin, 'POST', 'academico/matriculas/999999/obligacion', [...$base, 'plan' => 'completo'])->assertNotFound();
});

it('registra, confirma y reparte entre cuotas de la más antigua a la más reciente, reflejando monto_pagado', function (): void {
    $m = pagoMatricula();
    $admin = $this->usuarioConRol('admin');
    $sec = pagoOperador($this, 's@example.invalid', ['pagos.ver', 'pagos.registrar']);
    $id = pagoObligacion($this, $admin, $m, 'fraccionado', '300.00', [['monto' => '100', 'vence' => '2026-12-01'], ['monto' => '200', 'vence' => '2027-01-01']]);

    $r = pagoRegistrar($this, $sec, $id, '150.00', 'clave-0001-abcd')->assertCreated();
    $r->assertJsonPath('data.pendiente_confirmar', '150.00')->assertJsonPath('data.pagado', '0.00')->assertJsonPath('data.transacciones.0.estado', 'pendiente');
    expect((float) $m->fresh()->monto_pagado)->toBe(0.0);

    $tx = TransaccionPago::first()->identificador;
    pagoComprobante($this, $sec, $tx)->assertOk();
    $c = $this->panelComo($admin, 'POST', "academico/pagos/transacciones/{$tx}/confirmar")->assertOk();
    $c->assertJsonPath('data.pagado', '150.00')->assertJsonPath('data.saldo', '150.00')->assertJsonPath('data.estado', 'parcial')
        ->assertJsonPath('data.cuotas.0.pagado', '100.00')->assertJsonPath('data.cuotas.0.saldo', '0.00')
        ->assertJsonPath('data.cuotas.1.pagado', '50.00')->assertJsonPath('data.cuotas.1.saldo', '150.00');
    expect((float) $m->fresh()->monto_pagado)->toBe(150.0);

    // Confirmar de nuevo es idempotente.
    $this->panelComo($admin, 'POST', "academico/pagos/transacciones/{$tx}/confirmar")->assertOk();
    expect(DB::table('asignaciones_pago')->count())->toBe(2);

    pagoRegistrar($this, $admin, $id, '150.00', 'clave-0002-abcd', ['confirmar' => true])->assertCreated()->assertJsonPath('data.estado', 'pagada')->assertJsonPath('data.saldo', '0.00');
    expect((float) $m->fresh()->monto_pagado)->toBe(300.0);
});

it('impide sobrepagos, tiene en cuenta lo pendiente y valida método, fecha y referencia', function (): void {
    $m = pagoMatricula();
    $admin = $this->usuarioConRol('admin');
    $id = pagoObligacion($this, $admin, $m);

    pagoRegistrar($this, $admin, $id, '300.01', 'clave-0001-abcd')->assertStatus(422)->assertJsonValidationErrors('monto');
    pagoRegistrar($this, $admin, $id, '0', 'clave-0002-abcd')->assertStatus(422)->assertJsonValidationErrors('monto');
    pagoRegistrar($this, $admin, $id, '100', 'clave-0003-abcd', ['metodo' => 'criptomoneda'])->assertStatus(422)->assertJsonValidationErrors('metodo');
    pagoRegistrar($this, $admin, $id, '100', 'clave-0004-abcd', ['fecha_pago' => now()->addDays(2)->toDateString()])->assertStatus(422)->assertJsonValidationErrors('fecha_pago');
    pagoRegistrar($this, $admin, $id, '100', 'clave-0005-abcd', ['referencia' => '<script>'])->assertStatus(422)->assertJsonValidationErrors('referencia');

    pagoRegistrar($this, $admin, $id, '250', 'clave-0006-abcd')->assertCreated();
    pagoRegistrar($this, $admin, $id, '100', 'clave-0007-abcd')->assertStatus(422)->assertJsonValidationErrors('monto');
    pagoRegistrar($this, $admin, $id, '50', 'clave-0008-abcd')->assertCreated();
    expect(TransaccionPago::count())->toBe(2);
});

it('es idempotente por Idempotency-Key: misma clave y datos devuelve el pago; otros datos, 409; sin clave, 428', function (): void {
    $m = pagoMatricula();
    $admin = $this->usuarioConRol('admin');
    $id = pagoObligacion($this, $admin, $m);

    pagoRegistrar($this, $admin, $id, '100', 'clave-unica-001')->assertCreated();
    pagoRegistrar($this, $admin, $id, '100', 'clave-unica-001')->assertOk()->assertJsonPath('message', 'Este pago ya estaba registrado.');
    pagoRegistrar($this, $admin, $id, '120', 'clave-unica-001')->assertStatus(409);
    expect(TransaccionPago::count())->toBe(1);

    $this->panelComo($admin, 'POST', "academico/pagos/obligaciones/{$id}/pagos", ['metodo' => 'yape', 'monto' => '10', 'fecha_pago' => now('America/Lima')->toDateString()])->assertStatus(428);
    $this->panelComo($admin, 'POST', "academico/pagos/obligaciones/{$id}/pagos", ['metodo' => 'yape', 'monto' => '10', 'fecha_pago' => now('America/Lima')->toDateString()], ['Idempotency-Key' => 'corta'])->assertStatus(428);
});

it('separa funciones: quien registra no confirma, salvo admin; y rechazar exige motivo', function (): void {
    $m = pagoMatricula();
    $admin = $this->usuarioConRol('admin', 'a@example.invalid');
    $otroAdmin = $this->usuarioConRol('admin', 'b@example.invalid');
    $id = pagoObligacion($this, $admin, $m);
    // Un coordinador con permiso de confirmar concedido a mano no puede confirmar lo que él mismo registró.
    $coord = pagoOperador($this, 'c@example.invalid', ['pagos.ver', 'pagos.planificar', 'pagos.registrar']);
    $coord->givePermissionTo('pagos.confirmar');
    pagoRegistrar($this, $coord, $id, '100', 'clave-coord-001')->assertCreated();
    $tx = TransaccionPago::first()->identificador;
    $this->panelComo($coord, 'POST', "academico/pagos/transacciones/{$tx}/confirmar")->assertForbidden();
    $this->panelComo($otroAdmin, 'POST', "academico/pagos/transacciones/{$tx}/rechazar", ['motivo' => 'corto'])->assertStatus(422)->assertJsonValidationErrors('motivo');
    $this->panelComo($otroAdmin, 'POST', "academico/pagos/transacciones/{$tx}/rechazar", ['motivo' => 'No se recibió el Yape'])->assertOk()->assertJsonPath('data.transacciones.0.estado', 'rechazada')->assertJsonPath('data.pendiente_confirmar', '0.00');
    $this->panelComo($otroAdmin, 'POST', "academico/pagos/transacciones/{$tx}/confirmar")->assertStatus(409);

    // Un admin puede confirmar lo que él mismo registró.
    pagoRegistrar($this, $admin, $id, '100', 'clave-admin-001')->assertCreated();
    $propio = TransaccionPago::where('estado', 'pendiente')->first()->identificador;
    pagoComprobante($this, $admin, $propio)->assertOk();
    $this->panelComo($admin, 'POST', "academico/pagos/transacciones/{$propio}/confirmar")->assertOk();
});

it('reversa un pago confirmado con una transacción compensatoria, sin tocar el original', function (): void {
    $m = pagoMatricula();
    $admin = $this->usuarioConRol('admin');
    $id = pagoObligacion($this, $admin, $m);
    pagoRegistrar($this, $admin, $id, '200', 'clave-0001-abcd', ['confirmar' => true])->assertCreated();
    $original = TransaccionPago::first();
    $antes = $original->only(['monto', 'estado', 'fecha_pago', 'referencia']);
    expect((float) $m->fresh()->monto_pagado)->toBe(200.0);

    $url = "academico/pagos/transacciones/{$original->identificador}/reversar";
    $this->panelComo($admin, 'POST', $url, ['motivo' => 'corto'])->assertStatus(422);
    $r = $this->panelComo($admin, 'POST', $url, ['motivo' => 'Yape devuelto al cliente'])->assertOk();
    $r->assertJsonPath('data.pagado', '0.00')->assertJsonPath('data.saldo', '300.00')->assertJsonPath('data.estado', 'pendiente')
        ->assertJsonPath('data.transacciones.0.revertida', true)->assertJsonPath('data.transacciones.1.tipo', 'reverso')
        ->assertJsonPath('data.transacciones.1.reversa_de', $original->identificador)->assertJsonPath('data.cuotas.0.pagado', '0.00');
    expect($original->fresh()->only(['monto', 'estado', 'fecha_pago', 'referencia']))->toBe($antes)
        ->and((float) $m->fresh()->monto_pagado)->toBe(0.0)->and(TransaccionPago::count())->toBe(2);

    // Repetir el reverso no crea otro; un reverso no se reversa; un pago no confirmado no se reversa.
    $this->panelComo($admin, 'POST', $url, ['motivo' => 'Yape devuelto al cliente'])->assertOk();
    expect(TransaccionPago::count())->toBe(2);
    $this->panelComo($admin, 'POST', 'academico/pagos/transacciones/'.TransaccionPago::where('tipo', 'reverso')->first()->identificador.'/reversar', ['motivo' => 'Motivo suficiente'])->assertStatus(409);

    // Tras el reverso se puede volver a cobrar el total.
    pagoRegistrar($this, $admin, $id, '300', 'clave-0002-abcd', ['confirmar' => true])->assertCreated()->assertJsonPath('data.estado', 'pagada');
});

it('anula la obligación solo sin pagos y bloquea anular una matrícula con pagos hasta reversarlos', function (): void {
    $m = pagoMatricula();
    $admin = $this->usuarioConRol('admin');
    $id = pagoObligacion($this, $admin, $m);
    pagoRegistrar($this, $admin, $id, '100', 'clave-0001-abcd', ['confirmar' => true])->assertCreated();

    $this->panelComo($admin, 'POST', "academico/pagos/obligaciones/{$id}/anular", ['motivo' => 'Error de captura inicial'])->assertStatus(409);
    $datos = ['estudiante_id' => $m->estudiante_id, 'curso_id' => $m->curso_id, 'convocatoria_id' => $m->convocatoria_id, 'fecha_matricula' => now()->toDateString(), 'estado' => 'anulada', 'motivo' => 'Anulación bloqueada por pagos'];
    $this->panelComo($admin, 'PUT', "academico/matriculas/{$m->id}", $datos)->assertStatus(409);
    expect($m->fresh()->estado)->toBe('pendiente');

    $tx = TransaccionPago::first()->identificador;
    $this->panelComo($admin, 'POST', "academico/pagos/transacciones/{$tx}/reversar", ['motivo' => 'Se devuelve el Yape'])->assertOk();
    $this->panelComo($admin, 'POST', "academico/pagos/obligaciones/{$id}/anular", ['motivo' => 'Error de captura inicial'])->assertOk()->assertJsonPath('data.estado', 'anulada');
    pagoRegistrar($this, $admin, $id, '10', 'clave-0002-abcd')->assertStatus(409);
    expect(ObligacionPago::first()->estado)->toBe('anulada');
});

it('lista con saldos y filtros, sin ids internos, claves ni referencias en la auditoría', function (): void {
    $m = pagoMatricula();
    $admin = $this->usuarioConRol('admin');
    $id = pagoObligacion($this, $admin, $m);
    pagoRegistrar($this, $admin, $id, '100', 'clave-secreta-001', ['confirmar' => true])->assertCreated();

    $r = $this->panelComo($admin, 'GET', 'academico/pagos/obligaciones')->assertOk();
    $r->assertJsonPath('data.0.pagado', '100.00')->assertJsonPath('data.0.saldo', '200.00')->assertJsonPath('data.0.estado', 'parcial')->assertJsonMissingPath('data.0.id');
    $this->panelComo($admin, 'GET', 'academico/pagos/obligaciones?estado=pagada')->assertJsonCount(0, 'data');
    $this->panelComo($admin, 'GET', 'academico/pagos/obligaciones?busqueda=60000001')->assertJsonCount(1, 'data');
    $detalle = $this->panelComo($admin, 'GET', "academico/pagos/obligaciones/{$id}")->assertOk();
    expect($detalle->getContent())->not->toContain('clave-secreta')->not->toContain('huella');

    $auditoria = json_encode(AuditLog::all()->map->only(['action', 'changes'])->all());
    expect($auditoria)->not->toContain('OP-12345')->not->toContain('clave-secreta')
        ->and(AuditLog::whereIn('action', ['pago_registrado', 'pago_confirmado'])->count())->toBe(2);
});

it('responde 404 ante identificadores mal formados', function (): void {
    $admin = $this->usuarioConRol('admin');
    $this->panelComo($admin, 'GET', 'academico/pagos/obligaciones/no-es-uuid')->assertNotFound();
    $this->panelComo($admin, 'GET', 'academico/pagos/obligaciones/'.Str::uuid())->assertNotFound();
    $this->panelComo($admin, 'POST', 'academico/pagos/transacciones/'.Str::uuid().'/confirmar')->assertNotFound();
});

it('exporta las obligaciones a XLSX solo con pagos.exportar, sin conservarlo y con constancia', function (): void {
    $m = pagoMatricula();
    $admin = $this->usuarioConRol('admin', 'a@example.invalid');
    $coord = pagoOperador($this, 'c@example.invalid', ['pagos.ver', 'pagos.planificar', 'pagos.registrar']);
    pagoObligacion($this, $admin, $m);

    $this->panelComo($coord, 'POST', 'academico/pagos/exportar')->assertForbidden();
    $r = $this->panelComo($admin, 'POST', 'academico/pagos/exportar')->assertOk();
    expect($r->headers->get('content-disposition'))->toContain('pagos-')->and($r->headers->get('Cache-Control'))->toContain('no-store')
        ->and(AuditLog::where('action', 'pagos_exportados')->count())->toBe(1)
        ->and(json_encode(AuditLog::where('action', 'pagos_exportados')->first()->changes))->not->toContain('60000001');
});

it('verifica el libro (solo lectura): consistente tras operar y detecta un monto_pagado alterado', function (): void {
    $m = pagoMatricula();
    $admin = $this->usuarioConRol('admin');
    $id = pagoObligacion($this, $admin, $m, 'fraccionado', '300.00', [['monto' => '100', 'vence' => '2026-12-01'], ['monto' => '200', 'vence' => '2027-01-01']]);
    pagoRegistrar($this, $admin, $id, '150', 'clave-0001-abcd', ['confirmar' => true])->assertCreated();
    $tx = TransaccionPago::first()->identificador;
    $this->panelComo($admin, 'POST', "academico/pagos/transacciones/{$tx}/reversar", ['motivo' => 'Devolución del Yape'])->assertOk();
    pagoRegistrar($this, $admin, $id, '300', 'clave-0002-abcd', ['confirmar' => true])->assertCreated();

    $this->artisan('horus:verificar-finanzas')->expectsOutputToContain('consistente')->assertSuccessful();

    DB::table('matriculas')->where('id', $m->id)->update(['monto_pagado' => 5]);
    $antes = DB::table('transacciones_pago')->count();
    $this->artisan('horus:verificar-finanzas')->expectsOutputToContain('monto_pagado')->assertFailed();
    expect(DB::table('transacciones_pago')->count())->toBe($antes)->and((float) DB::table('matriculas')->where('id', $m->id)->value('monto_pagado'))->toBe(5.0);
});

it('simula el saldo inicial sin escribir nada', function (): void {
    $conLibro = pagoMatricula('60000001');
    $sinLibro = pagoMatricula('60000002');
    $sinLibro->forceFill(['monto_pagado' => 120.5])->save();
    $admin = $this->usuarioConRol('admin');
    pagoObligacion($this, $admin, $conLibro);

    $this->artisan('horus:saldo-inicial-pagos')->expectsOutputToContain('SIMULACIÓN')->expectsOutputToContain('120.50')->assertSuccessful();
    expect(ObligacionPago::count())->toBe(1)->and(TransaccionPago::count())->toBe(0)->and((float) $sinLibro->fresh()->monto_pagado)->toBe(120.5);
});

it('guarda la captura en privado, re-codificada y sin ruta en ninguna respuesta; solo se ve con pagos.ver y se audita', function (): void {
    Storage::fake('local');
    $m = pagoMatricula();
    $admin = $this->usuarioConRol('admin', 'a@example.invalid');
    $sec = pagoOperador($this, 's@example.invalid', ['pagos.ver', 'pagos.registrar']);
    $id = pagoObligacion($this, $admin, $m);
    pagoRegistrar($this, $sec, $id, '100', 'clave-0001-abcd')->assertCreated()->assertJsonPath('data.transacciones.0.tiene_comprobante', false);
    $tx = TransaccionPago::first()->identificador;
    $this->panelComo($sec, 'GET', "academico/pagos/transacciones/{$tx}/comprobante")->assertNotFound();

    $r = pagoComprobante($this, $sec, $tx)->assertOk();
    $r->assertJsonPath('data.transacciones.0.tiene_comprobante', true);
    expect($r->getContent())->not->toContain('comprobantes/')->not->toContain('sha256');
    $fila = DB::table('comprobantes_pago')->first();
    expect(Storage::disk('local')->exists($fila->ruta))->toBeTrue()->and($fila->ruta)->toMatch('#^comprobantes/[0-9a-f-]{36}\.png$#')->and($fila->mime)->toBe('image/png');

    $ver = $this->panelComo($sec, 'GET', "academico/pagos/transacciones/{$tx}/comprobante")->assertOk();
    expect($ver->headers->get('Content-Type'))->toBe('image/png')->and($ver->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($ver->headers->get('Cache-Control'))->toContain('no-store')->and(AuditLog::where('action', 'comprobante_consultado')->count())->toBe(1);
    $sinPermiso = App\Models\User::factory()->create(['email' => 'x@example.invalid']);
    $sinPermiso->givePermissionTo(['panel.academico.acceder']);
    $this->panelComo($sinPermiso, 'GET', "academico/pagos/transacciones/{$tx}/comprobante")->assertForbidden();
    expect(json_encode(AuditLog::all()->map->only(['action', 'changes'])->all()))->not->toContain('comprobantes/');
});

it('rechaza archivos que no son una captura válida y reemplaza borrando el anterior', function (): void {
    Storage::fake('local');
    $m = pagoMatricula();
    $admin = $this->usuarioConRol('admin');
    $id = pagoObligacion($this, $admin, $m);
    pagoRegistrar($this, $admin, $id, '100', 'clave-0001-abcd')->assertCreated();
    $tx = TransaccionPago::first()->identificador;

    pagoComprobante($this, $admin, $tx, UploadedFile::fake()->create('virus.pdf', 10, 'application/pdf'))->assertStatus(422)->assertJsonValidationErrors('imagen');
    pagoComprobante($this, $admin, $tx, UploadedFile::fake()->createWithContent('falso.png', '<?php echo 1;'))->assertStatus(422)->assertJsonValidationErrors('imagen');
    pagoComprobante($this, $admin, $tx, UploadedFile::fake()->image('enorme.png', 300, 400)->size(4000))->assertStatus(422)->assertJsonValidationErrors('imagen');
    pagoComprobante($this, $admin, $tx, UploadedFile::fake()->image('chica.png', 20, 20))->assertStatus(422)->assertJsonValidationErrors('imagen');
    expect(DB::table('comprobantes_pago')->count())->toBe(0)->and(Storage::disk('local')->allFiles('comprobantes'))->toBe([]);

    pagoComprobante($this, $admin, $tx)->assertOk();
    $primera = DB::table('comprobantes_pago')->value('ruta');
    pagoComprobante($this, $admin, $tx, UploadedFile::fake()->image('otra.jpg', 320, 480))->assertOk();
    expect(DB::table('comprobantes_pago')->count())->toBe(1)->and(Storage::disk('local')->exists($primera))->toBeFalse()
        ->and(Storage::disk('local')->allFiles('comprobantes'))->toHaveCount(1);
});

it('solo adjunta a pagos por confirmar y exige el comprobante para confirmar', function (): void {
    Storage::fake('local');
    $m = pagoMatricula();
    $admin = $this->usuarioConRol('admin', 'a@example.invalid');
    $coord = pagoOperador($this, 'c@example.invalid', ['pagos.ver', 'pagos.planificar', 'pagos.registrar']);
    $id = pagoObligacion($this, $admin, $m);
    pagoRegistrar($this, $coord, $id, '100', 'clave-0001-abcd')->assertCreated();
    $tx = TransaccionPago::first()->identificador;

    $this->panelComo($admin, 'POST', "academico/pagos/transacciones/{$tx}/confirmar")->assertStatus(409);
    expect(TransaccionPago::first()->estado)->toBe('pendiente');
    pagoComprobante($this, $coord, $tx)->assertOk();
    $this->panelComo($admin, 'POST', "academico/pagos/transacciones/{$tx}/confirmar")->assertOk();
    pagoComprobante($this, $coord, $tx)->assertStatus(409);
    expect(DB::table('comprobantes_pago')->count())->toBe(1);
});

it('filtra por estado de cobro en SQL antes de paginar y sin consultas N+1', function (): void {
    $admin = $this->usuarioConRol('admin');
    $estados = ['pendiente', 'pendiente', 'pendiente', 'parcial', 'pagada', 'pendiente'];
    foreach ($estados as $i => $tipo) {
        $m = pagoMatricula('6100000'.$i);
        $id = pagoObligacion($this, $admin, $m, 'completo', '200.00');
        if ($tipo === 'parcial') {
            pagoRegistrar($this, $admin, $id, '50.00', 'clave-sql-'.$i, ['confirmar' => true])->assertCreated();
        } elseif ($tipo === 'pagada') {
            pagoRegistrar($this, $admin, $id, '200.00', 'clave-sql-'.$i, ['confirmar' => true])->assertCreated();
        }
    }

    $p1 = $this->panelComo($admin, 'GET', 'academico/pagos/obligaciones?estado=pendiente&per_page=2&page=1')->assertOk();
    $p1->assertJsonPath('meta.total', 4)->assertJsonPath('meta.paginas', 2)->assertJsonCount(2, 'data');
    $p2 = $this->panelComo($admin, 'GET', 'academico/pagos/obligaciones?estado=pendiente&per_page=2&page=2')->assertOk();
    $p2->assertJsonPath('meta.total', 4)->assertJsonPath('meta.paginas', 2)->assertJsonCount(2, 'data');

    $conSaldo = $this->panelComo($admin, 'GET', 'academico/pagos/obligaciones?estado=con_saldo&per_page=10')->assertOk();
    $conSaldo->assertJsonPath('meta.total', 5);

    // Calentamiento de permisos y medición sin N+1 por fila.
    $this->panelComo($admin, 'GET', 'academico/pagos/obligaciones?per_page=2')->assertOk();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->panelComo($admin, 'GET', 'academico/pagos/obligaciones?per_page=2')->assertOk();
    $consultasDosFilas = count(DB::getQueryLog());
    DB::disableQueryLog();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->panelComo($admin, 'GET', 'academico/pagos/obligaciones?per_page=10')->assertOk();
    $consultasSeisFilas = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($consultasSeisFilas)->toBe($consultasDosFilas);
});

it('exporta obligaciones respetando los filtros y expone cabeceras de límite', function (): void {
    $admin = $this->usuarioConRol('admin');
    $m1 = pagoMatricula('62000001');
    $m2 = pagoMatricula('62000002');
    $id1 = pagoObligacion($this, $admin, $m1, 'completo', '100.00');
    pagoObligacion($this, $admin, $m2, 'completo', '100.00');
    pagoRegistrar($this, $admin, $id1, '100.00', 'clave-exp-1', ['confirmar' => true])->assertCreated();

    $r = $this->panelComo($admin, 'POST', 'academico/pagos/exportar?estado=pagada&busqueda=62000001')->assertOk();
    expect($r->headers->get('X-Export-Limit'))->toBe('5000')
        ->and($r->headers->get('X-Export-Truncated'))->toBe('false');

    $archivo = $r->baseResponse->getFile()->getPathname();
    try {
        $hoja = IOFactory::load($archivo)->getActiveSheet();
        expect($hoja->getHighestDataRow())->toBe(2)
            ->and($hoja->getCell('A2')->getValue())->toBe('62000001');
    } finally {
        if (is_file($archivo)) {
            unlink($archivo);
        }
    }
});

/** Registra un pago directamente sobre la matrícula (sin obligación previa). */
function pagoDeMatricula(object $t, $usuario, int $matricula, string $monto, string $clave, array $extra = [])
{
    return (fn () => $this->panelComo($usuario, 'POST', "academico/matriculas/{$matricula}/pagos", ['metodo' => 'yape', 'monto' => $monto, 'fecha_pago' => now('America/Lima')->toDateString(), 'referencia' => 'OP-77001', ...$extra], ['Idempotency-Key' => $clave]))->call($t);
}

it('crea la obligación sola al registrar el primer pago de una matrícula, con el precio del curso', function (): void {
    $m = pagoMatricula();
    $sec = pagoOperador($this, 'sec-auto@example.invalid', ['pagos.ver', 'pagos.registrar']);
    expect(ObligacionPago::query()->count())->toBe(0);

    $r = pagoDeMatricula($this, $sec, $m->id, '40', 'clave-auto-0001')->assertCreated();

    $o = ObligacionPago::query()->sole();
    expect($o->matricula_id)->toBe($m->id)->and($o->plan)->toBe('completo')->and($o->estado)->toBe('vigente')->and(\App\Services\GestionPagos::leer($o->monto_total))->toBe(10000);
    $r->assertJsonPath('data.monto_total', '100.00')->assertJsonPath('data.saldo', '100.00')->assertJsonPath('data.pendiente_confirmar', '40.00');
    expect($r->json('transaccion'))->toBe(TransaccionPago::query()->sole()->identificador);
    expect(AuditLog::query()->where('action', 'obligacion_pago_creada')->count())->toBe(1);
});

it('reutiliza la obligación vigente y repetir la clave no duplica el pago ni la obligación', function (): void {
    $m = pagoMatricula();
    $sec = pagoOperador($this, 'sec-rep@example.invalid', ['pagos.ver', 'pagos.registrar']);

    pagoDeMatricula($this, $sec, $m->id, '30', 'clave-rep-00001')->assertCreated();
    pagoDeMatricula($this, $sec, $m->id, '30', 'clave-rep-00001')->assertOk();
    pagoDeMatricula($this, $sec, $m->id, '20', 'clave-rep-00002')->assertCreated();
    pagoDeMatricula($this, $sec, $m->id, '99', 'clave-rep-00001')->assertStatus(409);

    expect(ObligacionPago::query()->count())->toBe(1)->and(TransaccionPago::query()->count())->toBe(2);
});

it('rechaza el sobrepago sobre una matrícula sin obligación y no deja la obligación creada', function (): void {
    $m = pagoMatricula();
    $sec = pagoOperador($this, 'sec-sobre@example.invalid', ['pagos.ver', 'pagos.registrar']);

    pagoDeMatricula($this, $sec, $m->id, '150', 'clave-sobre-0001')->assertStatus(422)->assertJsonValidationErrors('monto');

    expect(ObligacionPago::query()->count())->toBe(0)->and(TransaccionPago::query()->count())->toBe(0);
});

it('no registra pagos de una matrícula anulada ni de un curso sin precio', function (): void {
    $sec = pagoOperador($this, 'sec-inv@example.invalid', ['pagos.ver', 'pagos.registrar']);
    $anulada = pagoMatricula('60000011');
    $anulada->forceFill(['estado' => 'anulada'])->save();
    pagoDeMatricula($this, $sec, $anulada->id, '10', 'clave-anul-00001')->assertStatus(409);

    $gratis = pagoMatricula('60000012');
    $gratis->curso->forceFill(['precio' => 0])->save();
    pagoDeMatricula($this, $sec, $gratis->id, '10', 'clave-gratis-0001')->assertStatus(422)->assertJsonValidationErrors('monto');

    expect(ObligacionPago::query()->count())->toBe(0);
});

it('el pago por matrícula exige pagos.registrar y la Idempotency-Key, y confirmar sigue siendo de quien tiene pagos.confirmar', function (): void {
    $m = pagoMatricula();
    $soloVer = pagoOperador($this, 'solo-ver@example.invalid', ['pagos.ver']);
    $sec = pagoOperador($this, 'sec-perm@example.invalid', ['pagos.ver', 'pagos.registrar']);

    pagoDeMatricula($this, $soloVer, $m->id, '10', 'clave-perm-00001')->assertForbidden();
    (fn () => $this->panelComo($sec, 'POST', "academico/matriculas/{$m->id}/pagos", ['metodo' => 'yape', 'monto' => '10', 'fecha_pago' => now('America/Lima')->toDateString()]))->call($this)->assertStatus(428);
    pagoDeMatricula($this, $sec, $m->id, '10', 'clave-perm-00002', ['confirmar' => true])->assertForbidden();

    expect(ObligacionPago::query()->count())->toBe(0);
});

it('un pago por matrícula que completa el precio deja la matrícula pagada al confirmarse', function (): void {
    $m = pagoMatricula();
    $admin = $this->usuarioConRol('admin', 'adm-auto@example.invalid');
    $sec = pagoOperador($this, 'sec-total@example.invalid', ['pagos.ver', 'pagos.registrar']);

    $a = pagoDeMatricula($this, $sec, $m->id, '60', 'clave-total-0001')->assertCreated();
    pagoComprobante($this, $sec, $a->json('transaccion'))->assertOk();
    $this->panelComo($admin, 'POST', 'academico/pagos/transacciones/'.$a->json('transaccion').'/confirmar')->assertOk()->assertJsonPath('data.saldo', '40.00');
    expect($m->refresh()->estado)->toBe('pendiente');

    $b = pagoDeMatricula($this, $sec, $m->id, '40', 'clave-total-0002')->assertCreated();
    pagoComprobante($this, $sec, $b->json('transaccion'))->assertOk();
    $this->panelComo($admin, 'POST', 'academico/pagos/transacciones/'.$b->json('transaccion').'/confirmar')->assertOk()->assertJsonPath('data.estado', 'pagada');
    expect($m->refresh()->estado)->toBe('pagada')->and((string) $m->monto_pagado)->toBe('100.00');
});

it('lista los pagos con su comprobante y el saldo pendiente de cada matrícula, con filtros', function (): void {
    $a = pagoMatricula('60000021');
    $b = pagoMatricula('60000022');
    $sec = pagoOperador($this, 'sec-lista@example.invalid', ['pagos.ver', 'pagos.registrar']);

    $p1 = pagoDeMatricula($this, $sec, $a->id, '30', 'clave-lista-0001')->assertCreated();
    pagoComprobante($this, $sec, $p1->json('transaccion'))->assertOk();
    pagoDeMatricula($this, $sec, $b->id, '100', 'clave-lista-0002', ['referencia' => 'OP-SIN-IMG'])->assertCreated();

    $r = $this->panelComo($sec, 'GET', 'academico/pagos/comprobantes')->assertOk();
    expect($r->json('meta.total'))->toBe(2);
    $porDni = collect($r->json('data'))->keyBy('estudiante.dni');
    expect($porDni['60000021']['tiene_comprobante'])->toBeTrue()
        ->and($porDni['60000021']['monto'])->toBe('30.00')
        ->and($porDni['60000021']['saldo'])->toBe('70.00')
        ->and($porDni['60000021']['estado'])->toBe('pendiente')
        ->and($porDni['60000022']['tiene_comprobante'])->toBeFalse()
        ->and($porDni['60000022']['saldo'])->toBe('0.00');

    expect($this->panelComo($sec, 'GET', 'academico/pagos/comprobantes?comprobante=sin')->json('meta.total'))->toBe(1);
    expect($this->panelComo($sec, 'GET', 'academico/pagos/comprobantes?busqueda=60000021')->json('meta.total'))->toBe(1);
    expect($this->panelComo($sec, 'GET', 'academico/pagos/comprobantes?busqueda=OP-SIN-IMG')->json('data.0.estudiante.dni'))->toBe('60000022');
    expect($this->panelComo($sec, 'GET', 'academico/pagos/comprobantes?estado=confirmada')->json('meta.total'))->toBe(0);
    expect($this->panelComo($sec, 'GET', 'academico/pagos/comprobantes?estado=por_confirmar')->json('meta.total'))->toBe(2);
});

it('el listado por comprobante exige autenticación y pagos.ver y no expone claves ni rutas de archivo', function (): void {
    $m = pagoMatricula('60000031');
    $sec = pagoOperador($this, 'sec-ver@example.invalid', ['pagos.ver', 'pagos.registrar']);
    $sinVer = pagoOperador($this, 'sin-ver@example.invalid', ['pagos.registrar']);
    $this->panelComo(null, 'GET', 'academico/pagos/comprobantes')->assertUnauthorized();
    pagoDeMatricula($this, $sec, $m->id, '10', 'clave-ver-000001')->assertCreated();

    $this->panelComo($sinVer, 'GET', 'academico/pagos/comprobantes')->assertForbidden();
    $texto = $this->panelComo($sec, 'GET', 'academico/pagos/comprobantes')->assertOk()->getContent();
    expect($texto)->not->toContain('clave-ver-000001')->and($texto)->not->toContain('comprobantes/');
});

it('acepta métodos manuales como efectivo, y confirmar sigue exigiendo el comprobante adjunto', function (): void {
    $m = pagoMatricula('60000051');
    $admin = $this->usuarioConRol('admin', 'adm-efectivo@example.invalid');

    $r = pagoDeMatricula($this, $admin, $m->id, '100', 'clave-efectivo-001', ['metodo' => 'efectivo', 'referencia' => null])->assertCreated();
    $tx = $r->json('transaccion');
    $this->panelComo($admin, 'POST', "academico/pagos/transacciones/{$tx}/confirmar")->assertStatus(409);
    pagoComprobante($this, $admin, $tx)->assertOk();
    $this->panelComo($admin, 'POST', "academico/pagos/transacciones/{$tx}/confirmar")->assertOk()->assertJsonPath('data.estado', 'pagada');
    pagoDeMatricula($this, $admin, pagoMatricula('60000052')->id, '10', 'clave-efectivo-002', ['metodo' => 'bitcoin'])->assertStatus(422)->assertJsonValidationErrors('metodo');
});

it('avisa cuando la misma captura ya respalda otro pago vigente, sin bloquear, y lo deja en auditoría', function (): void {
    $a = pagoMatricula('60000061');
    $b = pagoMatricula('60000062');
    $sec = pagoOperador($this, 'sec-dup@example.invalid', ['pagos.ver', 'pagos.registrar']);

    $p1 = pagoDeMatricula($this, $sec, $a->id, '30', 'clave-dup-0000001')->assertCreated();
    pagoComprobante($this, $sec, $p1->json('transaccion'), UploadedFile::fake()->image('yape.png', 300, 400))->assertOk()
        ->assertJsonPath('advertencias.imagen_repetida', null);

    // Otra captura distinta: sin aviso.
    $p2 = pagoDeMatricula($this, $sec, $b->id, '40', 'clave-dup-0000002', ['referencia' => 'OP-DUP-2'])->assertCreated();
    pagoComprobante($this, $sec, $p2->json('transaccion'), UploadedFile::fake()->image('otra.png', 320, 410))->assertOk()
        ->assertJsonPath('advertencias.imagen_repetida', null);

    // La MISMA captura del primer pago, subida al segundo: aviso con la matrícula y la fecha del primero.
    $r = pagoComprobante($this, $sec, $p2->json('transaccion'), UploadedFile::fake()->image('yape.png', 300, 400))->assertOk();
    $r->assertJsonPath('advertencias.imagen_repetida.matricula_id', $a->id);
    expect($r->json('advertencias.imagen_repetida.fecha_pago'))->toBe(now('America/Lima')->toDateString())
        ->and(AuditLog::query()->where('action', 'comprobante_imagen_repetida')->count())->toBe(1);

    // Se ve en el detalle y en la lista de comprobantes (en ambos pagos), para quien revisa antes de confirmar.
    $detalle = collect($r->json('data.transacciones'))->firstWhere('identificador', $p2->json('transaccion'));
    expect($detalle['imagen_repetida']['matricula_id'])->toBe($a->id);
    $lista = collect($this->panelComo($sec, 'GET', 'academico/pagos/comprobantes')->assertOk()->json('data'))->keyBy('matricula_id');
    expect($lista[$b->id]['imagen_repetida']['matricula_id'])->toBe($a->id)->and($lista[$a->id]['imagen_repetida']['matricula_id'])->toBe($b->id);
});

it('una captura repetida no se señala si el otro pago fue rechazado o reversado', function (): void {
    $a = pagoMatricula('60000071');
    $b = pagoMatricula('60000072');
    $admin = $this->usuarioConRol('admin', 'adm-dup@example.invalid');
    $imagen = fn () => UploadedFile::fake()->image('yape.png', 300, 400);

    $p1 = pagoDeMatricula($this, $admin, $a->id, '30', 'clave-dup-1000001')->assertCreated();
    pagoComprobante($this, $admin, $p1->json('transaccion'), $imagen())->assertOk();
    $this->panelComo($admin, 'POST', 'academico/pagos/transacciones/'.$p1->json('transaccion').'/rechazar', ['motivo' => 'Comprobante ilegible o incompleto'])->assertOk();

    $p2 = pagoDeMatricula($this, $admin, $b->id, '40', 'clave-dup-1000002')->assertCreated();
    pagoComprobante($this, $admin, $p2->json('transaccion'), $imagen())->assertOk()->assertJsonPath('advertencias.imagen_repetida', null);

    // Un pago confirmado y luego reversado tampoco cuenta.
    $c = pagoMatricula('60000073');
    $p3 = pagoDeMatricula($this, $admin, $c->id, '20', 'clave-dup-1000003')->assertCreated();
    pagoComprobante($this, $admin, $p3->json('transaccion'), UploadedFile::fake()->image('otro.png', 330, 420))->assertOk();
    $this->panelComo($admin, 'POST', 'academico/pagos/transacciones/'.$p3->json('transaccion').'/confirmar')->assertOk();
    $this->panelComo($admin, 'POST', 'academico/pagos/transacciones/'.$p3->json('transaccion').'/reversar', ['motivo' => 'Yape devuelto al cliente'])->assertOk();
    $d = pagoMatricula('60000074');
    $p4 = pagoDeMatricula($this, $admin, $d->id, '20', 'clave-dup-1000004')->assertCreated();
    pagoComprobante($this, $admin, $p4->json('transaccion'), UploadedFile::fake()->image('otro.png', 330, 420))->assertOk()->assertJsonPath('advertencias.imagen_repetida', null);
});
