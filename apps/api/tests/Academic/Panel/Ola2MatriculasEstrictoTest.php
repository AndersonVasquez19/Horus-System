<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\MetodoPago;
use App\Models\ObligacionPago;
use App\Models\TransaccionPago;
use Illuminate\Support\Str;

test('rechaza monto_pagado o estado pagada manuales al crear y editar matrícula', function (): void {
    $admin = $this->usuarioConRol('admin');
    $curso = Curso::create([
        'codigo' => 'EST-1', 'nombre' => 'Curso Estricto', 'descripcion' => 'Desc', 'categoria' => 'tecnologia',
        'duracion_horas' => 15, 'precio' => '180.00', 'modalidad' => 'virtual', 'horario' => 'Lun 18:00',
        'cupos_maximos' => 5, 'cupos_disponibles' => 5, 'estado' => 'activo',
    ]);
    $estudiante = Estudiante::create([
        'dni' => '72000001', 'nombres' => 'Carla', 'apellidos' => 'Vega', 'estado' => 'activo',
    ]);

    $this->panelComo($admin, 'POST', 'academico/matriculas', [
        'estudiante_id' => $estudiante->id, 'curso_id' => $curso->id,
        'fecha_matricula' => '2026-11-19', 'estado' => 'pagada', 'monto_pagado' => '180.00',
    ])->assertUnprocessable()->assertJsonValidationErrors(['estado', 'monto_pagado']);

    $id = (int) $this->panelComo($admin, 'POST', 'academico/matriculas', [
        'estudiante_id' => $estudiante->id, 'curso_id' => $curso->id,
        'fecha_matricula' => '2026-11-19', 'estado' => 'pendiente',
    ])->assertCreated()->json('data.id');

    $this->panelComo($admin, 'PUT', "academico/matriculas/{$id}", [
        'estudiante_id' => $estudiante->id, 'curso_id' => $curso->id,
        'fecha_matricula' => '2026-11-19', 'estado' => 'pagada',
    ])->assertUnprocessable()->assertJsonValidationErrors(['estado']);

    $this->panelComo($admin, 'PUT', "academico/matriculas/{$id}", [
        'estudiante_id' => $estudiante->id, 'curso_id' => $curso->id,
        'fecha_matricula' => '2026-11-19', 'estado' => 'pendiente', 'monto_pagado' => '50.00',
    ])->assertUnprocessable()->assertJsonValidationErrors(['monto_pagado']);
});

test('exige motivo al anular o eliminar matrícula y nunca lo expone en auditoría', function (): void {
    $admin = $this->usuarioConRol('admin');
    $curso = Curso::create([
        'codigo' => 'MOT-1', 'nombre' => 'Curso Motivo', 'descripcion' => 'Desc', 'categoria' => 'tecnologia',
        'duracion_horas' => 15, 'precio' => '180.00', 'modalidad' => 'virtual', 'horario' => 'Lun 18:00',
        'cupos_maximos' => 5, 'cupos_disponibles' => 4, 'estado' => 'activo',
    ]);
    $estudiante = Estudiante::create([
        'dni' => '72000002', 'nombres' => 'Luis', 'apellidos' => 'Paz', 'estado' => 'activo',
    ]);
    $matricula = Matricula::create([
        'estudiante_id' => $estudiante->id, 'curso_id' => $curso->id,
        'fecha_matricula' => '2026-11-19', 'estado' => 'pendiente', 'monto_pagado' => '0.00',
    ]);

    $this->panelComo($admin, 'POST', "academico/matriculas/{$matricula->id}/anular", [])
        ->assertUnprocessable()->assertJsonValidationErrors(['motivo']);

    $motivo = 'Retiro voluntario del estudiante por cruce horario';
    $this->panelComo($admin, 'POST', "academico/matriculas/{$matricula->id}/anular", ['motivo' => $motivo])
        ->assertOk();

    expect($matricula->fresh()->motivo_anulacion)->toBe($motivo);
    $log = AuditLog::query()->where('action', 'matricula_anulada')->latest('id')->first();
    expect(json_encode($log?->changes))->not->toContain($motivo);

    $this->panelComo($admin, 'DELETE', "academico/matriculas/{$matricula->id}", ['motivo' => 'AB'])
        ->assertUnprocessable()->assertJsonValidationErrors(['motivo']);

    $this->panelComo($admin, 'DELETE', "academico/matriculas/{$matricula->id}", ['motivo' => 'Registro duplicado'])
        ->assertOk();
    expect(Matricula::withTrashed()->findOrFail($matricula->id)->motivo_anulacion)->toBe('Registro duplicado');
});

test('constancia PDF exige matriculas.ver, entrega PDF válido y audita sin texto libre', function (): void {
    $admin = $this->usuarioConRol('admin');
    $curso = Curso::create([
        'codigo' => 'CON-1', 'nombre' => 'Constancia', 'descripcion' => 'Desc', 'categoria' => 'tecnologia',
        'duracion_horas' => 12, 'precio' => '120.00', 'modalidad' => 'virtual', 'horario' => 'Sáb 09:00',
        'cupos_maximos' => 5, 'cupos_disponibles' => 4, 'estado' => 'activo',
        'enlace_grupo_whatsapp' => 'https://chat.whatsapp.com/SecretLinkTest12345',
    ]);
    $estudiante = Estudiante::create([
        'dni' => '72000003', 'nombres' => 'Rosa', 'apellidos' => 'Luna', 'estado' => 'activo',
    ]);
    $matricula = Matricula::create([
        'estudiante_id' => $estudiante->id, 'curso_id' => $curso->id,
        'fecha_matricula' => '2026-11-19', 'estado' => 'pagada', 'monto_pagado' => '120.00',
    ]);
    $metodo = MetodoPago::firstOrCreate(['codigo' => 'yape'], ['nombre' => 'Yape', 'activo' => true]);
    $ob = ObligacionPago::create([
        'identificador' => (string) Str::uuid(), 'matricula_id' => $matricula->id, 'concepto' => 'Matrícula',
        'plan' => 'completo', 'monto_total' => '120.00', 'estado' => 'vigente',
    ]);
    TransaccionPago::create([
        'identificador' => (string) Str::uuid(), 'obligacion_pago_id' => $ob->id, 'tipo' => 'pago',
        'metodo_pago_id' => $metodo->id, 'monto' => '120.00', 'fecha_pago' => '2026-11-19', 'estado' => 'confirmada',
    ]);

    $this->panelComo($admin, 'GET', "academico/matriculas/{$matricula->id}/constancia")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $contenido = $this->actingAs($admin)
        ->withHeaders(['X-Horus-Web-Token' => self::TOKEN_BFF])
        ->get("/api/panel/v1/academico/matriculas/{$matricula->id}/constancia")
        ->getContent();
    expect($contenido)->toStartWith('%PDF-')
        ->and($contenido)->not->toContain('SecretLinkTest12345');

    expect(AuditLog::query()->where('action', 'matricula_constancia_pdf')->count())->toBe(2);
});
