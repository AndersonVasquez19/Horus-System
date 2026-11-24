<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Certificado;
use App\Models\Convocatoria;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Plan de mejora del panel, Fase 3.5: una sola regla de elegibilidad de certificado (asistencia, nota, deuda y acta opcional),
 * compartida por la emisión individual, el lote y la vista previa. La deuda bloquea y advierte; el admin puede emitir con motivo.
 */

function elgCurso(): Curso
{
    return Curso::query()->firstOrCreate(['codigo' => 'ELG-1'], ['nombre' => 'Curso elegibilidad', 'descripcion' => 'x', 'categoria' => 'tecnologia', 'duracion_horas' => 20,
        'precio' => 300, 'modalidad' => 'virtual', 'horario' => 'Lun', 'cupos_maximos' => 10, 'cupos_disponibles' => 10, 'estado' => 'activo']);
}

function elgEdicion(string $estado = 'en_curso'): Convocatoria
{
    $c = new Convocatoria(['nombre' => null, 'modalidad' => 'virtual', 'fecha_inicio' => now()->subDays(10)->toDateString(),
        'fecha_fin' => now()->addDays(20)->toDateString(), 'cupos_maximos' => 10]);
    $c->identificador = (string) Str::uuid();
    $c->codigo = 'CNV-2026-'.random_int(1000, 9999);
    $c->curso_id = elgCurso()->id;
    $c->estado = $estado;
    $c->cupos_disponibles = 10;
    $c->revision = 1;
    $c->save();

    return $c;
}

function elgMatricula(Convocatoria $c, string $dni): Matricula
{
    $e = Estudiante::create(['dni' => $dni, 'nombres' => 'Nombre'.$dni, 'apellidos' => 'Apellido'.$dni, 'estado' => 'activo']);

    return Matricula::create(['estudiante_id' => $e->id, 'curso_id' => $c->curso_id, 'convocatoria_id' => $c->id,
        'fecha_matricula' => now()->toDateString(), 'estado' => 'pendiente', 'monto_pagado' => 0]);
}

