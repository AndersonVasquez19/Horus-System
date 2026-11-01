<?php

declare(strict_types=1);

namespace Tests\PostgresIntegrity;

use App\Models\NotificacionInterna;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\PostgresIntegrity\Concerns\RequiresIsolatedPostgres;
use Tests\Support\EscenarioReversible;
use Tests\TestCase;

/**
 * Validación en PostgreSQL real de la migración 2026_10_31_000032 (audiencia de notificaciones internas).
 * Solo perfil aislado (horus_l2_test en 127.0.0.1:55433, vía scripts/pgsql-test.php); se omite en otro entorno.
 *
 * Requiere la migración YA aplicada por el migrador. No usa RefreshDatabase ni DatabaseTruncation (ni
 * migrate:fresh ni truncado): cada prueba abre su propia transacción y la revierte. El DDL de PostgreSQL es
 * transaccional, así que down()/up(), las alteraciones del esquema y los datos ficticios no persisten.
 * Cada sentencia que debe fallar va en su propio SAVEPOINT. Datos ficticios.
 *
 * Primera ejecución (31/10/2026): 5/28 pasaron; 3 errores eran de este arnés (ReflectionMethod obtenido sobre
 * una instancia e invocado sobre otra; corregido) y 20 del inventario, que creaba una tabla temporal sin el
 * permiso TEMPORARY (corregido en la migración: referencias NOT VALID en un SAVEPOINT que se deshace).
 * test_11b añade la conservación del error original y la reversión parcial de las referencias.
 *
 * Revisión de Codex (H1-H4): el inventario usa enPuntoDeRetorno() (SAVEPOINT explícito, sin DB::transaction);
 * test_11b exige la sentencia exacta que falla, la secuencia del punto de retorno y el objeto ajeno intacto;
 * test_02 compara todas las filas valor a valor (la comprobación principal) y, de forma complementaria, su xmin
 * antes y después del inventario y de cada up(); cada escenario comprueba residuos antes del rollback y el estado
 * persistente después (si la conexión lo permite; si no, lo informa); las consultas se limitan al OID de la tabla
 * y al esquema public; test_12 vale en cualquier orden.
 *
 * xmin es solo un indicio complementario: es el identificador de la transacción que creó la versión de la fila.
 * No garantiza detectar toda escritura: en test_02 las filas se crean en la MISMA transacción que luego ejecuta
 * up(), de modo que un UPDATE posterior de esa transacción produciría una versión nueva con el mismo xmin. Por
 * eso la comparación completa de valores es la comprobación principal y se conserva.
 */
class NotificacionAudienciaPostgresTest extends TestCase
{
    use RequiresIsolatedPostgres;

    private const TABLA = 'notificaciones_internas';

    private const FUNCION = 'notificaciones_internas_audiencia_coherente';

    private const MIGRACION = '2026_10_31_000032_add_audiencia_to_notificaciones_internas';

    private const CHECKS = [
        'notificaciones_internas_audiencia_valida',
        'notificaciones_internas_general_sin_destinatario',
        'notificaciones_internas_indeterminada_sin_destinatario',
    ];

    /** Condiciones de los CHECK en el orden de la migración (ref_0, ref_1, ref_2). */
    private const CONDICIONES = [
        "audiencia IS NOT NULL AND audiencia IN ('personal', 'general', 'indeterminada')",
        "audiencia <> 'general' OR user_id IS NULL",
        "audiencia <> 'indeterminada' OR user_id IS NULL",
    ];

    private const REFERENCIA_TEMPORAL = 'pg_temp.notificaciones_internas_audiencia_ref';

    private function migracion(): object
    {
        return require base_path('database/migrations/'.self::MIGRACION.'.php');
    }

    /**
     * Cada require de la migración crea una clase anónima distinta: el método se obtiene e invoca sobre la
     * MISMA instancia.
     *
     * @return array<string, mixed>
     */
    private function catalogo(): array
    {
        $migracion = $this->migracion();

        return (new ReflectionMethod($migracion, 'catalogoPostgres'))->invoke($migracion);
    }

    /** @return array{columna: bool, faltan: list<string>, presentes: list<string>} */
    private function inventario(): array
    {
        $migracion = $this->migracion();

        return (new ReflectionMethod($migracion, 'inventario'))->invoke($migracion);
    }

    /** OID de public.notificaciones_internas resuelto ANTES de abrir la transacción (sigue valiendo si la prueba la renombra). */
    private ?int $oidTabla = null;

    private function oidTabla(): int
    {
        return $this->oidTabla ??= (int) DB::selectOne('SELECT to_regclass(?)::oid AS o', ['public.'.self::TABLA])->o;
    }

