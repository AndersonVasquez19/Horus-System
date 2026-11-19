<?php

declare(strict_types=1);

namespace Tests\PostgresIntegrity;

use App\Models\Convocatoria;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\SesionClase;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresIntegrity\Concerns\RequiresIsolatedPostgres;
use Tests\TestCase;

/**
 * Horus Academic (sesiones, asistencia, finanzas, pesos de evaluación): reglas que SOLO existen en PostgreSQL y que
 * SQLite en memoria no mide — CHECK, índices parciales, disparadores de inmutabilidad y de suma de pesos.
 *
 * Escriben filas directamente (sin el panel) para comprobar que la base rechaza lo que la aplicación ya impide.
 * Solo corren en el perfil aislado (ver RequiresIsolatedPostgres); datos ficticios.
 */
class AcademicoFase9PostgresTest extends TestCase
{
    use RefreshDatabase;
    use RequiresIsolatedPostgres;

    private const UNICA = '23505';

    private const CHECK = '23514';

    /** `RAISE EXCEPTION` de un disparador sin SQLSTATE propio. */
    private const DISPARADOR = 'P0001';

    private int $n = 0;

    // ---------------------------------------------------------------- finanzas

    public function test_el_libro_de_pagos_rechaza_borrados_y_ediciones_de_lo_ya_resuelto(): void
    {
        [, $obligacion] = $this->matriculaConObligacion();
        $pendiente = $this->transaccion($obligacion, 'pago', 'pendiente', '100.00');

        // Un pago pendiente solo puede pasar a confirmada o rechazada; nada más cambia.
        $this->assertEstado(self::DISPARADOR, fn () => DB::table('transacciones_pago')->where('id', $pendiente)->update(['monto' => '999.00']));
        $this->assertEstado(self::DISPARADOR, fn () => DB::table('transacciones_pago')->where('id', $pendiente)->update(['tipo' => 'reverso']));
        $this->assertEstado(self::DISPARADOR, fn () => DB::table('transacciones_pago')->where('id', $pendiente)->delete());
        DB::table('transacciones_pago')->where('id', $pendiente)->update(['estado' => 'confirmada', 'resuelto_en' => now()]);

        // Confirmado: ya no se toca (ni estado, ni motivo, ni borrado).
        $this->assertEstado(self::DISPARADOR, fn () => DB::table('transacciones_pago')->where('id', $pendiente)->update(['estado' => 'rechazada']));
        $this->assertEstado(self::DISPARADOR, fn () => DB::table('transacciones_pago')->where('id', $pendiente)->update(['motivo' => 'edición tardía']));
        $this->assertEstado(self::DISPARADOR, fn () => DB::table('transacciones_pago')->where('id', $pendiente)->delete());
        $this->assertSame('confirmada', DB::table('transacciones_pago')->where('id', $pendiente)->value('estado'));
        $this->assertSame('100.00', (string) DB::table('transacciones_pago')->where('id', $pendiente)->value('monto'));
    }

