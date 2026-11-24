<?php

declare(strict_types=1);

use App\Models\AprobacionInterna;
use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\TransaccionPago;
use App\Services\GestionAprobaciones;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * Plan de mejora del panel, Fase 3.10: un reverso de pago desde el umbral (S/ 100 por defecto) exige la aprobación de Gerencia
 * (otro admin), que sirve para un solo reverso. Por debajo del umbral, como antes.
 */

/** @return array{obligacion: string, grande: string, chico: string} identificadores: un pago de 150.00 y otro de 50.00 */
function aprRev(): array
{
    $curso = Curso::create(['codigo' => 'APR-1', 'nombre' => 'Curso aprobación', 'descripcion' => 'x', 'categoria' => 'tecnologia', 'duracion_horas' => 20,
        'precio' => 300, 'modalidad' => 'virtual', 'horario' => 'Lun', 'cupos_maximos' => 10, 'cupos_disponibles' => 10, 'estado' => 'activo']);
    $e = Estudiante::create(['dni' => (string) random_int(70000000, 79999999), 'nombres' => 'Apr', 'apellidos' => 'Rev', 'estado' => 'activo']);
    $m = Matricula::create(['estudiante_id' => $e->id, 'curso_id' => $curso->id, 'fecha_matricula' => now()->toDateString(), 'estado' => 'pendiente', 'monto_pagado' => 0]);
    $o = DB::table('obligaciones_pago')->insertGetId(['identificador' => $obligacion = (string) Str::uuid(), 'matricula_id' => $m->id, 'concepto' => 'Matrícula',
        'plan' => 'completo', 'monto_total' => '500.00', 'moneda' => 'PEN', 'estado' => 'vigente', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('metodos_pago')->insertOrIgnore(['codigo' => 'yape', 'nombre' => 'Yape', 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
    $metodo = (int) DB::table('metodos_pago')->where('codigo', 'yape')->value('id');
    $ids = [];
    foreach (['grande' => '150.00', 'chico' => '50.00'] as $clave => $monto) {
        DB::table('transacciones_pago')->insert(['identificador' => $ids[$clave] = (string) Str::uuid(), 'obligacion_pago_id' => $o, 'metodo_pago_id' => $metodo,
            'tipo' => 'pago', 'estado' => 'confirmada', 'monto' => $monto, 'fecha_pago' => now('America/Lima')->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
    }

    return ['obligacion' => $obligacion, ...$ids];
}

const APR_MOTIVO = 'Yape devuelto al cliente por error';

it('por debajo del umbral el reverso sigue como antes', function (): void {
    $t = aprRev();
    $admin = $this->usuarioConRol('admin');

    $this->panelComo($admin, 'POST', "academico/pagos/transacciones/{$t['chico']}/reversar", ['motivo' => APR_MOTIVO])->assertOk();
    expect(TransaccionPago::query()->where('tipo', 'reverso')->count())->toBe(1);
});

it('desde el umbral exige aprobación: sin ella responde 409 y no revierte nada', function (): void {
    $t = aprRev();

    $r = $this->panelComo($this->usuarioConRol('admin'), 'POST', "academico/pagos/transacciones/{$t['grande']}/reversar", ['motivo' => APR_MOTIVO])->assertStatus(409);
    expect($r->json('message'))->toContain('aprobación de Gerencia')->toContain('100.00');
    expect(TransaccionPago::query()->where('tipo', 'reverso')->count())->toBe(0);
});

it('flujo completo: solicitar, aprobar otro admin, reversar una sola vez', function (): void {
    $t = aprRev();
    $uno = $this->usuarioConRol('admin', 'uno@example.test');
    $dos = $this->usuarioConRol('admin', 'dos@example.test');
    $url = "academico/pagos/transacciones/{$t['grande']}/reversar";

    $this->panelComo($uno, 'POST', "{$url}/solicitar", ['motivo' => 'x'])->assertStatus(422);
    $detalle = $this->panelComo($uno, 'POST', "{$url}/solicitar", ['motivo' => APR_MOTIVO])->assertCreated()->json('data.transacciones');
    expect(collect($detalle)->firstWhere('identificador', $t['grande'])['reverso_aprobacion'])->toBe('pendiente')
        ->and(collect($detalle)->firstWhere('identificador', $t['chico'])['reverso_aprobacion'])->toBeNull();
    $this->panelComo($uno, 'POST', "{$url}/solicitar", ['motivo' => APR_MOTIVO])->assertStatus(409);

    $solicitud = AprobacionInterna::query()->firstOrFail();
    expect($solicitud->tipo)->toBe('reverso_pago')->and($solicitud->titulo)->toBe('Reverso de pago de S/ 150.00');

    // El solicitante no resuelve su propia solicitud; sin aprobación el reverso sigue bloqueado.
    expect(fn () => app(GestionAprobaciones::class)->aprobar($uno, (int) $solicitud->id, null))->toThrow(HttpException::class);
    $this->panelComo($uno, 'POST', $url, ['motivo' => APR_MOTIVO])->assertStatus(409);

    app(GestionAprobaciones::class)->aprobar($dos, (int) $solicitud->id, 'Conforme');
    $this->panelComo($uno, 'POST', $url, ['motivo' => APR_MOTIVO])->assertOk();
    expect(TransaccionPago::query()->where('tipo', 'reverso')->count())->toBe(1)
        ->and(AprobacionInterna::query()->firstOrFail()->referencia)->toEndWith(':usada')
        ->and(AuditLog::query()->where('action', 'reverso_aprobacion_solicitada')->count())->toBe(1);

    // Repetir la petición no duplica el reverso (idempotente) ni pide otra aprobación.
    $this->panelComo($uno, 'POST', $url, ['motivo' => APR_MOTIVO])->assertOk();
    expect(TransaccionPago::query()->where('tipo', 'reverso')->count())->toBe(1);
});

it('una solicitud rechazada no habilita el reverso', function (): void {
    $t = aprRev();
    $uno = $this->usuarioConRol('admin', 'uno@example.test');
    $dos = $this->usuarioConRol('admin', 'dos@example.test');
    $url = "academico/pagos/transacciones/{$t['grande']}/reversar";

    $this->panelComo($uno, 'POST', "{$url}/solicitar", ['motivo' => APR_MOTIVO])->assertCreated();
    app(GestionAprobaciones::class)->rechazar($dos, (int) AprobacionInterna::query()->firstOrFail()->id, 'No procede');

    $this->panelComo($uno, 'POST', $url, ['motivo' => APR_MOTIVO])->assertStatus(409);
    expect(TransaccionPago::query()->where('tipo', 'reverso')->count())->toBe(0);
});

it('no se solicita aprobación para un pago bajo el umbral ni con quien no puede reversar', function (): void {
    $t = aprRev();

    $this->panelComo($this->usuarioConRol('admin'), 'POST', "academico/pagos/transacciones/{$t['chico']}/reversar/solicitar", ['motivo' => APR_MOTIVO])->assertStatus(409);
    $this->panelComo($this->usuarioConRol('coordinador'), 'POST', "academico/pagos/transacciones/{$t['grande']}/reversar/solicitar", ['motivo' => APR_MOTIVO])->assertForbidden();
    expect(AprobacionInterna::query()->count())->toBe(0);
});

it('con el umbral en 0 la aprobación se apaga', function (): void {
    config(['panel.aprobacion_reverso_umbral' => 0]);
    $t = aprRev();

    $this->panelComo($this->usuarioConRol('admin'), 'POST', "academico/pagos/transacciones/{$t['grande']}/reversar", ['motivo' => APR_MOTIVO])->assertOk();
});

it('el umbral es configurable y el límite es inclusivo', function (): void {
    config(['panel.aprobacion_reverso_umbral' => 50]);
    $t = aprRev();

    $this->panelComo($this->usuarioConRol('admin'), 'POST', "academico/pagos/transacciones/{$t['chico']}/reversar", ['motivo' => APR_MOTIVO])->assertStatus(409);
});
