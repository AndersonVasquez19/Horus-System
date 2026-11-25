<?php

declare(strict_types=1);

use App\Models\AprobacionInterna;
use App\Models\AuditLog;
use App\Models\Calificacion;
use App\Models\ChatIntent;
use App\Models\ChatLog;
use App\Models\Convocatoria;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Evaluacion;
use App\Models\Matricula;
use App\Models\MetodoPago;
use App\Models\NotificacionInterna;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PreparaReclamaciones;

uses(PreparaReclamaciones::class);

test('pagos filtra por_confirmar y vencidas exactas sin falsos positivos, expone proxima_cuota y genera recibo PDF', function (): void {
    $admin = $this->usuarioConRol('admin');
    MetodoPago::query()->firstOrCreate(['codigo' => 'yape'], ['nombre' => 'Yape', 'activo' => true]);

    $curso = Curso::create([
        'codigo' => 'PAG2F-1', 'nombre' => 'Curso Pagos 2F', 'descripcion' => 'x', 'categoria' => 'tecnologia',
        'duracion_horas' => 12, 'precio' => '200.00', 'modalidad' => 'virtual', 'horario' => 'Lun',
        'cupos_maximos' => 20, 'cupos_disponibles' => 20, 'estado' => 'activo',
    ]);
    $conv = new Convocatoria([
        'nombre' => null, 'modalidad' => 'virtual', 'fecha_inicio' => now()->subDay()->toDateString(),
        'fecha_fin' => now()->addDays(30)->toDateString(), 'cupos_maximos' => 20,
    ]);
    $conv->identificador = (string) Str::uuid();
    $conv->codigo = 'CNV-2026-9902';
    $conv->curso_id = $curso->id;
    $conv->estado = 'en_curso';
    $conv->cupos_disponibles = 20;
    $conv->revision = 1;
    $conv->save();

    $estA = Estudiante::create(['dni' => '75000001', 'nombres' => 'Ana', 'apellidos' => 'Vencida', 'estado' => 'activo']);
    $estB = Estudiante::create(['dni' => '75000002', 'nombres' => 'Luis', 'apellidos' => 'AlDia', 'estado' => 'activo']);

    $mA = Matricula::create(['estudiante_id' => $estA->id, 'curso_id' => $curso->id, 'convocatoria_id' => $conv->id, 'fecha_matricula' => now()->toDateString(), 'estado' => 'pendiente', 'monto_pagado' => 0]);
    $mB = Matricula::create(['estudiante_id' => $estB->id, 'curso_id' => $curso->id, 'convocatoria_id' => $conv->id, 'fecha_matricula' => now()->toDateString(), 'estado' => 'pendiente', 'monto_pagado' => 0]);

    $ayer = now('America/Lima')->subDay()->toDateString();
    $manana = now('America/Lima')->addDays(15)->toDateString();
    $pasado = now('America/Lima')->addDays(30)->toDateString();

    // Obligación A: cuota 1 vencida SIN pagar (se mueve a ayer tras crearla) + pago pendiente por confirmar
    $obA = $this->panelComo($admin, 'POST', "academico/matriculas/{$mA->id}/obligacion", [
        'concepto' => 'Matrícula A', 'plan' => 'fraccionado', 'monto_total' => '200.00',
        'cuotas' => [['monto' => '100.00', 'vence' => $manana], ['monto' => '100.00', 'vence' => $pasado]],
    ])->assertCreated()->json('data.identificador');

    DB::table('cuotas_obligacion')
        ->whereIn('obligacion_pago_id', fn ($q) => $q->select('id')->from('obligaciones_pago')->where('identificador', $obA))
        ->where('numero', 1)
        ->update(['vence' => $ayer]);

    $this->panelComo($admin, 'POST', "academico/pagos/obligaciones/{$obA}/pagos", [
        'metodo' => 'yape', 'monto' => '100.00', 'fecha_pago' => now('America/Lima')->toDateString(),
    ], ['Idempotency-Key' => 'clave-2f-0001'])->assertCreated();

    // Obligación B: cuota 1 vencida YA pagada y confirmada; cuota 2 aún vigente
    $obB = $this->panelComo($admin, 'POST', "academico/matriculas/{$mB->id}/obligacion", [
        'concepto' => 'Matrícula B', 'plan' => 'fraccionado', 'monto_total' => '200.00',
        'cuotas' => [['monto' => '100.00', 'vence' => $manana], ['monto' => '100.00', 'vence' => $pasado]],
    ])->assertCreated()->json('data.identificador');

    DB::table('cuotas_obligacion')
        ->whereIn('obligacion_pago_id', fn ($q) => $q->select('id')->from('obligaciones_pago')->where('identificador', $obB))
        ->where('numero', 1)
        ->update(['vence' => $ayer]);

    Storage::fake('local');
    $txB = $this->panelComo($admin, 'POST', "academico/pagos/obligaciones/{$obB}/pagos", [
        'metodo' => 'yape', 'monto' => '100.00', 'fecha_pago' => now('America/Lima')->toDateString(),
    ], ['Idempotency-Key' => 'clave-2f-0002'])->assertCreated()->json('data.transacciones.0.identificador');
    $this->actingAs($admin)->post("/api/panel/v1/academico/pagos/transacciones/{$txB}/comprobante", [
        'imagen' => UploadedFile::fake()->image('yape.png', 300, 400),
    ], ['X-Horus-Web-Token' => self::TOKEN_BFF, 'Accept' => 'application/json'])->assertOk();
    $this->panelComo($admin, 'POST', "academico/pagos/transacciones/{$txB}/confirmar")->assertOk();

    // Filtro por_confirmar: solo A
    $this->panelComo($admin, 'GET', 'academico/pagos/obligaciones?estado=por_confirmar')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.identificador', $obA);

    // Filtro vencidas: solo A (B ya pagó su cuota vencida, así que no debe aparecer)
    $rVencidas = $this->panelComo($admin, 'GET', 'academico/pagos/obligaciones?estado=vencidas')->assertOk();
    $rVencidas->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.identificador', $obA)
        ->assertJsonPath('data.0.proxima_cuota.numero', 1)
        ->assertJsonPath('data.0.proxima_cuota.vencida', true);

    // Próxima cuota de B apunta a la cuota 2 (no vencida)
    $this->panelComo($admin, 'GET', 'academico/pagos/obligaciones?busqueda=75000002')
        ->assertOk()
        ->assertJsonPath('data.0.proxima_cuota.numero', 2)
        ->assertJsonPath('data.0.proxima_cuota.vencida', false);

    // Recibo PDF
    $pdf = $this->panelComo($admin, 'GET', "academico/pagos/obligaciones/{$obB}/recibo")->assertOk();
    expect($pdf->headers->get('Content-Type'))->toContain('application/pdf')
        ->and(substr($pdf->getContent(), 0, 4))->toBe('%PDF')
        ->and(AuditLog::where('action', 'pago_recibo_pdf')->count())->toBe(1);
});