    public function test_las_asignaciones_de_pago_son_inmutables(): void
    {
        [, $obligacion] = $this->matriculaConObligacion();
        $t = $this->transaccion($obligacion, 'pago', 'pendiente', '100.00');
        $cuota = (int) DB::table('cuotas_obligacion')->where('obligacion_pago_id', $obligacion)->value('id');
        $a = DB::table('asignaciones_pago')->insertGetId(['transaccion_pago_id' => $t, 'cuota_obligacion_id' => $cuota, 'monto' => '100.00', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertEstado(self::DISPARADOR, fn () => DB::table('asignaciones_pago')->where('id', $a)->update(['monto' => '1.00']));
        $this->assertEstado(self::DISPARADOR, fn () => DB::table('asignaciones_pago')->where('id', $a)->delete());
        $this->assertSame(1, DB::table('asignaciones_pago')->count());
    }

    public function test_check_de_transacciones_y_obligaciones(): void
    {
        [, $obligacion] = $this->matriculaConObligacion();

        $this->assertEstado(self::CHECK, fn () => $this->transaccion($obligacion, 'pago', 'pendiente', '0.00'));
        $this->assertEstado(self::CHECK, fn () => $this->transaccion($obligacion, 'pago', 'pendiente', '-5.00'));
        $this->assertEstado(self::CHECK, fn () => $this->transaccion($obligacion, 'otro', 'pendiente', '5.00'));
        $this->assertEstado(self::CHECK, fn () => $this->transaccion($obligacion, 'pago', 'rara', '5.00'));
        // Un reverso debe enlazar el pago original y nacer confirmado.
        $this->assertEstado(self::CHECK, fn () => $this->transaccion($obligacion, 'reverso', 'confirmada', '5.00'));

        $this->assertEstado(self::CHECK, fn () => DB::table('obligaciones_pago')->where('id', $obligacion)->update(['moneda' => 'USD']));
        $this->assertEstado(self::CHECK, fn () => DB::table('obligaciones_pago')->where('id', $obligacion)->update(['plan' => 'quincenal']));
        $this->assertEstado(self::CHECK, fn () => DB::table('obligaciones_pago')->where('id', $obligacion)->update(['monto_total' => '0.00']));
        $this->assertEstado(self::CHECK, fn () => DB::table('cuotas_obligacion')->where('obligacion_pago_id', $obligacion)->update(['monto' => '0.00']));
    }

    public function test_un_pago_tiene_a_lo_sumo_un_reverso_y_la_clave_de_idempotencia_es_unica_por_obligacion(): void
    {
        [, $obligacion] = $this->matriculaConObligacion();
        $pago = $this->transaccion($obligacion, 'pago', 'confirmada', '100.00', ['clave_idempotencia' => 'clave-pg-0001']);
        $this->transaccion($obligacion, 'reverso', 'confirmada', '100.00', ['reversa_de_id' => $pago]);

        $this->assertEstado(self::UNICA, fn () => $this->transaccion($obligacion, 'reverso', 'confirmada', '100.00', ['reversa_de_id' => $pago]));
        $this->assertEstado(self::UNICA, fn () => $this->transaccion($obligacion, 'pago', 'pendiente', '5.00', ['clave_idempotencia' => 'clave-pg-0001']));
    }

    public function test_una_sola_obligacion_vigente_por_matricula(): void
    {
        [$matricula, $obligacion] = $this->matriculaConObligacion();

        $this->assertEstado(self::UNICA, fn () => $this->obligacion($matricula));
        DB::table('obligaciones_pago')->where('id', $obligacion)->update(['estado' => 'anulada']);
        $this->obligacion($matricula); // tras anular, se puede crear otra vigente

    }

    // ---------------------------------------------------------------- sesiones y asistencia

    public function test_check_de_sesiones(): void
    {
        $edicion = $this->edicion();

        $this->assertEstado(self::CHECK, fn () => $this->sesion($edicion, 1, ['hora_inicio' => '20:00:00', 'hora_fin' => '18:00:00']));
        $this->assertEstado(self::CHECK, fn () => $this->sesion($edicion, 2, ['hora_inicio' => '18:00:00', 'hora_fin' => '18:00:00']));
        $this->assertEstado(self::CHECK, fn () => $this->sesion($edicion, 3, ['estado' => 'rara']));
        // Cancelada sin motivo: no.
        $this->assertEstado(self::CHECK, fn () => $this->sesion($edicion, 5, ['estado' => 'cancelada']));
        $this->sesion($edicion, 6, ['estado' => 'cancelada', 'motivo_cancelacion' => 'Feriado']);
        $this->sesion($edicion, 7);
        $this->assertSame(2, DB::table('sesiones_clase')->count());
    }

    public function test_el_numero_de_sesion_es_unico_por_edicion(): void
    {
        $edicion = $this->edicion();
        $otra = $this->edicion();
        $this->sesion($edicion, 1);

        $this->assertEstado(self::UNICA, fn () => $this->sesion($edicion, 1));
        $this->sesion($otra, 1);
        $this->assertSame(2, DB::table('sesiones_clase')->count());
    }

    public function test_asistencia_unica_por_sesion_y_matricula_y_estado_valido(): void
    {
        [$matricula] = $this->matriculaConObligacion();
        $sesion = $this->sesion((int) Matricula::query()->whereKey($matricula)->value('convocatoria_id'), 1);

        $this->asistencia($sesion, $matricula, 'presente');
        $this->assertEstado(self::UNICA, fn () => $this->asistencia($sesion, $matricula, 'ausente'));
        $otraSesion = $this->sesion((int) Matricula::query()->whereKey($matricula)->value('convocatoria_id'), 2);
        $this->assertEstado(self::CHECK, fn () => $this->asistencia($otraSesion, $matricula, 'volando'));
        $this->asistencia($otraSesion, $matricula, 'justificada');
        $this->assertSame(2, DB::table('asistencias')->count());
    }

    // ---------------------------------------------------------------- evaluaciones

    public function test_la_suma_de_pesos_de_una_edicion_no_pasa_de_100_en_la_base(): void
    {
        $edicion = $this->edicion();
        $otra = $this->edicion();

        $a = $this->evaluacion($edicion, '60.00');
        $this->assertEstado(self::DISPARADOR, fn () => $this->evaluacion($edicion, '50.00'));
        $b = $this->evaluacion($edicion, '40.00'); // justo 100
        $this->assertEstado(self::DISPARADOR, fn () => $this->evaluacion($edicion, '0.01'));

        // Editar tampoco puede pasar de 100; reducir o repartir sí.
        $this->assertEstado(self::DISPARADOR, fn () => DB::table('evaluaciones')->where('id', $a)->update(['peso' => '70.00']));
        DB::table('evaluaciones')->where('id', $a)->update(['peso' => '50.00']);
        DB::table('evaluaciones')->where('id', $b)->update(['peso' => '50.00']);
        $this->assertSame('100.00', (string) DB::table('evaluaciones')->where('convocatoria_id', $edicion)->sum('peso'));

        // Otra edición tiene su propia suma.
        $this->evaluacion($otra, '100.00');
        $this->assertSame(1, DB::table('evaluaciones')->where('convocatoria_id', $otra)->count());
    }

    // ---------------------------------------------------------------- ayudas

    /** La sentencia debe fallar con $sqlstate, dentro de un SAVEPOINT para no abortar la transacción de la prueba. */
    private function assertEstado(string $sqlstate, callable $sentencia): void
    {
        try {
            DB::transaction(fn () => $sentencia());
        } catch (QueryException $e) {
            $this->assertSame($sqlstate, $e->errorInfo[0] ?? $e->getCode(), 'SQLSTATE inesperado: '.$e->getMessage());

            return;
        }
        $this->fail("La base debió rechazar la sentencia con $sqlstate.");
    }

    private function edicion(): int
    {
        $this->n++;
        $curso = Curso::create(['codigo' => 'PGF-'.$this->n, 'nombre' => 'Curso '.$this->n, 'descripcion' => 'x', 'categoria' => 'tecnologia', 'duracion_horas' => 10,
            'precio' => 100, 'modalidad' => 'virtual', 'horario' => 'Lun', 'cupos_maximos' => 50, 'cupos_disponibles' => 50, 'estado' => 'activo']);
        $c = new Convocatoria(['nombre' => null, 'modalidad' => 'virtual', 'fecha_inicio' => now()->subDay()->toDateString(), 'fecha_fin' => now()->addDays(30)->toDateString(), 'cupos_maximos' => 20]);
        $c->identificador = (string) Str::uuid();
        $c->codigo = 'CNV-2026-7'.str_pad((string) $this->n, 3, '0', STR_PAD_LEFT);
        $c->curso_id = $curso->id;
        $c->estado = 'en_curso';
        $c->cupos_disponibles = 20;
        $c->revision = 1;
        $c->save();

        return $c->id;
    }

    /** @return array{0: int, 1: int} id de matrícula y de su obligación vigente (con una cuota de 300.00) */
    private function matriculaConObligacion(): array
    {
        $edicion = $this->edicion();
        $c = Convocatoria::query()->findOrFail($edicion);
        $e = Estudiante::create(['dni' => (string) (60000000 + $this->n), 'nombres' => 'Ana', 'apellidos' => 'Quispe', 'estado' => 'activo']);
        $m = Matricula::create(['estudiante_id' => $e->id, 'curso_id' => $c->curso_id, 'convocatoria_id' => $c->id, 'fecha_matricula' => now()->toDateString(), 'estado' => 'pendiente', 'monto_pagado' => 0]);
        $obligacion = $this->obligacion($m->id);
        DB::table('cuotas_obligacion')->insert(['obligacion_pago_id' => $obligacion, 'numero' => 1, 'monto' => '300.00', 'vence' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);

        return [$m->id, $obligacion];
    }

    private function obligacion(int $matricula): int
    {
        return DB::table('obligaciones_pago')->insertGetId(['identificador' => (string) Str::uuid(), 'matricula_id' => $matricula, 'concepto' => 'Pago de prueba', 'plan' => 'completo',
            'monto_total' => '300.00', 'moneda' => 'PEN', 'estado' => 'vigente', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param array<string, mixed> $extra */
    private function transaccion(int $obligacion, string $tipo, string $estado, string $monto, array $extra = []): int
    {
        return DB::table('transacciones_pago')->insertGetId($extra + ['identificador' => (string) Str::uuid(), 'obligacion_pago_id' => $obligacion,
            'metodo_pago_id' => $this->metodoYape(), 'tipo' => $tipo, 'estado' => $estado, 'monto' => $monto,
            'fecha_pago' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** El método lo siembra la migración, pero otras pruebas de PostgreSQL vacían tablas: aquí se garantiza que exista. */
    private function metodoYape(): int
    {
        $id = DB::table('metodos_pago')->where('codigo', 'yape')->value('id');

        return (int) ($id ?? DB::table('metodos_pago')->insertGetId(['codigo' => 'yape', 'nombre' => 'Yape', 'activo' => true, 'created_at' => now(), 'updated_at' => now()]));
    }

    /** @param array<string, mixed> $extra */
    private function sesion(int $edicion, int $numero, array $extra = []): int
    {
        return DB::table('sesiones_clase')->insertGetId($extra + ['identificador' => (string) Str::uuid(), 'convocatoria_id' => $edicion, 'numero' => $numero,
            'fecha' => now()->toDateString(), 'hora_inicio' => '18:00:00', 'hora_fin' => '20:00:00', 'estado' => 'programada', 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function asistencia(int $sesion, int $matricula, string $estado): int
    {
        return DB::table('asistencias')->insertGetId(['sesion_clase_id' => $sesion, 'matricula_id' => $matricula, 'estado' => $estado, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function evaluacion(int $edicion, string $peso): int
    {
        return DB::table('evaluaciones')->insertGetId(['identificador' => (string) Str::uuid(), 'convocatoria_id' => $edicion, 'nombre' => 'Examen', 'peso' => $peso, 'orden' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
}
