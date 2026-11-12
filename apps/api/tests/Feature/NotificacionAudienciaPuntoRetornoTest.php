<?php

declare(strict_types=1);

namespace Tests\Feature;

use Exception;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDOException;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\PdoFalible;
use Tests\TestCase;

/**
 * Punto de retorno del inventario de la migración 2026_10_31_000032 (enPuntoDeRetorno), con SAVEPOINT reales de
 * SQLite en memoria. El inventario PostgreSQL lo usa para crear y deshacer sus CHECK de referencia.
 *
 * Contrato: el trabajo se ejecuta una vez; sus efectos se deshacen siempre; la transacción exterior sigue
 * utilizable; un error del trabajo se relanza como el MISMO objeto (sin convertirlo en DeadlockException, como
 * hace DB::transaction() anidada ante errores de concurrencia); si no se puede deshacer el punto de retorno, se
 * aborta la transacción exterior y se informa con el error original encadenado.
 *
 * No es evidencia de PostgreSQL (estado abortado de la transacción, 25P02): eso se valida en el clúster 55433.
 * Sin RefreshDatabase: cada prueba controla el nivel de transacción y crea su propia tabla en memoria.
 */
class NotificacionAudienciaPuntoRetornoTest extends TestCase
{
    private const TABLA = 'punto_retorno_prueba';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        Schema::create(self::TABLA, function (Blueprint $table): void {
            $table->id();
            $table->string('nombre')->unique();
        });
    }

    public function test_at_level_zero_it_uses_its_own_transaction_and_always_reverts(): void
    {
        $llamadas = 0;
        $resultado = $this->migracion()->enPuntoDeRetorno(function () use (&$llamadas): string {
            $llamadas++;
            $this->insertar('dentro');
            $this->assertSame(1, DB::transactionLevel());

            return 'leído';
        });

        $this->assertSame('leído', $resultado);
        $this->assertSame(1, $llamadas);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame([], $this->nombres());
    }

    public function test_inside_a_transaction_only_the_work_is_reverted_and_the_outer_transaction_continues(): void
    {
        DB::beginTransaction();
        $this->insertar('exterior');

        $this->migracion()->enPuntoDeRetorno(fn () => $this->insertar('dentro'));

        $this->assertSame(1, DB::transactionLevel(), 'El contador de Laravel no cambia');
        $this->assertSame(['exterior'], $this->nombres());
        $this->insertar('después');
        DB::commit();
        $this->assertSame(['exterior', 'después'], $this->nombres());
    }

    public function test_a_concurrency_error_is_rethrown_unchanged_and_never_converted_or_retried(): void
    {
        $original = $this->errorSql('40P01', 'deadlock detected');
        DB::beginTransaction();
        $this->insertar('exterior');
        $llamadas = 0;

        try {
            $this->migracion()->enPuntoDeRetorno(function () use ($original, &$llamadas): void {
                $llamadas++;
                $this->insertar('dentro');
                throw $original;
            });
            $this->fail('Debió propagarse el error original');
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(DeadlockException::class, $e);
            $this->assertSame($original, $e, 'Mismo objeto: misma clase, SQLSTATE y diagnóstico');
            $this->assertSame('40P01', $e->errorInfo[0]);
        }

        $this->assertSame(1, $llamadas, 'Sin reintentos');
        $this->assertSame(1, DB::transactionLevel(), 'El contador no se decrementa sin deshacer el punto');
        $this->assertSame(['exterior'], $this->nombres(), 'El trabajo se deshizo; lo exterior se conserva');
        $this->insertar('después');
        DB::rollBack();
    }

    public function test_a_real_sql_error_keeps_its_class_sqlstate_and_the_outer_transaction_stays_usable(): void
    {
        DB::beginTransaction();
        $this->insertar('repetido');
        $capturada = null;

        try {
            $this->migracion()->enPuntoDeRetorno(function () use (&$capturada): void {
                $this->insertar('dentro');
                try {
                    $this->insertar('repetido'); // UNIQUE
                } catch (\Throwable $x) {
                    $capturada = $x;
                    throw $x;
                }
            });
            $this->fail('Debió propagarse la violación de unicidad');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(QueryException::class, $capturada);
            $this->assertSame($capturada, $e, 'Se relanza el mismo objeto que lanzó la sentencia');
            $this->assertSame('23000', $e->errorInfo[0]);
            $this->assertStringContainsString('UNIQUE constraint failed: '.self::TABLA.'.nombre', $e->getMessage());
        }

        $this->assertSame(1, DB::transactionLevel());
        $this->assertSame(['repetido'], $this->nombres());
        DB::rollBack();
    }

    public function test_an_error_at_level_zero_is_rethrown_unchanged_and_reverted(): void
    {
        $original = $this->errorSql('40001', 'could not serialize access');

        try {
            $this->migracion()->enPuntoDeRetorno(function () use ($original): void {
                $this->insertar('dentro');
                throw $original;
            });
            $this->fail('Debió propagarse el error original');
        } catch (\Throwable $e) {
            $this->assertSame($original, $e);
        }

        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame([], $this->nombres());
    }

    public function test_an_unrecoverable_savepoint_after_an_error_aborts_the_outer_transaction_and_keeps_the_original(): void
    {
        $original = $this->errorSql('42710', 'constraint "notif_audiencia_ref_2" already exists');
        DB::beginTransaction();
        $this->insertar('exterior');

        try {
            $this->migracion()->enPuntoDeRetorno(function () use ($original): void {
                $this->insertar('dentro');
                DB::statement('RELEASE SAVEPOINT notif_audiencia_inventario'); // el punto deja de existir
                throw $original;
            });
            $this->fail('Debió abortar');
        } catch (\Throwable $e) {
            $this->assertSame(RuntimeException::class, $e::class);
            $this->assertStringContainsString('no se pudo deshacer el punto de retorno', $e->getMessage());
            $this->assertStringContainsString('se abortó la transacción exterior', $e->getMessage());
            $this->assertStringContainsString(QueryException::class.' SQLSTATE 42710', $e->getMessage());
            $this->assertSame($original, $e->getPrevious(), 'El error original queda encadenado');
        }

        $this->assertSame(0, DB::transactionLevel(), 'Transacción exterior abortada');
        $this->assertFalse(DB::getPdo()->inTransaction());
        $this->assertSame([], $this->nombres(), 'Nada de la transacción exterior se confirmó');
    }

    public function test_an_unrecoverable_savepoint_after_success_also_aborts_the_outer_transaction(): void
    {
        DB::beginTransaction();
        $this->insertar('exterior');

        try {
            $this->migracion()->enPuntoDeRetorno(function (): void {
                $this->insertar('dentro');
                DB::statement('RELEASE SAVEPOINT notif_audiencia_inventario');
            });
            $this->fail('Debió abortar');
        } catch (\Throwable $e) {
            $this->assertSame(RuntimeException::class, $e::class);
            $this->assertStringContainsString('no se pudo deshacer el punto de retorno', $e->getMessage());
            $this->assertInstanceOf(QueryException::class, $e->getPrevious(), 'Se encadena el fallo de la reversión');
        }

        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame([], $this->nombres(), 'Ni el trabajo ni lo exterior persisten');
    }

    public function test_when_even_the_full_rollback_fails_the_connection_is_discarded_and_nothing_can_be_committed(): void
    {
        $pdo = $this->conectarConPdoFalible();
        $original = $this->errorSql('42710', 'constraint "notif_audiencia_ref_2" already exists');
        $ejecutado = false;
        DB::beginTransaction();
        DB::afterCommit(function () use (&$ejecutado): void {
            $ejecutado = true;
        });
        $this->insertar('exterior');
        $pdo->fallarRollback = true;

        try {
            $this->migracion()->enPuntoDeRetorno(function () use ($original): void {
                $this->insertar('dentro');
                DB::statement('RELEASE SAVEPOINT notif_audiencia_inventario'); // 1.er fallo: ROLLBACK TO SAVEPOINT
                throw $original;
            });
            $this->fail('Debió abortar');
        } catch (\Throwable $e) {
            $this->assertSame(RuntimeException::class, $e::class);
            $this->assertSame($original, $e->getPrevious(), 'El error original queda encadenado');
            $mensaje = $e->getMessage();
            $this->assertStringContainsString(QueryException::class.' SQLSTATE 42710', $mensaje, 'Error original');
            $this->assertStringContainsString('Fallo al deshacer el punto de retorno', $mensaje);
            $this->assertStringContainsString('Fallo del rollback completo', $mensaje);
            $this->assertStringContainsString('rollback simulado fallido', $mensaje);
            $this->assertStringContainsString('conexión descartada', $mensaje);
            $this->assertStringNotContainsString('se revirtió por completo', $mensaje, 'No se afirma una reversión no demostrada');
        }

        $this->assertSame(0, DB::transactionLevel(), 'Contador de Laravel a 0 (setPdo/disconnect)');
        $this->assertNull(DB::connection()->getRawPdo(), 'La conexión de estado dudoso no se reutiliza');
        $this->assertTrue($pdo->inTransaction(), 'La transacción del PDO descartado sigue abierta: solo el cierre de sesión la revierte');
        DB::commit(); // un commit accidental posterior no llega a ningún PDO
        $this->assertSame(0, $pdo->commits, 'Nunca se confirmó nada');
        $this->assertFalse($ejecutado, 'Los callbacks afterCommit de la transacción abortada no se ejecutan');
        DB::beginTransaction();
        DB::commit();
        $this->assertFalse($ejecutado, 'Ni al confirmar una transacción nueva');
    }

    public function test_when_the_transaction_is_its_own_and_its_rollback_fails_the_connection_is_discarded(): void
    {
        $pdo = $this->conectarConPdoFalible();
        $original = $this->errorSql('40001', 'could not serialize access');
        $pdo->fallarRollback = true;

        try {
            $this->migracion()->enPuntoDeRetorno(function () use ($original): void {
                $this->insertar('dentro');
                throw $original;
            });
            $this->fail('Debió abortar');
        } catch (\Throwable $e) {
            $this->assertSame(RuntimeException::class, $e::class);
            $this->assertSame($original, $e->getPrevious());
            $this->assertStringContainsString(QueryException::class.' SQLSTATE 40001', $e->getMessage());
            $this->assertStringContainsString('rollback simulado fallido', $e->getMessage());
            $this->assertStringContainsString('conexión descartada', $e->getMessage());
        }

        $this->assertSame(0, DB::transactionLevel());
        $this->assertNull(DB::connection()->getRawPdo());
        $this->assertSame(0, $pdo->commits);
    }

    public function test_a_successful_work_followed_by_a_double_failure_reports_the_recovery_errors_without_an_original(): void
    {
        $pdo = $this->conectarConPdoFalible();
        DB::beginTransaction();
        $pdo->fallarRollback = true;

        try {
            $this->migracion()->enPuntoDeRetorno(function (): void {
                DB::statement('RELEASE SAVEPOINT notif_audiencia_inventario');
            });
            $this->fail('Debió abortar');
        } catch (\Throwable $e) {
            $this->assertSame(RuntimeException::class, $e::class);
            $this->assertStringNotContainsString('Error original', $e->getMessage());
            $this->assertInstanceOf(QueryException::class, $e->getPrevious(), 'Se encadena el fallo al deshacer');
            $this->assertStringContainsString('Fallo del rollback completo', $e->getMessage());
        }

        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(0, $pdo->commits);
    }

    public function test_when_the_full_rollback_succeeds_the_message_states_it_was_verified(): void
    {
        DB::beginTransaction();
        $this->insertar('exterior');

        try {
            $this->migracion()->enPuntoDeRetorno(function (): void {
                DB::statement('RELEASE SAVEPOINT notif_audiencia_inventario');
            });
            $this->fail('Debió abortar');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('se revirtió por completo', $e->getMessage());
            $this->assertStringNotContainsString('Fallo del rollback completo', $e->getMessage());
        }

        $this->assertSame(0, DB::transactionLevel());
        $this->assertNotNull(DB::connection()->getRawPdo(), 'La conexión se conserva cuando la reversión se comprobó');
    }

    /** Sustituye el PDO de la conexión por uno SQLite en memoria cuyo rollback puede fallar y que cuenta los commit. */
    private function conectarConPdoFalible(): PdoFalible
    {
        $pdo = new PdoFalible('sqlite::memory:');
        DB::connection()->setPdo($pdo);
        Schema::create(self::TABLA, function (Blueprint $table): void {
            $table->id();
            $table->string('nombre')->unique();
        });

        return $pdo;
    }

    private function insertar(string $nombre): void
    {
        DB::table(self::TABLA)->insert(['nombre' => $nombre]);
    }

    /** @return list<string> */
    private function nombres(): array
    {
        return DB::table(self::TABLA)->orderBy('id')->pluck('nombre')->all();
    }

    /** QueryException con la forma que produce PDO pgsql (código y errorInfo con SQLSTATE de texto). */
    private function errorSql(string $sqlstate, string $mensaje): QueryException
    {
        $pdo = new PDOException("SQLSTATE[$sqlstate]: 7 ERROR:  $mensaje");
        (new ReflectionProperty(Exception::class, 'code'))->setValue($pdo, $sqlstate);
        $pdo->errorInfo = [$sqlstate, 7, "ERROR:  $mensaje"];

        return new QueryException('pgsql', 'ALTER TABLE simulada', [], $pdo);
    }

    private function migracion(): object
    {
        return require database_path('migrations/2026_10_31_000032_add_audiencia_to_notificaciones_internas.php');
    }
}