test('reclamaciones enlaza notificaciones al expediente, filtra responsable=mio, asigna en lote y vincula estudiante', function (): void {
    Queue::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->habilitarReclamaciones();

    $admin = $this->usuarioConRol('admin');
    $coord = $this->usuarioConRol('coordinador');
    $lectura = $this->usuarioConRol('secretaria');

    $coord->givePermissionTo('reclamaciones.ver');
    $rolSecretaria = Role::findByName('secretaria', 'web');
    $rolSecretaria->syncPermissions([RolesAndPermissionsSeeder::PANEL_ACADEMICO, 'reclamaciones.ver']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $est = Estudiante::create(['dni' => '75111222', 'nombres' => 'Rosa', 'apellidos' => 'Paredes', 'estado' => 'activo']);

    $rec1 = $this->registrar(['numero_documento' => '75111222'])['reclamacion'];
    $rec2 = $this->registrar(['numero_documento' => '75999888'])['reclamacion'];

    // La notificación interna generada enlaza directamente al UUID del expediente
    $notif = NotificacionInterna::query()->where('tipo', 'reclamacion')->latest('id')->first();
    expect($notif?->vinculo)->toBe('/admin/academico/reclamaciones/'.$rec2->identificador);

    // Sin permiso reclamaciones.gestionar -> 403 en asignar-lote
    $this->panelComo($lectura, 'POST', 'compartido/reclamaciones/asignar-lote', [
        'identificadores' => [$rec1->identificador],
        'responsable_id' => $coord->id,
    ])->assertForbidden();

    // Asignación masiva a coordinador
    $this->panelComo($admin, 'POST', 'compartido/reclamaciones/asignar-lote', [
        'identificadores' => [$rec1->identificador, $rec2->identificador],
        'responsable_id' => $coord->id,
    ])->assertOk()->assertJsonPath('actualizadas', 2);

    // Filtro responsable=mio como coordinador devuelve ambos casos
    $this->panelComo($coord, 'GET', 'compartido/reclamaciones?responsable=mio')
        ->assertOk()->assertJsonCount(2, 'data');

    // En show: admin con estudiantes.ver ve estudiante_relacionado; lectura sin estudiantes.ver recibe null
    $this->panelComo($admin, 'GET', "compartido/reclamaciones/{$rec1->identificador}")
        ->assertOk()->assertJsonPath('meta.estudiante_relacionado.id', $est->id);
    $this->panelComo($lectura, 'GET', "compartido/reclamaciones/{$rec1->identificador}")
        ->assertOk()->assertJsonPath('meta.estudiante_relacionado', null);
});

test('aprobaciones filtra alcance para_mi/mias/todas y guarda comentario al aprobar', function (): void {
    $admin = $this->usuarioConRol('admin');
    $coord = $this->usuarioConRol('coordinador');

    $compraAjena = AprobacionInterna::create([
        'titulo' => 'Compra de equipo', 'tipo' => AprobacionInterna::TIPO_COMPRA,
        'estado' => AprobacionInterna::ESTADO_PENDIENTE, 'solicitante_id' => $coord->id,
    ]);
    $operativaPropia = AprobacionInterna::create([
        'titulo' => 'Cambio operativo', 'tipo' => AprobacionInterna::TIPO_OPERATIVO,
        'estado' => AprobacionInterna::ESTADO_PENDIENTE, 'solicitante_id' => $admin->id,
    ]);

    // Admin tiene autoridad sobre `compra` y no solicitó compraAjena -> para_mi devuelve solo esa
    $this->panelComo($admin, 'GET', 'compartido/aprobaciones?alcance=para_mi')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $compraAjena->id);

    // mias del admin devuelve operativaPropia
    $this->panelComo($admin, 'GET', 'compartido/aprobaciones?alcance=mias')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $operativaPropia->id);

    // Aprobar con comentario
    $this->panelComo($admin, 'POST', "compartido/aprobaciones/{$compraAjena->id}/aprobar", [
        'comentario' => 'Aprobado con presupuesto vigente.',
    ])->assertOk()->assertJsonPath('data.comentario', 'Aprobado con presupuesto vigente.');
});

