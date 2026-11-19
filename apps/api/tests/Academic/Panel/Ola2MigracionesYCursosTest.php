<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\CursoPublicacion;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\MetodoPago;
use App\Models\ObligacionPago;
use App\Models\TransaccionPago;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

test('las migraciones de ola 2 añaden columnas de negocio e índices esperados', function (): void {
    expect(Schema::hasColumn('cursos', 'enlace_grupo_whatsapp'))->toBeTrue()
        ->and(Schema::hasColumn('matriculas', 'motivo_anulacion'))->toBeTrue()
        ->and(Schema::hasIndex('audit_logs', 'audit_logs_model_type_model_id_index'))->toBeTrue()
        ->and(Schema::hasIndex('audit_logs', 'audit_logs_action_index'))->toBeTrue()
        ->and(Schema::hasIndex('obligaciones_pago', 'obligaciones_pago_estado_index'))->toBeTrue()
        ->and(Schema::hasIndex('notificaciones_internas', 'notificaciones_internas_created_at_index'))->toBeTrue();
});

test('horus:reconciliar-pagos reporta discrepancias en modo lectura y solo corrige con --aplicar', function (): void {
    $curso = Curso::create([
        'codigo' => 'CUR-REC', 'nombre' => 'Reconciliación', 'descripcion' => 'Desc', 'categoria' => 'tecnologia',
        'duracion_horas' => 10, 'precio' => '200.00', 'modalidad' => 'virtual', 'horario' => 'Lun 19:00',
        'cupos_maximos' => 5, 'cupos_disponibles' => 4, 'estado' => 'activo',
    ]);
    $estudiante = Estudiante::create([
        'dni' => '71000001', 'nombres' => 'Ana', 'apellidos' => 'Perez', 'estado' => 'activo',
    ]);
    $matricula = Matricula::create([
        'estudiante_id' => $estudiante->id, 'curso_id' => $curso->id, 'fecha_matricula' => now()->toDateString(),
        'estado' => 'pendiente', 'monto_pagado' => '0.00',
    ]);
    $metodo = MetodoPago::firstOrCreate(['codigo' => 'yape'], ['nombre' => 'Yape', 'activo' => true]);
    $obligacion = ObligacionPago::create([
        'identificador' => (string) Str::uuid(), 'matricula_id' => $matricula->id, 'concepto' => 'Matrícula',
        'plan' => 'unico', 'monto_total' => '200.00', 'estado' => 'vigente',
    ]);
    TransaccionPago::create([
        'identificador' => (string) Str::uuid(), 'obligacion_pago_id' => $obligacion->id, 'tipo' => 'pago',
        'metodo_pago_id' => $metodo->id, 'monto' => '200.00', 'fecha_pago' => now()->toDateString(), 'estado' => 'confirmada',
    ]);

    $this->artisan('horus:reconciliar-pagos')
        ->expectsOutputToContain('Discrepancias con obligación vigente: 1')
        ->assertSuccessful();

    $matricula->refresh();
    expect($matricula->estado)->toBe('pendiente')
        ->and((float) $matricula->monto_pagado)->toBe(0.0);

    // Sin --confirmo se niega a escribir.
    $this->artisan('horus:reconciliar-pagos --aplicar')->assertFailed();
    expect($matricula->fresh()->estado)->toBe('pendiente');

    $this->artisan('horus:reconciliar-pagos --aplicar --confirmo')
        ->expectsOutputToContain('Alineación aplicada sobre 1 matrícula(s).')
        ->assertSuccessful();

    $matricula->refresh();
    expect($matricula->estado)->toBe('pagada')
        ->and((float) $matricula->monto_pagado)->toBe(200.0);

    // Quedó el respaldo del valor anterior y la auditoría del lote.
    $respaldo = DB::table('matriculas_monto_respaldo')->where('matricula_id', $matricula->id)->first();
    expect($respaldo)->not->toBeNull()
        ->and($respaldo->estado_anterior)->toBe('pendiente')
        ->and((float) $respaldo->monto_pagado_anterior)->toBe(0.0)
        ->and(AuditLog::query()->where('action', 'matriculas_reconciliadas')->count())->toBe(1);

    // Repetir no cambia nada (ya está alineado) y revertir restaura lo anterior una sola vez.
    $this->artisan('horus:reconciliar-pagos --aplicar --confirmo')->assertSuccessful();
    expect(DB::table('matriculas_monto_respaldo')->count())->toBe(1);

    $this->artisan('horus:reconciliar-pagos --revertir='.$respaldo->lote)->assertSuccessful();
    $matricula->refresh();
    expect($matricula->estado)->toBe('pendiente')
        ->and((float) $matricula->monto_pagado)->toBe(0.0);
    $this->artisan('horus:reconciliar-pagos --revertir='.$respaldo->lote)->expectsOutputToContain('No hay filas pendientes')->assertSuccessful();
});