    /**
     * Ejecuta el escenario en una transacción que siempre se revierte (lógica en Tests\Support\EscenarioReversible,
     * probada sin PostgreSQL en tests/Unit/EscenarioReversibleTest.php):
     *  - si el escenario termina bien, ANTES del rollback se exige que el inventario no haya dejado residuos
     *    (salvo los objetos ajenos que el propio escenario creó a propósito);
     *  - el rollback se intenta siempre; el error original, el del rollback y los de limpieza se conservan todos;
     *  - DESPUÉS del rollback se comprueba el estado persistente solo si la conexión lo permite (rollback sin error
     *    y nivel 0); si no, el fallo informa expresamente que NO se pudo comprobar, nunca "limpio".
     *
     * @param  list<string>  $ajenas  restricciones notif_audiencia_ref_* creadas a propósito por el escenario
     */
    private function enTransaccion(callable $prueba, array $ajenas = []): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->oidTabla();
        DB::beginTransaction();
        EscenarioReversible::ejecutar(
            $prueba,
            fn () => $this->sinResiduosDelInventario($ajenas),
            fn () => DB::rollBack(),
            fn () => DB::transactionLevel(),
            fn () => $this->estadoPersistenteIntegro(),
        );
    }

    /** @return list<string> restricciones de referencia sobre ESTA tabla (por OID; no cuenta homónimas de otras tablas o esquemas) */
    private function restosDeReferencia(): array
    {
        return array_map(fn (object $f): string => (string) $f->conname, DB::select(
            "SELECT conname FROM pg_constraint WHERE conrelid = ? AND conname LIKE 'notif\\_audiencia\\_ref\\_%' ORDER BY conname",
            [$this->oidTabla()]
        ));
    }

    /** @return list<string> funciones notificaciones_internas* del esquema public */
    private function funcionesPublicas(): array
    {
        return array_map(fn (object $f): string => (string) $f->proname, DB::select(
            "SELECT p.proname FROM pg_proc p WHERE p.pronamespace = 'public'::regnamespace AND p.proname LIKE 'notificaciones\\_internas%' ORDER BY 1"
        ));
    }

    /**
     * Sin tabla temporal (versión anterior del inventario) y sin restricciones de referencia sobre esta tabla.
     *
     * @param  list<string>  $ajenas
     */
    private function sinResiduosDelInventario(array $ajenas = []): void
    {
        $this->assertNull(DB::selectOne('SELECT to_regclass(?) AS r', [self::REFERENCIA_TEMPORAL])->r, 'No debe existir tabla temporal');
        $this->assertSame($ajenas, $this->restosDeReferencia(), 'Solo pueden quedar las restricciones ajenas del escenario');
    }

    /** El inventario no debe dejar a esta sesión con un AccessExclusiveLock sobre la tabla (se libera al deshacer el SAVEPOINT). */
    private function sinBloqueoExclusivo(): void
    {
        $this->assertSame(0, (int) DB::selectOne(
            "SELECT count(*) AS n FROM pg_locks WHERE pid = pg_backend_pid() AND relation = ? AND mode = 'AccessExclusiveLock'",
            [$this->oidTabla()]
        )->n);
    }

    /**
     * Estado persistente esperado, independiente del orden de las pruebas: la tabla conserva OID y nombre en
     * public, existen sus 3 CHECK validados, una sola función del módulo y su trigger habilitado, y no hay restos
     * de referencia, funciones auxiliares ni tablas renombradas. Solo lecturas del catálogo.
     */
    private function estadoPersistenteIntegro(): void
    {
        $oid = $this->oidTabla();
        $this->assertSame($oid, (int) DB::selectOne('SELECT to_regclass(?)::oid AS o', ['public.'.self::TABLA])->o, 'Misma tabla, mismo nombre');
        $this->assertSame([], $this->restosDeReferencia());
        $this->assertNull(DB::selectOne('SELECT to_regclass(?) AS r', [self::REFERENCIA_TEMPORAL])->r);
        $this->assertSame([self::FUNCION], $this->funcionesPublicas(), 'Sin funciones auxiliares ni renombradas');
        $checks = DB::select("SELECT conname, convalidated FROM pg_constraint WHERE conrelid = ? AND contype = 'c' AND conname LIKE 'notificaciones\\_internas\\_%' ORDER BY conname", [$oid]);
        $this->assertSame([...self::CHECKS], array_map(fn (object $f): string => (string) $f->conname, $checks));
        $this->assertSame([true, true, true], array_map(fn (object $f): bool => in_array($f->convalidated, [true, 't'], true), $checks));
        $trigger = DB::select('SELECT tgenabled FROM pg_trigger WHERE tgrelid = ? AND NOT tgisinternal', [$oid]);
        $this->assertSame(['O'], array_map(fn (object $f): string => (string) $f->tgenabled, $trigger));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn(self::TABLA, 'audiencia'));
    }

    /** La excepción debe ser exactamente RuntimeException (una QueryException también lo es por herencia). */
    private function assertRuntimeExceptionPropia(\Throwable $e): void
    {
        $this->assertSame(RuntimeException::class, $e::class, 'Excepción inesperada: '.$e->getMessage());
    }

    private function evidencia(string $titulo, mixed $datos): void
    {
        fwrite(STDERR, PHP_EOL.'[evidencia] '.$titulo.': '.json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }

    /** La sentencia debe fallar con $sqlstate y nombrar $motivo; va en un SAVEPOINT para no abortar la transacción. */
    private function rechazo(string $sqlstate, string $motivo, callable $sentencia): void
    {
        try {
            DB::transaction(fn () => $sentencia());
        } catch (QueryException $e) {
            $this->assertSame($sqlstate, (string) $e->getCode(), 'SQLSTATE inesperado: '.$e->getMessage());
            $this->assertStringContainsString($motivo, $e->getMessage());

            return;
        }
        $this->fail("La base debió rechazar la sentencia ($sqlstate, $motivo).");
    }

    /** @param  array<string, mixed>  $extra */
    private function fila(array $extra): int
    {
        return (int) DB::table(self::TABLA)->insertGetId($extra + [
            'titulo' => 'Aviso ficticio', 'mensaje' => 'Mensaje ficticio de validación.', 'tipo' => 'info',
            'leida' => false, 'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-01 10:00:00',
        ]);
    }

    private function audiencia(int $id): ?string
    {
        return DB::table(self::TABLA)->where('id', $id)->value('audiencia');
    }

    /** @return array<string, mixed> instantánea comparable (sin las referencias de la tabla temporal) */
    private function huella(): array
    {
        $catalogo = $this->catalogo();
        unset($catalogo['referencias']);

        return $catalogo;
    }

    // ---------------------------------------------------------------------------------------------------------

    public function test_01_esquema_aplicado_y_catalogo_real(): void
    {
        $this->assertSame(1, DB::table('migrations')->where('migration', self::MIGRACION)->count());
        $this->evidencia('version', DB::selectOne("SELECT current_setting('server_version') AS v")->v);
        $this->evidencia('filas_iniciales', [
            'users' => DB::table('users')->count(), self::TABLA => DB::table(self::TABLA)->count(),
            'sin_destinatario_no_general' => DB::table(self::TABLA)->whereNull('user_id')->where('audiencia', '<>', 'general')->count(),
        ]);

        $this->enTransaccion(function (): void {
            $c = $this->catalogo();
            $this->sinResiduosDelInventario();
            $this->sinBloqueoExclusivo();

            $tipos = fn (array $fila): array => array_map(fn ($v): string => get_debug_type($v), $fila);
            $this->evidencia('tipos_pdo', [
                'columna' => $tipos($c['columna']), 'check' => $tipos($c['checks'][0]),
                'funcion' => $tipos(array_diff_key($c['funciones'][0], ['prosrc' => 1])), 'trigger' => $tipos($c['triggers'][0]),
            ]);
            $this->evidencia('columna', $c['columna']);
            $this->evidencia('checks', $c['checks']);
            $this->evidencia('referencias', $c['referencias']);
            $this->evidencia('funcion', array_diff_key($c['funciones'][0], ['prosrc' => 1]));
            $this->evidencia('trigger', $c['triggers'][0]);

            $this->assertIsInt($c['tabla_oid']);
            $this->assertSame('character varying(20)', $c['columna']['tipo']);
            $this->assertTrue((bool) $c['columna']['attnotnull']);

            $this->assertCount(3, $c['checks']);
            foreach ($c['checks'] as $check) {
                $this->assertContains($check['conname'], self::CHECKS);
                $this->assertSame('c', $check['contype']);
                // La referencia es la misma expresión creada NOT VALID: PostgreSQL añade exactamente ese sufijo.
                $this->assertSame($check['definicion'].' NOT VALID', $c['referencias'][$check['conname']]);
            }
            $this->assertCount(1, $c['funciones'], 'Una sola función con ese nombre en el esquema');
            $f = $c['funciones'][0];
            $this->assertSame([0, 'f', 'trigger', 'plpgsql'], [(int) $f['pronargs'], $f['prokind'], $f['retorno'], $f['lenguaje']]);

            $this->assertCount(1, $c['triggers']);
            $t = $c['triggers'][0];
            $this->assertSame((int) $f['oid'], (int) $t['tgfoid'], 'El trigger ejecuta exactamente esa función');
            $this->assertSame(self::FUNCION.'()', DB::selectOne('SELECT ?::oid::regprocedure::text AS f', [(int) $t['tgfoid']])->f);
            $this->assertSame(23, (int) $t['tgtype']);
            $this->assertSame('O', $t['tgenabled']);

            $this->assertSame(['columna' => true, 'faltan' => [], 'presentes' => [...self::CHECKS, self::FUNCION.'()', 'trigger '.self::FUNCION]], $this->inventario());
            $this->sinResiduosDelInventario();
            $this->sinBloqueoExclusivo();

            $fk = DB::selectOne(<<<'SQL'
                SELECT c.confdeltype, c.confrelid::regclass::text AS destino FROM pg_constraint c
                JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1]
                WHERE c.contype = 'f' AND c.conrelid = ? AND a.attname = 'user_id'
                SQL, [$this->oidTabla()]);
            $this->assertSame(['n', 'users'], [$fk->confdeltype, $fk->destino], 'FK user_id → users ON DELETE SET NULL');
        });
    }

    public function test_02_repetir_up_sobre_esquema_completo_no_modifica_nada(): void
    {
        $this->enTransaccion(function (): void {
            $u = User::factory()->create();
            // Filas representativas: texto no ASCII y multilínea, lectura, vínculo, fechas distintas, tres audiencias.
            $esperada = [
                'user_id' => $u->id, 'audiencia' => 'personal', 'titulo' => 'Aviso «ficticio» — ñandú ✓',
                'mensaje' => "Línea 1 con comillas 'simples' y \"dobles\".\nLínea 2: 100 % ficticia; \\ barra.",
                'tipo' => 'advertencia', 'leida' => true, 'vinculo' => '/admin/procesos/7?pestaña=historial',
                'created_at' => '2026-09-15 08:30:45', 'updated_at' => '2026-10-02 17:05:09',
            ];
            $personal = $this->fila($esperada);
            $this->fila(['user_id' => null, 'audiencia' => 'general', 'leida' => false, 'vinculo' => null]);
            $this->fila(['user_id' => null, 'audiencia' => 'indeterminada', 'leida' => true]);

            // Todas las filas de la tabla, con todas sus columnas. La comparación de valores es la comprobación
            // principal; xmin (transacción que creó la versión de la fila) es solo complementaria: un UPDATE o una
            // reescritura suele producir otro xmin, pero xmin no garantiza detectar toda escritura.
            $instantanea = fn (): array => array_map(fn (object $f): array => (array) $f, DB::select(
                'SELECT xmin::text AS version_fila, * FROM public.'.self::TABLA.' ORDER BY id'
            ));

            $antes = $instantanea();
            $fila = collect($antes)->firstWhere('id', $personal);
            $this->assertNotNull($fila);
            foreach ($esperada as $columna => $valor) {
                // Igualdad de valor: el tipo PHP de int8/bool lo decide PDO (se registra en test_01); la comparación
                // entre instantáneas de abajo sí es estricta.
                $this->assertEquals($valor, $fila[$columna], "La instantánea refleja el valor real de $columna");
            }
            $huella = $this->huella();

            $this->assertSame([], $this->inventario()['faltan']);
            $this->assertSame($antes, $instantanea(), 'Tras el inventario: mismas filas, valores y versiones');
            $this->sinResiduosDelInventario();
            $this->sinBloqueoExclusivo();

            $this->migracion()->up();
            $this->assertSame($antes, $instantanea(), 'Tras up() sobre el esquema completo');
            $this->migracion()->up();
            $this->assertSame($antes, $instantanea(), 'Tras repetir up()');
            $this->assertSame($huella, $this->huella(), 'Mismos OID, definiciones y estado del catálogo');
            $this->sinResiduosDelInventario();
            $this->sinBloqueoExclusivo();
        });
    }

    public function test_03_clasificacion_historica_real(): void
    {
        $this->enTransaccion(function (): void {
            $this->migracion()->down(); // Estado previo a 000032 (sin filas ambiguas en la base de pruebas).
            $this->assertFalse(DB::getSchemaBuilder()->hasColumn(self::TABLA, 'audiencia'));

            [$a, $b] = [User::factory()->create(), User::factory()->create()];
            $personal = $this->fila(['user_id' => $a->id, 'leida' => true, 'titulo' => 'Personal histórica']);
            $sinDestinatario = $this->fila(['user_id' => null, 'titulo' => 'Sin destinatario histórica']);
            $huerfana = $this->fila(['user_id' => $b->id, 'titulo' => 'Huérfana histórica']);
            DB::table('users')->where('id', $b->id)->delete(); // FK ON DELETE SET NULL
            $this->assertNull(DB::table(self::TABLA)->where('id', $huerfana)->value('user_id'));
            $antes = DB::table(self::TABLA)->orderBy('id')->get()->map(fn ($f) => (array) $f)->all();

            $this->migracion()->up();

            $this->assertSame('personal', $this->audiencia($personal));
            $this->assertSame('indeterminada', $this->audiencia($sinDestinatario));
            $this->assertSame('indeterminada', $this->audiencia($huerfana));
            $despues = DB::table(self::TABLA)->orderBy('id')->get()->map(fn ($f) => array_diff_key((array) $f, ['audiencia' => 1]))->all();
            $this->assertSame($antes, $despues, 'No se altera ningún otro dato');
            $this->assertSame([], $this->inventario()['faltan']);
            $this->sinResiduosDelInventario();
        });
    }

    public function test_04_restricciones_en_insercion(): void
    {
        $this->enTransaccion(function (): void {
            $u = User::factory()->create();
            $this->rechazo('23502', '"audiencia"', fn () => $this->fila(['user_id' => $u->id, 'audiencia' => null]));
            $this->rechazo('23514', 'notificaciones_internas_audiencia_valida', fn () => $this->fila(['user_id' => null, 'audiencia' => 'otra']));
            $this->rechazo('23514', 'notificaciones_internas_general_sin_destinatario', fn () => $this->fila(['user_id' => $u->id, 'audiencia' => 'general']));
            $this->rechazo('23514', 'notificaciones_internas_indeterminada_sin_destinatario', fn () => $this->fila(['user_id' => $u->id, 'audiencia' => 'indeterminada']));
            $this->rechazo('23514', 'notificaciones_internas_personal_con_destinatario', fn () => $this->fila(['user_id' => null, 'audiencia' => 'personal']));

            $this->assertSame('personal', NotificacionInterna::crearPersonal($u->id, ['titulo' => 'P', 'mensaje' => 'M'])->fresh()->audiencia);
            $this->assertSame('general', NotificacionInterna::crearGeneral(['titulo' => 'G', 'mensaje' => 'M'])->fresh()->audiencia);
        });
    }

    public function test_05_restricciones_en_actualizacion(): void
    {
        $this->enTransaccion(function (): void {
            [$u, $otro] = [User::factory()->create(), User::factory()->create()];
            $personal = $this->fila(['user_id' => $u->id, 'audiencia' => 'personal']);
            $general = $this->fila(['user_id' => null, 'audiencia' => 'general']);
            $indeterminada = $this->fila(['user_id' => null, 'audiencia' => 'indeterminada']);
            $q = fn (int $id) => DB::table(self::TABLA)->where('id', $id);

            $this->rechazo('23514', 'notificaciones_internas_audiencia_inmutable', fn () => $q($personal)->update(['audiencia' => 'general', 'user_id' => null]));
            $this->rechazo('23514', 'notificaciones_internas_audiencia_inmutable', fn () => $q($personal)->update(['user_id' => $otro->id]));
            $this->rechazo('23514', 'notificaciones_internas_audiencia_inmutable', fn () => $q($general)->update(['user_id' => $u->id]));
            $this->rechazo('23514', 'notificaciones_internas_audiencia_inmutable', fn () => $q($indeterminada)->update(['audiencia' => 'personal', 'user_id' => $u->id]));
            $this->rechazo('23514', 'notificaciones_internas_audiencia_inmutable', fn () => $q($general)->update(['audiencia' => 'indeterminada']));

            $q($personal)->update(['leida' => true, 'titulo' => 'Editada']); // otros campos sí
            $q($personal)->update(['user_id' => null]); // solo hacia NULL
            $this->assertSame([null, 'personal'], [$q($personal)->value('user_id'), $this->audiencia($personal)]);
        });
    }

    public function test_06_fk_on_delete_set_null_conserva_personal_y_privacidad(): void
    {
        $this->enTransaccion(function (): void {
            [$eliminado, $otro] = [User::factory()->create(), User::factory()->create()];
            $personal = NotificacionInterna::crearPersonal($eliminado->id, ['titulo' => 'Privada', 'mensaje' => 'M']);
            $general = NotificacionInterna::crearGeneral(['titulo' => 'Para todos', 'mensaje' => 'M']);

            DB::table('users')->where('id', $eliminado->id)->delete();

            $personal->refresh();
            $this->assertSame([null, 'personal'], [$personal->user_id, $personal->audiencia]);
            $visibles = NotificacionInterna::query()->visiblePara($otro)->pluck('id')->all();
            $this->assertNotContains($personal->id, $visibles, 'La huérfana no pasa a general');
            $this->assertContains($general->id, $visibles);
            $this->assertFalse($personal->esVisiblePara($otro));
        });
    }

    public function test_07_down_rechaza_historicos_ambiguos_sin_tocar_nada(): void
    {
        $rechazados = 0;
        foreach (['huérfana personal' => 'personal', 'indeterminada' => 'indeterminada'] as $caso => $tipo) {
            $this->enTransaccion(function () use ($tipo, $caso, &$rechazados): void {
                if ($tipo === 'personal') {
                    $u = User::factory()->create();
                    $this->fila(['user_id' => $u->id, 'audiencia' => 'personal']);
                    DB::table('users')->where('id', $u->id)->delete();
                } else {
                    $this->fila(['user_id' => null, 'audiencia' => 'indeterminada']);
                }
                $antes = $this->huella();

                try {
                    DB::transaction(fn () => $this->migracion()->down());
                    $this->fail("down() debió rechazar: $caso");
                } catch (RuntimeException $e) {
                    $this->assertRuntimeExceptionPropia($e);
                    $this->assertStringStartsWith('Reversión rechazada: 1 notificación(es)', $e->getMessage(), $caso);
                    $rechazados++;
                }
                $this->assertSame($antes, $this->huella(), "Esquema intacto tras el rechazo ($caso)");
            });
        }
    }

    public function test_08_down_limpio_elimina_todo_y_up_reclasifica_sin_adivinar(): void
    {
        $this->enTransaccion(function (): void {
            $u = User::factory()->create();
            $personal = NotificacionInterna::crearPersonal($u->id, ['titulo' => 'P', 'mensaje' => 'M'])->id;
            $general = NotificacionInterna::crearGeneral(['titulo' => 'G', 'mensaje' => 'M'])->id;
            $filas = DB::table(self::TABLA)->count();

            $this->migracion()->down();

            $this->assertFalse(DB::getSchemaBuilder()->hasColumn(self::TABLA, 'audiencia'));
            $this->assertSame([], $this->funcionesPublicas());
            $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM pg_trigger WHERE tgrelid = ? AND NOT tgisinternal', [$this->oidTabla()])->n);
            $this->assertSame(0, (int) DB::selectOne('SELECT count(*) AS n FROM pg_constraint WHERE conrelid = ? AND conname = ANY(?)', [$this->oidTabla(), '{'.implode(',', self::CHECKS).'}'])->n);
            $this->assertSame($filas, DB::table(self::TABLA)->count(), 'down() no borra filas');
            $this->migracion()->down(); // sin columna: no hace nada

            $this->migracion()->up();
            $this->assertSame('personal', $this->audiencia($personal));
            $this->assertSame('indeterminada', $this->audiencia($general), 'Tras revertir, user_id NULL no se presume general');
            $this->assertSame([], $this->inventario()['faltan']);
        });
    }

    /** @return array<string, array{0: list<string>, 1: string}> */
    public static function esquemasAlterados(): array
    {
        $f = self::FUNCION;
        $t = self::TABLA;
        $valida = "audiencia IS NOT NULL AND audiencia IN ('personal', 'general', 'indeterminada')";
        $recrear = fn (string $definicion): array => ["DROP TRIGGER $f ON $t", "CREATE TRIGGER $f $definicion"];

        return [
            'CHECK (true) con el nombre correcto' => [["ALTER TABLE $t DROP CONSTRAINT notificaciones_internas_audiencia_valida", "ALTER TABLE $t ADD CONSTRAINT notificaciones_internas_audiencia_valida CHECK (true)"], 'notificaciones_internas_audiencia_valida (definición distinta)'],
            'CHECK NOT VALID' => [["ALTER TABLE $t DROP CONSTRAINT notificaciones_internas_audiencia_valida", "ALTER TABLE $t ADD CONSTRAINT notificaciones_internas_audiencia_valida CHECK ($valida) NOT VALID"], 'notificaciones_internas_audiencia_valida (no validada)'],
            'CHECK NOT ENFORCED' => [["ALTER TABLE $t DROP CONSTRAINT notificaciones_internas_audiencia_valida", "ALTER TABLE $t ADD CONSTRAINT notificaciones_internas_audiencia_valida CHECK ($valida) NOT ENFORCED"], 'notificaciones_internas_audiencia_valida (no aplicada)'],
            'CHECK ausente' => [["ALTER TABLE $t DROP CONSTRAINT notificaciones_internas_general_sin_destinatario"], 'notificaciones_internas_general_sin_destinatario'],
            'columna admite NULL' => [["ALTER TABLE $t ALTER COLUMN audiencia DROP NOT NULL"], 'audiencia NOT NULL'],
            'columna de otro tipo' => [["ALTER TABLE $t ALTER COLUMN audiencia TYPE varchar(30)"], 'audiencia character varying(20) (tipo character varying(30))'],
            'trigger solo de réplica' => [["ALTER TABLE $t ENABLE REPLICA TRIGGER $f"], "trigger $f (deshabilitado o solo réplica)"],
            'trigger deshabilitado' => [["ALTER TABLE $t DISABLE TRIGGER $f"], "trigger $f (deshabilitado o solo réplica)"],
            'trigger solo INSERT' => [$recrear("BEFORE INSERT ON $t FOR EACH ROW EXECUTE FUNCTION $f()"), "trigger $f (eventos o modalidad distintos)"],
            'trigger AFTER' => [$recrear("AFTER INSERT OR UPDATE ON $t FOR EACH ROW EXECUTE FUNCTION $f()"), "trigger $f (eventos o modalidad distintos)"],
            'trigger por sentencia' => [$recrear("BEFORE INSERT OR UPDATE ON $t FOR EACH STATEMENT EXECUTE FUNCTION $f()"), "trigger $f (eventos o modalidad distintos)"],
            'trigger con WHEN' => [$recrear("BEFORE INSERT OR UPDATE ON $t FOR EACH ROW WHEN (NEW.audiencia IS NOT NULL) EXECUTE FUNCTION $f()"), "trigger $f (condición WHEN inesperada)"],
            'trigger UPDATE OF columna' => [$recrear("BEFORE INSERT OR UPDATE OF audiencia ON $t FOR EACH ROW EXECUTE FUNCTION $f()"), "trigger $f (argumentos o columnas inesperados)"],
            // La función con el nombre correcto existe, pero el trigger ejecuta otra (la original, renombrada).
            'trigger con otra función; homónima nueva' => [["ALTER FUNCTION $f() RENAME TO {$f}_original", '@CREAR_FUNCION'], "trigger $f (función distinta)"],
            'sobrecarga ambigua' => [['@CREAR_SOBRECARGA'], "$f() (sobrecargas ambiguas)"],
            'cuerpo distinto' => [["CREATE OR REPLACE FUNCTION $f() RETURNS trigger AS \$x\$ BEGIN RETURN NEW; END; \$x\$ LANGUAGE plpgsql"], "$f() (definición distinta)"],
            'firma distinta (RETURNS void)' => [['SET LOCAL check_function_bodies = off', "ALTER FUNCTION $f() RENAME TO {$f}_original", '@CREAR_FUNCION_VOID'], "$f() (firma distinta)"],
        ];
    }

    /** @param  list<string>  $ddl */
    #[DataProvider('esquemasAlterados')]
    public function test_09_inventario_rechaza_esquema_alterado(array $ddl, string $defecto): void
    {
        $cuerpo = $this->migracion()->cuerpoFuncionPostgres();
        $f = self::FUNCION;
        $especiales = [
            '@CREAR_FUNCION' => "CREATE FUNCTION $f() RETURNS trigger AS \$fn\${$cuerpo}\$fn\$ LANGUAGE plpgsql",
            // Las funciones de trigger no admiten argumentos declarados: la sobrecarga es una función normal homónima.
            '@CREAR_SOBRECARGA' => "CREATE FUNCTION $f(integer) RETURNS integer AS 'SELECT 1' LANGUAGE sql",
            '@CREAR_FUNCION_VOID' => "CREATE FUNCTION $f() RETURNS void AS \$fn\${$cuerpo}\$fn\$ LANGUAGE plpgsql",
        ];

        $this->enTransaccion(function () use ($ddl, $defecto, $especiales): void {
            foreach ($ddl as $sentencia) {
                DB::unprepared($especiales[$sentencia] ?? $sentencia);
            }

            $inventario = $this->inventario();
            $this->sinResiduosDelInventario();
            $this->evidencia('faltan', $inventario['faltan']);
            $this->assertTrue($inventario['columna']);
            $this->assertContains($defecto, $inventario['faltan']);

            $antes = $this->huella();
            try {
                DB::transaction(fn () => $this->migracion()->up());
                $this->fail('up() debió detenerse ante el esquema alterado');
            } catch (RuntimeException $e) {
                $this->assertRuntimeExceptionPropia($e);
                $this->assertStringContainsString('Esquema incompleto en '.self::TABLA.': la columna audiencia existe', $e->getMessage());
                $this->assertStringContainsString($defecto, $e->getMessage());
            }
            $this->assertSame($antes, $this->huella(), 'up() no modifica nada al detenerse');
            $this->sinResiduosDelInventario();
        });
    }

    public function test_10_columna_ausente_con_objetos_residuales(): void
    {
        $this->enTransaccion(function (): void {
            DB::statement('ALTER TABLE '.self::TABLA.' DROP COLUMN audiencia'); // arrastra sus CHECK; quedan función y trigger
            $inventario = $this->inventario();
            $this->assertFalse($inventario['columna']);
            $this->assertSame([self::FUNCION.'()', 'trigger '.self::FUNCION], $inventario['presentes']);

            try {
                DB::transaction(fn () => $this->migracion()->up());
                $this->fail('up() debió detenerse');
            } catch (RuntimeException $e) {
                $this->assertRuntimeExceptionPropia($e);
                $this->assertStringContainsString('la columna audiencia no existe pero ya hay objetos', $e->getMessage());
            }
            $this->assertFalse(DB::getSchemaBuilder()->hasColumn(self::TABLA, 'audiencia'), 'up() no creó nada');
        });
    }

    public function test_11_tabla_ausente(): void
    {
        $this->enTransaccion(function (): void {
            DB::statement('ALTER TABLE '.self::TABLA.' RENAME TO notificaciones_internas_renombrada_prueba');
            try {
                DB::transaction(fn () => $this->migracion()->up());
                $this->fail('up() debió detenerse');
            } catch (RuntimeException $e) {
                $this->assertRuntimeExceptionPropia($e);
                $this->assertStringContainsString('la tabla '.self::TABLA.' no existe', $e->getMessage());
            }
        });
    }

    public function test_11b_error_del_inventario_se_propaga_intacto_y_revierte_las_referencias(): void
    {
        $tabla = 'public.'.self::TABLA;
        $alter = fn (int $i): string => "ALTER TABLE $tabla ADD CONSTRAINT notif_audiencia_ref_$i CHECK (".self::CONDICIONES[$i].') NOT VALID';

        $this->enTransaccion(function () use ($tabla, $alter): void {
            // Un objeto ajeno ocupa el TERCER nombre de referencia: ref_0 y ref_1 se añaden y la tercera falla.
            DB::statement("ALTER TABLE $tabla ADD CONSTRAINT notif_audiencia_ref_2 CHECK (id > 0) NOT VALID");
            $ajena = fn (): array => (array) DB::selectOne(
                'SELECT oid::text AS oid, conrelid::text AS tabla, contype, pg_get_constraintdef(oid) AS definicion, convalidated::text AS validada '
                .'FROM pg_constraint WHERE conrelid = ? AND conname = ?',
                [$this->oidTabla(), 'notif_audiencia_ref_2']
            );
            $antes = $ajena();
            $this->assertSame((string) $this->oidTabla(), $antes['tabla']);

            DB::flushQueryLog();
            DB::enableQueryLog(); // solo registra sentencias terminadas: la que falla no aparece
            $capturada = null;
            try {
                $this->inventario();
            } catch (\Throwable $e) {
                $capturada = $e;
            } finally {
                DB::disableQueryLog();
            }
            $sentencias = array_column(DB::getQueryLog(), 'query');

            $this->assertNotNull($capturada, 'El inventario debió propagar el error original');
            // No vale cualquier excepción: la QueryException original, ni DeadlockException ni RuntimeException.
            $this->assertSame(QueryException::class, $capturada::class);
            $this->assertSame('42710', $capturada->errorInfo[0], 'SQLSTATE duplicate_object');
            $this->assertSame('42710', (string) $capturada->getCode());
            // Es exactamente el tercer ADD CONSTRAINT previsto, sobre public.notificaciones_internas.
            $this->assertSame($alter(2), $capturada->getSql());
            $this->assertStringContainsString('constraint "notif_audiencia_ref_2" for relation "'.self::TABLA.'" already exists', (string) $capturada->errorInfo[2]);
            // Diagnóstico del driver conservado tal cual.
            $previa = $capturada->getPrevious();
            $this->assertInstanceOf(\PDOException::class, $previa);
            $this->assertSame($capturada->errorInfo, $previa->errorInfo);
            // Secuencia: punto de retorno, los dos primeros ADD terminados y la reversión tras el fallo.
            $ddl = array_values(array_filter($sentencias, fn (string $q): bool => str_starts_with($q, 'ALTER TABLE') || str_contains($q, 'SAVEPOINT')));
            $this->assertSame(['SAVEPOINT notif_audiencia_inventario', $alter(0), $alter(1), 'ROLLBACK TO SAVEPOINT notif_audiencia_inventario', 'RELEASE SAVEPOINT notif_audiencia_inventario'], $ddl);

            $this->assertSame(['notif_audiencia_ref_2'], $this->restosDeReferencia(), 'ref_0 y ref_1 se revirtieron; solo queda la ajena');
            $this->assertSame($antes, $ajena(), 'El objeto ajeno permanece intacto (mismo OID, tabla, tipo, definición y validación)');
            $this->assertSame(1, DB::transactionLevel());
            $this->assertSame(1, (int) DB::selectOne('SELECT 1 AS ok')->ok, 'La transacción exterior sigue utilizable');
        }, ['notif_audiencia_ref_2']);
    }

    /**
     * Estado persistente correcto, válido en cualquier posición de la suite (no depende de ejecutarse al final):
     * catálogo esperado, inventario completo ejecutado en el nivel 0 (transacción propia que se revierte) y
     * ningún resto ni bloqueo después.
     */
    public function test_12_estado_persistente_integro_en_cualquier_orden(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->estadoPersistenteIntegro();
        $this->assertSame([], $this->inventario()['faltan']);
        $this->assertSame(0, DB::transactionLevel(), 'La transacción propia del inventario se cerró');
        $this->estadoPersistenteIntegro();
        $this->sinBloqueoExclusivo();
        $this->assertNull(DB::selectOne("SELECT to_regclass('public.notificaciones_internas_renombrada_prueba') AS r")->r);
        $this->evidencia('filas_persistentes', [
            'users' => DB::table('users')->count(), self::TABLA => DB::table(self::TABLA)->count(),
        ]);
    }
}