/** Obligación vigente de 300.00 con lo confirmado y lo pendiente indicados. */
function elgDeuda(Matricula $m, string $confirmado, string $pendiente = '0.00'): void
{
    $o = DB::table('obligaciones_pago')->insertGetId(['identificador' => (string) Str::uuid(), 'matricula_id' => $m->id, 'concepto' => 'Matrícula',
        'plan' => 'completo', 'monto_total' => '300.00', 'moneda' => 'PEN', 'estado' => 'vigente', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('metodos_pago')->insertOrIgnore(['codigo' => 'yape', 'nombre' => 'Yape', 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
    $metodo = (int) DB::table('metodos_pago')->where('codigo', 'yape')->value('id');
    foreach ([['confirmada', $confirmado], ['pendiente', $pendiente]] as [$estado, $monto]) {
        if ($monto === '0.00') {
            continue;
        }
        DB::table('transacciones_pago')->insert(['identificador' => (string) Str::uuid(), 'obligacion_pago_id' => $o, 'metodo_pago_id' => $metodo,
            'tipo' => 'pago', 'estado' => $estado, 'monto' => $monto, 'fecha_pago' => now('America/Lima')->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
    }
}

function elgPrevia(Closure $llamar, Matricula $m): array
{
    return $llamar('GET', "academico/matriculas/{$m->id}/certificado/elegibilidad")->assertOk()->json('data');
}

it('sin obligación ni requisitos pendientes la matrícula es elegible y se emite', function (): void {
    $m = elgMatricula(elgEdicion(), '74000001');

    expect(elgPrevia(fn (...$a) => $this->panelComo($this->usuarioConRol('admin'), ...$a), $m))->toMatchArray(['elegible' => true, 'bloqueos' => []]);
    $this->panelComo($this->usuarioConRol('coordinador'), 'POST', "academico/matriculas/{$m->id}/certificado")->assertCreated();
});

it('la deuda bloquea y advierte con el importe; un pago pendiente de confirmar no cuenta como pagado', function (): void {
    $m = elgMatricula(elgEdicion(), '74000002');
    elgDeuda($m, '100.00', '200.00');

    $p = elgPrevia(fn (...$a) => $this->panelComo($this->usuarioConRol('coordinador'), ...$a), $m);
    expect($p['elegible'])->toBeFalse()
        ->and(array_column($p['bloqueos'], 'codigo'))->toBe(['deuda'])
        ->and($p['bloqueos'][0]['mensaje'])->toContain('S/ 200.00')
        ->and($p['puede_excepcion'])->toBeFalse();

    $this->panelComo($this->usuarioConRol('coordinador'), 'POST', "academico/matriculas/{$m->id}/certificado")->assertStatus(422);
    expect(Certificado::query()->count())->toBe(0);
});

it('el admin solo puede emitir con deuda indicando un motivo, y queda en el certificado y la auditoría', function (): void {
    $m = elgMatricula(elgEdicion(), '74000003');
    elgDeuda($m, '100.00');
    $admin = $this->usuarioConRol('admin');

    expect(elgPrevia(fn (...$a) => $this->panelComo($this->usuarioConRol('admin'), ...$a), $m)['puede_excepcion'])->toBeTrue();
    $this->panelComo($admin, 'POST', "academico/matriculas/{$m->id}/certificado")->assertStatus(422);
    $this->panelComo($admin, 'POST', "academico/matriculas/{$m->id}/certificado", ['motivo_excepcion' => 'Convenio: paga al recoger'])->assertCreated();

    $c = Certificado::query()->firstOrFail();
    expect($c->motivo_excepcion)->toBe('Convenio: paga al recoger');
    expect(AuditLog::query()->where('action', 'certificado_emitido')->firstOrFail()->changes['bloqueos'])->toBe(['deuda']);
});

it('una obligación saldada deja de bloquear', function (): void {
    $m = elgMatricula(elgEdicion(), '74000004');
    elgDeuda($m, '300.00');

    expect(elgPrevia(fn (...$a) => $this->panelComo($this->usuarioConRol('admin'), ...$a), $m)['elegible'])->toBeTrue();
});

it('la nota mínima sale de la configuración', function (): void {
    $m = elgMatricula(elgEdicion(), '74000005');
    DB::table('matriculas')->where('id', $m->id)->update(['nota_final' => '12.00']);

    expect(elgPrevia(fn (...$a) => $this->panelComo($this->usuarioConRol('admin'), ...$a), $m)['elegible'])->toBeTrue();
    config(['academico.nota_minima' => 14.0]);
    $p = elgPrevia(fn (...$a) => $this->panelComo($this->usuarioConRol('admin'), ...$a), $m);
    expect(array_column($p['bloqueos'], 'codigo'))->toBe(['nota']);
});

it('exigir el acta cerrada es opcional y apagado por defecto', function (): void {
    $abierta = elgMatricula(elgEdicion('en_curso'), '74000006');
    $cerrada = elgMatricula(elgEdicion('cerrada'), '74000007');

    expect(elgPrevia(fn (...$a) => $this->panelComo($this->usuarioConRol('admin'), ...$a), $abierta)['elegible'])->toBeTrue();
    config(['academico.certificado_exige_acta_cerrada' => true]);
    expect(array_column(elgPrevia(fn (...$a) => $this->panelComo($this->usuarioConRol('admin'), ...$a), $abierta)['bloqueos'], 'codigo'))->toBe(['acta'])
        ->and(elgPrevia(fn (...$a) => $this->panelComo($this->usuarioConRol('admin'), ...$a), $cerrada)['elegible'])->toBeTrue();
});

it('el lote omite a quien tiene deuda y lo cuenta aparte', function (): void {
    $edicion = elgEdicion();
    $conDeuda = elgMatricula($edicion, '74000008');
    elgDeuda($conDeuda, '0.00', '50.00');
    elgDeuda(elgMatricula($edicion, '74000009'), '300.00');
    elgMatricula($edicion, '74000010');

    $r = $this->panelComo($this->usuarioConRol('coordinador'), 'POST', "academico/convocatorias/{$edicion->identificador}/certificados/emitir-lote")
        ->assertOk()->json('data');

    expect($r)->toMatchArray(['emitidos' => 2, 'omitidos' => 1, 'omitidos_por_deuda' => 1, 'ya_emitidos' => 0]);
    expect(Certificado::query()->where('matricula_id', $conDeuda->id)->exists())->toBeFalse();
});

it('quien solo puede ver certificados consulta la vista previa', function (): void {
    $m = elgMatricula(elgEdicion(), '74000011');

    expect(elgPrevia(fn (...$a) => $this->panelComo($this->usuarioConRol('secretaria'), ...$a), $m)['elegible'])->toBeTrue();
});
