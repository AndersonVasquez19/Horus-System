<?php

declare(strict_types=1);

use App\Models\Asistencia;
use App\Models\Convocatoria;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\SesionClase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Horus Academic, N4: tablero (ocho cifras) y búsqueda global. Cada cifra y cada grupo de resultados se envía SOLO si la persona tiene
 * el permiso de su módulo; el dinero sale del libro de pagos. SQLite en memoria.
 */

function tbCurso(string $codigo, string $nombre): Curso
{
    return Curso::create(['codigo' => $codigo, 'nombre' => $nombre, 'descripcion' => 'x', 'categoria' => 'tecnologia', 'duracion_horas' => 20,
        'precio' => 300, 'modalidad' => 'virtual', 'horario' => 'Lun', 'cupos_maximos' => 50, 'cupos_disponibles' => 50, 'estado' => 'activo']);
}

function tbEdicion(Curso $curso, string $codigo, string $estado, string $inicio, string $fin, int $max = 20, int $libres = 20): Convocatoria
{
    $c = new Convocatoria(['nombre' => null, 'modalidad' => 'virtual', 'fecha_inicio' => $inicio, 'fecha_fin' => $fin, 'cupos_maximos' => $max]);
    $c->identificador = (string) Str::uuid();
    $c->codigo = $codigo;
    $c->curso_id = $curso->id;
    $c->estado = $estado;
    $c->cupos_disponibles = $libres;
    $c->revision = 1;
    $c->save();

    return $c;
}

function tbMatricula(Convocatoria $c, string $dni, string $nombres, string $apellidos, array $extra = []): Matricula
{
    $e = Estudiante::create(['dni' => $dni, 'nombres' => $nombres, 'apellidos' => $apellidos, 'estado' => 'activo']);

    return Matricula::create($extra + ['estudiante_id' => $e->id, 'curso_id' => $c->curso_id, 'convocatoria_id' => $c->id, 'fecha_matricula' => now()->toDateString(), 'estado' => 'pendiente', 'monto_pagado' => 0]);
}