test('enlace_grupo_whatsapp se valida, se expone en el panel y nunca entra en la auditoría', function (): void {
    $admin = $this->usuarioConRol('admin');
    $enlace = 'https://chat.whatsapp.com/AbCdEfGhIjKlMnOpQrStUv';

    $this->panelComo($admin, 'POST', 'academico/cursos', [
        'codigo' => 'CUR-WA-BAD', 'nombre' => 'Curso WA', 'descripcion' => 'Desc', 'categoria' => 'tecnologia',
        'duracion_horas' => 12, 'precio' => '150.00', 'modalidad' => 'virtual', 'horario' => 'Mar 19:00',
        'cupos_maximos' => 8, 'estado' => 'activo', 'enlace_grupo_whatsapp' => 'https://zoom.us/j/12345',
    ])->assertUnprocessable()->assertJsonValidationErrors(['enlace_grupo_whatsapp']);

    $creado = $this->panelComo($admin, 'POST', 'academico/cursos', [
        'codigo' => 'CUR-WA-OK', 'nombre' => 'Curso WA', 'descripcion' => 'Desc', 'categoria' => 'tecnologia',
        'duracion_horas' => 12, 'precio' => '150.00', 'modalidad' => 'virtual', 'horario' => 'Mar 19:00',
        'cupos_maximos' => 8, 'estado' => 'activo', 'enlace_grupo_whatsapp' => $enlace,
    ])->assertCreated();

    $cursoId = (int) $creado->json('data.id');
    expect($creado->json('data.enlace_grupo_whatsapp'))->toBe($enlace);

    $log = AuditLog::query()->where('model_id', $cursoId)->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and(json_encode($log?->changes))->not->toContain($enlace)
        ->and($log?->changes['campos'] ?? [])->toContain('enlace_grupo_whatsapp');
});

test('duplicar curso genera copia inactiva sin enlace de whatsapp y respeta permisos', function (): void {
    $admin = $this->usuarioConRol('admin');
    $visor = $this->usuarioConRol('secretaria');
    $curso = Curso::create([
        'codigo' => 'CUR-ORIG', 'nombre' => 'Python Base', 'descripcion' => 'Contenido', 'categoria' => 'tecnologia',
        'duracion_horas' => 24, 'precio' => '320.00', 'modalidad' => 'virtual', 'horario' => 'Sáb 10:00',
        'cupos_maximos' => 8, 'cupos_disponibles' => 3, 'estado' => 'activo',
        'enlace_grupo_whatsapp' => 'https://chat.whatsapp.com/AbCdEfGhIjKlMnOpQrStUv',
    ]);

    $this->panelComo($visor, 'POST', "academico/cursos/{$curso->id}/duplicar")->assertForbidden();

    $copia = $this->panelComo($admin, 'POST', "academico/cursos/{$curso->id}/duplicar")
        ->assertCreated()
        ->json('data');

    expect($copia['codigo'])->toBe('CUR-ORIG-COPIA')
        ->and($copia['nombre'])->toBe('Python Base (copia)')
        ->and($copia['estado'])->toBe('inactivo')
        ->and($copia['cupos_maximos'])->toBe(8)
        ->and($copia['cupos_disponibles'])->toBe(8)
        ->and($copia['enlace_grupo_whatsapp'])->toBeNull();

    // Segunda duplicación resuelve colisión de código
    $segunda = $this->panelComo($admin, 'POST', "academico/cursos/{$curso->id}/duplicar")
        ->assertCreated()
        ->json('data');

    expect($segunda['codigo'])->toStartWith('CUR-ORIG-C')
        ->and(strlen($segunda['codigo']))->toBeLessThanOrEqual(20);
});

test('listado de cursos filtra por con_cupos y publicado y ordena por precio y duración', function (): void {
    $admin = $this->usuarioConRol('admin');

    $lleno = Curso::create([
        'codigo' => 'C-LLENO', 'nombre' => 'Curso Lleno', 'descripcion' => 'Desc', 'categoria' => 'tecnologia',
        'duracion_horas' => 10, 'precio' => '100.00', 'modalidad' => 'virtual', 'horario' => 'Lun 18:00',
        'cupos_maximos' => 5, 'cupos_disponibles' => 0, 'estado' => 'activo',
    ]);
    $pub = Curso::create([
        'codigo' => 'C-PUB', 'nombre' => 'Curso Publicado', 'descripcion' => 'Desc', 'categoria' => 'tecnologia',
        'duracion_horas' => 40, 'precio' => '450.00', 'modalidad' => 'virtual', 'horario' => 'Mar 18:00',
        'cupos_maximos' => 5, 'cupos_disponibles' => 4, 'estado' => 'activo',
    ]);
    CursoPublicacion::create([
        'curso_id' => $pub->id, 'titulo' => 'Curso Publicado', 'resumen' => 'Resumen válido del catálogo.',
        'modalidad' => 'virtual', 'vigente_desde' => now('America/Lima')->toDateString(),
        'vigente_hasta' => now('America/Lima')->addMonth()->toDateString(),
        'publicada' => true, 'autorizado_por' => $admin->id, 'autorizado_at' => now(),
    ]);

    $this->panelComo($admin, 'GET', 'academico/cursos?con_cupos=1')
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $pub->id);

    $this->panelComo($admin, 'GET', 'academico/cursos?publicado=1')
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $pub->id);

    $this->panelComo($admin, 'GET', 'academico/cursos?publicado=0')
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $lleno->id);

    $this->panelComo($admin, 'GET', 'academico/cursos?sort=-precio')
        ->assertOk()->assertJsonPath('data.0.id', $pub->id);

    $this->panelComo($admin, 'GET', 'academico/cursos?sort=duracion')
        ->assertOk()->assertJsonPath('data.0.id', $lleno->id);
});

test('la migración 000087 es idempotente y reversible (up repetido y down no fallan)', function (): void {
    $migracion = require glob(database_path('migrations/2026_11_19_000087_*.php'))[0];

    expect(Schema::hasColumn('cursos', 'enlace_grupo_whatsapp'))->toBeTrue();

    $migracion->up(); // repetir no debe fallar
    expect(Schema::hasColumn('cursos', 'enlace_grupo_whatsapp'))->toBeTrue();

    $migracion->down();
    expect(Schema::hasColumn('cursos', 'enlace_grupo_whatsapp'))->toBeFalse()
        ->and(Schema::hasColumn('matriculas', 'motivo_anulacion'))->toBeFalse();

    $migracion->down(); // y tampoco falla si ya no están
    $migracion->up();
    expect(Schema::hasColumn('matriculas', 'motivo_anulacion'))->toBeTrue();
});