test('asistente pagina registros con meta y estado de horus_ai', function (): void {
    $admin = $this->usuarioConRol('admin');
    ChatIntent::create(['nombre' => 'saludo', 'keywords' => ['hola'], 'respuesta' => 'Hola', 'prioridad' => 10, 'activo' => true]);
    foreach (range(1, 5) as $i) {
        ChatLog::create(['session_id' => "s-{$i}", 'mensaje_usuario' => "Msg {$i}", 'respuesta_bot' => "Resp {$i}", 'intent_detectado' => 'saludo', 'fuente' => 'respaldo']);
    }

    $r = $this->panelComo($admin, 'GET', 'compartido/asistente?vista=conversaciones')->assertOk();
    $r->assertJsonCount(5, 'data.conversaciones')
        ->assertJsonPath('meta.pagina', 1)
        ->assertJsonPath('meta.por_pagina', 10)
        ->assertJsonPath('meta.total', 5)
        ->assertJsonPath('meta.paginas', 1)
        ->assertJsonPath('data.horus_ai.habilitado', false);
});

test('calificaciones permite exportar borrador de acta antes del cierre manteniendo 409 en acta oficial', function (): void {
    $coord = $this->usuarioConRol('coordinador');
    $secretaria = $this->usuarioConRol('secretaria');
    $curso = Curso::create([
        'codigo' => 'CAL2F-1', 'nombre' => 'Curso Calificaciones', 'descripcion' => 'x', 'categoria' => 'tecnologia',
        'duracion_horas' => 10, 'precio' => '100.00', 'modalidad' => 'virtual', 'horario' => 'Lun',
        'cupos_maximos' => 10, 'cupos_disponibles' => 10, 'estado' => 'activo',
    ]);
    $conv = new Convocatoria([
        'nombre' => null, 'modalidad' => 'virtual', 'fecha_inicio' => now()->subDay()->toDateString(),
        'fecha_fin' => now()->addDays(10)->toDateString(), 'cupos_maximos' => 10,
    ]);
    $conv->identificador = (string) Str::uuid();
    $conv->codigo = 'CNV-2026-9901';
    $conv->curso_id = $curso->id;
    $conv->estado = 'en_curso';
    $conv->cupos_disponibles = 10;
    $conv->revision = 1;
    $conv->save();

    $ev = Evaluacion::forceCreate(['identificador' => (string) Str::uuid(), 'convocatoria_id' => $conv->id, 'nombre' => 'Final', 'peso' => '100.00', 'orden' => 1]);
    $est = Estudiante::create(['dni' => '75333444', 'nombres' => 'Mario', 'apellidos' => 'Luna', 'estado' => 'activo']);
    $mat = Matricula::create(['estudiante_id' => $est->id, 'curso_id' => $curso->id, 'convocatoria_id' => $conv->id, 'fecha_matricula' => now()->toDateString(), 'estado' => 'pendiente', 'monto_pagado' => 0]);
    Calificacion::forceCreate(['matricula_id' => $mat->id, 'evaluacion_id' => $ev->id, 'nota' => 17, 'registrado_por' => $coord->id]);

    // Secretaria sin calificaciones.exportar -> 403
    $this->panelComo($secretaria, 'POST', "academico/convocatorias/{$conv->identificador}/acta/borrador/exportar")->assertForbidden();

    // Acta oficial sigue bloqueada (409) antes del cierre
    $this->panelComo($coord, 'POST', "academico/convocatorias/{$conv->identificador}/acta/exportar")->assertStatus(409);

    // Borrador sí exporta antes del cierre y genera XLSX válido + auditoría propia
    $xlsx = $this->panelComo($coord, 'POST', "academico/convocatorias/{$conv->identificador}/acta/borrador/exportar")->assertOk()->assertDownload();
    $ruta = $xlsx->baseResponse->getFile()->getPathname();
    try {
        $hoja = IOFactory::load($ruta)->getActiveSheet();
        expect($hoja->getHighestDataRow())->toBeGreaterThan(1);
    } finally {
        @unlink($ruta);
    }
    expect(AuditLog::where('action', 'acta_borrador_exportada')->count())->toBe(1);
});