function tbTransaccion(int $obligacion, string $tipo, string $estado, string $monto, ?int $reversaDe = null): int
{
    DB::table('metodos_pago')->insertOrIgnore(['codigo' => 'yape', 'nombre' => 'Yape', 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);

    return DB::table('transacciones_pago')->insertGetId(['identificador' => (string) Str::uuid(), 'obligacion_pago_id' => $obligacion,
        'metodo_pago_id' => (int) DB::table('metodos_pago')->where('codigo', 'yape')->value('id'), 'tipo' => $tipo, 'estado' => $estado, 'monto' => $monto,
        'fecha_pago' => now('America/Lima')->toDateString(), 'reversa_de_id' => $reversaDe, 'created_at' => now(), 'updated_at' => now()]);
}

/** Escenario: dos ediciones, una matrícula con obligación de S/ 300 (100 cobrados, 50 por confirmar, un pago de 60 ya reversado). */
function tbEscenario(): array
{
    $curso = tbCurso('TBL-1', 'Curso de Excel');
    $porCerrar = tbEdicion($curso, 'CNV-2026-8001', 'en_curso', now()->subDays(30)->toDateString(), now()->subDays(2)->toDateString(), 20, 18);
    $porIniciar = tbEdicion($curso, 'CNV-2026-8002', 'abierta', now()->subDay()->toDateString(), now()->addDays(30)->toDateString(), 10, 10);
    $m = tbMatricula($porCerrar, '81000001', 'Ana', 'Quispe Soto', ['estado' => 'pendiente']);
    $m->forceFill(['nota_final' => 15, 'resultado' => 'aprobado'])->save();

    $obligacion = DB::table('obligaciones_pago')->insertGetId(['identificador' => (string) Str::uuid(), 'matricula_id' => $m->id, 'concepto' => 'Pago de prueba', 'plan' => 'completo',
        'monto_total' => '300.00', 'moneda' => 'PEN', 'estado' => 'vigente', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('cuotas_obligacion')->insert(['obligacion_pago_id' => $obligacion, 'numero' => 1, 'monto' => '300.00', 'vence' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
    tbTransaccion($obligacion, 'pago', 'confirmada', '100.00');
    $reversado = tbTransaccion($obligacion, 'pago', 'confirmada', '60.00');
    tbTransaccion($obligacion, 'reverso', 'confirmada', '60.00', $reversado);
    tbTransaccion($obligacion, 'pago', 'pendiente', '50.00');

    // Dos sesiones realizadas: la matrícula asistió a una (50 %, por debajo del 80 % mínimo).
    foreach ([1, 2] as $i) {
        $s = new SesionClase(['tema' => null]);
        $s->identificador = (string) Str::uuid();
        $s->convocatoria_id = $porCerrar->id;
        $s->numero = $i;
        $s->fecha = now('America/Lima')->subDays(10)->toDateString();
        $s->hora_inicio = sprintf('%02d:00:00', 6 + $i);
        $s->hora_fin = sprintf('%02d:00:00', 7 + $i);
        $s->estado = 'realizada';
        $s->revision = 1;
        $s->save();
        $a = new Asistencia;
        $a->sesion_clase_id = $s->id;
        $a->matricula_id = $m->id;
        $a->estado = $i === 1 ? 'presente' : 'ausente';
        $a->save();
    }

    // Otra matrícula aprobada; y una matrícula del mes anterior.
    $otra = tbMatricula($porCerrar, '81000002', 'Luis', 'Rojas');
    $otra->forceFill(['nota_final' => 16, 'resultado' => 'aprobado'])->save();
    tbMatricula($porIniciar, '81000003', 'Eva', 'Mora', ['fecha_matricula' => now()->subMonthNoOverflow()->toDateString()]);

    return [$porCerrar, $porIniciar, $m];
}

function tbSoloPermisos(string $email, array $permisos): User
{
    $u = User::factory()->create(['email' => $email]);
    $u->givePermissionTo(['panel.academico.acceder', ...$permisos]);

    return $u;
}

it('el administrador ve las ocho cifras, con el dinero tomado del libro de pagos', function (): void {
    [$porCerrar, $porIniciar] = tbEscenario();
    $admin = $this->usuarioConRol('admin');

    $d = $this->panelComo($admin, 'GET', 'academico/resumen')->assertOk()->json('data');

    // 1. Pagos por confirmar y 2. matrículas con saldo (300 - 100 confirmados netos = 200; lo pendiente no cuenta como cobrado).
    expect($d['acciones']['pagos_por_confirmar'])->toBe(['cantidad' => 1, 'monto' => '50.00'])
        ->and($d['acciones']['matriculas_con_saldo'])->toBe(['cantidad' => 1, 'monto' => '200.00']);
    // 3. Ediciones que requieren acción.
    expect($d['acciones']['ediciones']['por_iniciar'])->toBe(1)->and($d['acciones']['ediciones']['por_cerrar'])->toBe(1)
        ->and($d['acciones']['ediciones']['lista_por_cerrar'][0]['identificador'])->toBe($porCerrar->identificador);
    // 4. Cobrado del mes (100 + 60 - 60 reversado) y saldo por cobrar.
    expect($d['negocio']['cobrado_mes'])->toBe('100.00')->and($d['negocio']['saldo_por_cobrar'])->toBe('200.00');
    // 5. Matrículas del mes y del mes anterior.
    expect($d['indicadores']['matriculas_mes'])->toBe(2)->and($d['indicadores']['matriculas_mes_anterior'])->toBe(1);
    // 6. Cupos por edición abierta o en curso.
    $cupos = collect($d['academico']['cupos'])->keyBy('codigo');
    expect($cupos['CNV-2026-8001'])->toMatchArray(['maximo' => 20, 'ocupados' => 2, 'libres' => 18])->and($cupos['CNV-2026-8002']['libres'])->toBe(10);
    // 7. Asistencia por edición en curso, con alerta bajo el mínimo.
    expect($d['academico']['asistencia_minima'])->toBe(80)->and($d['academico']['asistencia'])->toHaveCount(1)
        ->and($d['academico']['asistencia'][0]['identificador'])->toBe($porCerrar->identificador)->and($d['academico']['asistencia'][0]['bajo_minimo'])->toBeTrue()
        ->and($d['academico']['asistencia'][0]['sesiones_realizadas'])->toBe(2);
    // Ana asistió a 1 de 2 sesiones (50 %) y Luis no tiene ningún registro (0 %): promedio de la edición, 25 %.
    expect((float) $d['academico']['asistencia'][0]['promedio'])->toBe(25.0);
    expect($d['academico'])->not->toHaveKey('certificados');
});

it('cada rol recibe solo las cifras de los módulos que puede ver; el dinero nunca sale sin pagos.ver', function (): void {
    tbEscenario();
    $coord = $this->usuarioConRol('coordinador', 'c@example.invalid');
    $sec = $this->usuarioConRol('secretaria', 's@example.invalid');

    $c = $this->panelComo($coord, 'GET', 'academico/resumen')->assertOk();
    $datos = $c->json('data');
    // Coordinación y secretaría tienen `pagos.ver` desde el 20/11/2026 (suben comprobantes): ven las cifras de cobro del tablero.
    // El ingreso del mes del indicador principal sigue siendo solo del administrador.
    expect($datos['acciones'])->toHaveKeys(['pagos_por_confirmar', 'matriculas_con_saldo', 'ediciones'])
        ->and($datos['negocio'])->toHaveKeys(['cobrado_mes', 'saldo_por_cobrar'])
        ->and($datos['academico'])->toHaveKeys(['cupos', 'asistencia'])
        ->and($datos['indicadores'])->not->toHaveKey('ingresos_mes');

    $s = $this->panelComo($sec, 'GET', 'academico/resumen')->assertOk();
    expect($s->json('data.negocio'))->toHaveKeys(['cobrado_mes', 'saldo_por_cobrar'])->and($s->json('data.acciones'))->toHaveKey('pagos_por_confirmar')
        ->and($s->json('data.indicadores'))->not->toHaveKey('ingresos_mes');

    // Solo con permisos directos: sin matrículas, cursos ni nada, el tablero llega vacío.
    $vacio = tbSoloPermisos('v@example.invalid', []);
    $v = $this->panelComo($vacio, 'GET', 'academico/resumen')->assertOk()->json('data');
    expect($v['indicadores'])->toBe([])->and($v['acciones'])->toBe([])->and($v['negocio'])->toBe([])->and($v['academico'])->toBe([]);

    // Solo asistencias.ver: ve la asistencia y nada más del tablero nuevo.
    $soloAsistencia = tbSoloPermisos('a@example.invalid', ['asistencias.ver']);
    $a = $this->panelComo($soloAsistencia, 'GET', 'academico/resumen')->assertOk()->json('data');
    expect($a['academico'])->toHaveKeys(['asistencia', 'asistencia_minima'])->not->toHaveKey('cupos')->and($a['acciones'])->toBe([]);
});

it('la cobranza no cuenta lo pendiente y un reverso la deshace', function (): void {
    [, , $m] = tbEscenario();
    $admin = $this->usuarioConRol('admin');
    $obligacion = (int) DB::table('obligaciones_pago')->where('matricula_id', $m->id)->value('id');
    $pago = (int) DB::table('transacciones_pago')->where('obligacion_pago_id', $obligacion)->where('monto', '100.00')->value('id');

    tbTransaccion($obligacion, 'reverso', 'confirmada', '100.00', $pago);
    $d = $this->panelComo($admin, 'GET', 'academico/resumen')->assertOk()->json('data');

    expect($d['negocio']['cobrado_mes'])->toBe('0.00')->and($d['acciones']['matriculas_con_saldo'])->toBe(['cantidad' => 1, 'monto' => '300.00']);
});

it('busca por DNI, nombre, código y número en todos los módulos y enlaza con identificadores, sin datos sensibles', function (): void {
    [$porCerrar, , $m] = tbEscenario();
    $admin = $this->usuarioConRol('admin');
    $r = $this->panelComo($admin, 'GET', 'academico/busqueda?q=Quispe')->assertOk();
    $g = $r->json('data.grupos');
    expect($g['estudiantes'])->toHaveCount(1)->and($g['estudiantes'][0]['texto'])->toBe('Quispe Soto, Ana')->and($g['estudiantes'][0]['detalle'])->toBe('DNI 81000001')
        ->and($g)->not->toHaveKey('docentes')
        ->and($g['matriculas'])->toHaveCount(1)->and($g['matriculas'][0])->toHaveKeys(['id', 'texto', 'detalle'])
        ->and($g['matriculas'][0]['detalle'])->toContain('DNI 81000001');
    expect($r->getContent())->not->toContain('monto_pagado')->not->toContain('nota_final');

    expect($this->panelComo($admin, 'GET', 'academico/busqueda?q='.urlencode('#'.$m->id))->json('data.grupos.matriculas.0.id'))->toBe($m->id);
    expect($this->panelComo($admin, 'GET', 'academico/busqueda?q=TBL-1')->json('data.grupos.matriculas'))->toHaveCount(3);
    expect($this->panelComo($admin, 'GET', 'academico/busqueda?q=81000002')->json('data.grupos.estudiantes.0.texto'))->toBe('Rojas, Luis');
    expect($this->panelComo($admin, 'GET', 'academico/busqueda?q=CNV-2026-8001')->json('data.grupos.ediciones.0.identificador'))->toBe($porCerrar->identificador);
    expect($this->panelComo($admin, 'GET', 'academico/busqueda?q=Excel')->json('data.grupos.ediciones'))->toHaveCount(2);
    // Los certificados también se buscan (permiso `certificados.ver`); el escenario no emite ninguno.
    $porCodigo = $this->panelComo($admin, 'GET', 'academico/busqueda?q=CERT-2026-0001')->json('data.grupos');
    expect($porCodigo)->toHaveKey('certificados');
    expect($porCodigo['certificados'])->toBeEmpty();
});

it('la búsqueda respeta permisos, el mínimo de caracteres y trata % como texto', function (): void {
    tbEscenario();
    $this->panelComo(null, 'GET', 'academico/busqueda?q=Quispe')->assertUnauthorized();
    $admin = $this->usuarioConRol('admin', 'a@example.invalid');
    $soloEstudiantes = tbSoloPermisos('e@example.invalid', ['estudiantes.ver']);
    $sinNada = tbSoloPermisos('n@example.invalid', []);

    $g = $this->panelComo($soloEstudiantes, 'GET', 'academico/busqueda?q=Quispe')->assertOk()->json('data.grupos');
    expect(array_keys($g))->toBe(['estudiantes']);
    expect($this->panelComo($sinNada, 'GET', 'academico/busqueda?q=Quispe')->assertOk()->json('data.grupos'))->toBe([]);

    expect($this->panelComo($admin, 'GET', 'academico/busqueda?q=Q')->assertOk()->json('data.grupos'))->toBe([]);
    expect($this->panelComo($admin, 'GET', 'academico/busqueda')->assertOk()->json('data.grupos'))->toBe([]);
    $this->panelComo($admin, 'GET', 'academico/busqueda?q='.str_repeat('a', 101))->assertStatus(422);
    // «%%» no es un comodín: no devuelve todo el listado.
    expect($this->panelComo($admin, 'GET', 'academico/busqueda?q=%25%25')->assertOk()->json('data.grupos.estudiantes'))->toBe([]);
});

it('el seguimiento muestra aprobaciones, notificaciones y auditoría reciente solo con su permiso', function (): void {
    tbEscenario();
    $admin = $this->usuarioConRol('admin', 'a@example.invalid');
    $sec = $this->usuarioConRol('secretaria', 's@example.invalid');

    \App\Models\AuditLog::record('accion_de_prueba', null, null, ['dato' => 'x']);

    $a = $this->panelComo($admin, 'GET', 'academico/resumen')->assertOk()->json('data.seguimiento');
    expect($a)->toHaveKeys(['aprobaciones_pendientes', 'notificaciones_sin_leer', 'auditoria_reciente'])
        ->and($a['aprobaciones_pendientes'])->toBeInt()
        ->and($a['auditoria_reciente'])->not->toBeEmpty()
        ->and($a['auditoria_reciente'][0])->toHaveKeys(['id', 'fecha_local', 'usuario', 'accion'])->not->toHaveKey('changes');

    $s = $this->panelComo($sec, 'GET', 'academico/resumen')->assertOk()->json('data.seguimiento');
    expect($s)->toHaveKey('aprobaciones_pendientes')->not->toHaveKey('auditoria_reciente');
});

it('calcula cuotas vencidas y por vencer en el tablero solo con pagos.ver', function (): void {
    [$porCerrar] = tbEscenario();
    $m2 = tbMatricula($porCerrar, '81000009', 'Carlos', 'Vega');
    $obligacionId = DB::table('obligaciones_pago')->insertGetId([
        'identificador' => (string) Str::uuid(),
        'matricula_id' => $m2->id,
        'concepto' => 'Fraccionado',
        'plan' => 'fraccionado',
        'monto_total' => '250.00',
        'moneda' => 'PEN',
        'estado' => 'vigente',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $hoy = \App\Support\HoraLocal::ahora();
    DB::table('cuotas_obligacion')->insert([
        ['obligacion_pago_id' => $obligacionId, 'numero' => 1, 'monto' => '100.00', 'vence' => $hoy->subDays(3)->toDateString(), 'created_at' => now(), 'updated_at' => now()],
        ['obligacion_pago_id' => $obligacionId, 'numero' => 2, 'monto' => '150.00', 'vence' => $hoy->addDays(4)->toDateString(), 'created_at' => now(), 'updated_at' => now()],
    ]);

    $admin = $this->usuarioConRol('admin');
    $d = $this->panelComo($admin, 'GET', 'academico/resumen')->assertOk()->json('data.acciones.cuotas');
    // tbEscenario aporta 1 cuota de 200.00 pendiente con fecha de hoy; m2 aporta 1 vencida (100.00) y 1 por vencer (150.00).
    expect($d['vencidas'])->toBe(1)
        ->and($d['monto_vencido'])->toBe('100.00')
        ->and($d['por_vencer'])->toBeGreaterThanOrEqual(1);
});
