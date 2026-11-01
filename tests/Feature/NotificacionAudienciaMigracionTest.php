<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Migración de audiencia sobre datos históricos: se aplican solo las migraciones ANTERIORES a la de
 * audiencia (no las futuras), se insertan filas como las que existen hoy y después se ejecuta la nueva.
 * También fija que up() rechaza un esquema parcial sin modificarlo.
 * SQLite en memoria; la rama PostgreSQL (CHECK + función de trigger) no se ha ejecutado.
 */
class NotificacionAudienciaMigracionTest extends TestCase
{
    private const MIGRACION = '2026_10_31_000032_add_audiencia_to_notificaciones_internas.php';

    /** Triggers SQLite que la migración debe dejar instalados (restricciones y reglas de cambio). */
    private const TRIGGERS_RESTRICCIONES = [
        'notificaciones_internas_audiencia_valida_INSERT',
        'notificaciones_internas_audiencia_valida_UPDATE',
        'notificaciones_internas_general_sin_destinatario_INSERT',
        'notificaciones_internas_general_sin_destinatario_UPDATE',
        'notificaciones_internas_indeterminada_sin_destinatario_INSERT',
        'notificaciones_internas_indeterminada_sin_destinatario_UPDATE',
    ];

    private const TRIGGERS_REGLAS = [
        'notificaciones_internas_personal_con_destinatario',
        'notificaciones_internas_audiencia_inmutable',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();

        Artisan::call('migrate', ['--path' => self::anteriores(glob(database_path('migrations/*.php')) ?: []), '--realpath' => true, '--force' => true]);
        $this->assertFalse(Schema::hasColumn('notificaciones_internas', 'audiencia'));
        $this->assertSame(0, DB::table('migrations')->where('migration', '>=', basename(self::MIGRACION, '.php'))->count());
    }

    /**
     * Solo migraciones con nombre anterior al de audiencia (el orden de Laravel es el del nombre).
     *
     * @param  list<string>  $rutas
     * @return list<string>
     */
    private static function anteriores(array $rutas): array
    {
        return collect($rutas)
            ->filter(fn (string $ruta): bool => strcmp(basename($ruta), self::MIGRACION) < 0)
            ->sort()->values()->all();
    }

    public function test_history_preparation_excludes_the_audience_migration_and_any_later_one(): void
    {
        $rutas = [
            '/m/2026_11_02_000040_futura_que_dependeria_de_audiencia.php',
            '/m/'.self::MIGRACION,
            '/m/2026_10_30_000031_add_reclamaciones_permissions.php',
            '/m/0001_01_01_000000_create_users_table.php',
            '/m/2026_10_31_000033_posterior_del_mismo_dia.php',
        ];

        $this->assertSame([
            '/m/0001_01_01_000000_create_users_table.php',
            '/m/2026_10_30_000031_add_reclamaciones_permissions.php',
        ], self::anteriores($rutas));
    }

    public function test_historical_rows_are_classified_without_guessing_and_without_losing_data(): void
    {
        $usuario = $this->usuario();
        $personal = $this->historica($usuario, 'Aviso personal', leida: true);
        $nula = $this->historica(null, 'Cierre mensual', leida: false);       // ¿general o huérfana? No se adivina.
        $huerfana = $this->historica(null, 'Nueva solicitud de aprobación', leida: true);
        $antes = $this->filas();

        $this->migrar();

        $despues = $this->filas();
        $this->assertSame(
            [[$personal, 'personal'], [$nula, 'indeterminada'], [$huerfana, 'indeterminada']],
            array_map(fn (array $f): array => [$f['id'], $f['audiencia']], $despues),
        );
        foreach ($antes as $i => $fila) {
            $this->assertSame($fila, array_diff_key($despues[$i], ['audiencia' => true]), 'Mensajes, fechas y lectura intactos');
        }
        $this->assertSame(0, DB::table('notificaciones_internas')->where('audiencia', 'general')->count());
        $this->assertEqualsCanonicalizing([...self::TRIGGERS_RESTRICCIONES, ...self::TRIGGERS_REGLAS], array_keys($this->triggers()));
    }

    public function test_after_migrating_the_constraints_apply_and_null_on_delete_is_kept(): void
    {
        $usuario = $this->usuario();
        $personal = $this->historica($usuario, 'Aviso personal', leida: false);
        $this->migrar();

        DB::table('users')->where('id', $usuario)->delete();

        $fila = DB::table('notificaciones_internas')->where('id', $personal)->first();
        $this->assertNull($fila->user_id);
        $this->assertSame('personal', $fila->audiencia);
        $this->expectException(QueryException::class);
        DB::table('notificaciones_internas')->where('id', $personal)->update(['audiencia' => 'general']);
    }

    // --- up() ante esquemas completos e incompletos -----------------------------------------

    public function test_up_is_a_no_op_on_a_complete_schema(): void
    {
        $this->historica(null, 'Histórica', leida: false);
        $this->migrar();
        $antes = $this->esquema();

        $this->migracion()->up();

        $this->assertSame($antes, $this->esquema());
    }

    public function test_inventory_and_repeated_up_preserve_every_value_of_representative_rows(): void
    {
        $this->migrar();
        $esperada = [
            'user_id' => $this->usuario(), 'audiencia' => 'personal', 'titulo' => 'Aviso «ficticio» — ñandú ✓',
            'mensaje' => "Línea 1 con comillas 'simples' y \"dobles\".\nLínea 2: 100 % ficticia; \\ barra.",
            'tipo' => 'advertencia', 'leida' => 1, 'vinculo' => '/admin/procesos/7?pestaña=historial',
            'created_at' => '2026-09-15 08:30:45', 'updated_at' => '2026-10-02 17:05:09',
        ];
        $personal = DB::table('notificaciones_internas')->insertGetId($esperada);
        DB::table('notificaciones_internas')->insert([
            ['user_id' => null, 'audiencia' => 'general', 'titulo' => 'General', 'mensaje' => 'M', 'tipo' => 'info', 'leida' => 0, 'vinculo' => null, 'created_at' => '2026-10-03 09:00:00', 'updated_at' => '2026-10-03 09:00:00'],
            ['user_id' => null, 'audiencia' => 'indeterminada', 'titulo' => 'Indeterminada', 'mensaje' => 'M', 'tipo' => 'info', 'leida' => 1, 'vinculo' => '/x', 'created_at' => '2026-10-04 09:00:00', 'updated_at' => '2026-10-05 10:00:00'],
        ]);

        $antes = $this->filas();
        $fila = collect($antes)->firstWhere('id', $personal);
        foreach ($esperada as $columna => $valor) {
            $this->assertEquals($valor, $fila[$columna], "La instantánea refleja el valor real de $columna");
        }
        $esquema = $this->esquema();

        $migracion = $this->migracion();
        $this->assertSame([], (new \ReflectionMethod($migracion, 'inventario'))->invoke($migracion)['faltan']);
        $this->assertSame($antes, $this->filas(), 'Tras el inventario');
        $this->migracion()->up();
        $this->assertSame($antes, $this->filas(), 'Tras up() sobre el esquema completo');
        $this->migracion()->up();
        $this->assertSame($antes, $this->filas(), 'Tras repetir up()');
        $this->assertSame($esquema, $this->esquema());
    }

    public function test_up_rejects_an_existing_column_without_its_constraints(): void
    {
        $this->instalarYQuitar(self::TRIGGERS_RESTRICCIONES);

        $this->assertUpRechazaSinCambios(self::TRIGGERS_RESTRICCIONES);
    }

    public function test_up_rejects_an_existing_column_without_its_change_rules(): void
    {
        $this->instalarYQuitar(self::TRIGGERS_REGLAS);

        $this->assertUpRechazaSinCambios(self::TRIGGERS_REGLAS);
    }

    public function test_up_rejects_a_bare_column_without_any_trigger(): void
    {
        $this->instalarYQuitar([...self::TRIGGERS_RESTRICCIONES, ...self::TRIGGERS_REGLAS]);

        $this->assertUpRechazaSinCambios([...self::TRIGGERS_RESTRICCIONES, ...self::TRIGGERS_REGLAS]);
    }

    public function test_up_rejects_a_single_missing_trigger_without_destructive_changes(): void
    {
        $this->instalarYQuitar(['notificaciones_internas_audiencia_valida_UPDATE']);

        $this->assertUpRechazaSinCambios(['notificaciones_internas_audiencia_valida_UPDATE']);
    }

    public function test_up_rejects_a_trigger_with_the_expected_name_but_a_different_definition(): void
    {
        $this->instalarYQuitar(['notificaciones_internas_audiencia_inmutable']);
        DB::unprepared('CREATE TRIGGER notificaciones_internas_audiencia_inmutable BEFORE UPDATE ON notificaciones_internas BEGIN SELECT 1; END');

        $this->assertUpRechazaSinCambios(['notificaciones_internas_audiencia_inmutable']);
    }

    public function test_up_rejects_leftover_objects_when_the_column_is_missing(): void
    {
        $this->historica(null, 'Histórica', leida: false);
        DB::unprepared('CREATE TRIGGER notificaciones_internas_audiencia_inmutable BEFORE UPDATE ON notificaciones_internas BEGIN SELECT 1; END');

        $this->assertUpRechazaSinCambios(['notificaciones_internas_audiencia_inmutable']);
        $this->assertFalse(Schema::hasColumn('notificaciones_internas', 'audiencia'));
    }

    // --- down() ------------------------------------------------------------------------------

    public function test_down_refuses_while_indeterminate_rows_exist(): void
    {
        $this->historica(null, 'Histórica', leida: false);
        $this->migrar();

        $this->assertReversionRechazada();
        $this->assertSame('indeterminada', DB::table('notificaciones_internas')->value('audiencia'));
    }

    public function test_down_refuses_while_orphaned_personal_rows_exist(): void
    {
        $usuario = $this->usuario();
        $this->migrar();
        $personal = $this->crear($usuario, 'personal');
        DB::table('users')->where('id', $usuario)->delete();

        $this->assertReversionRechazada();
        $fila = DB::table('notificaciones_internas')->where('id', $personal)->first();
        $this->assertSame(['personal', null], [$fila->audiencia, $fila->user_id]);
    }

    public function test_down_is_exact_when_no_row_would_be_exposed(): void
    {
        $usuario = $this->usuario();
        $this->migrar();
        $personal = $this->crear($usuario, 'personal');
        $general = $this->crear(null, 'general');

        $this->migracion()->down();

        $this->assertFalse(Schema::hasColumn('notificaciones_internas', 'audiencia'));
        $this->assertSame([[$personal, $usuario], [$general, null]], DB::table('notificaciones_internas')->orderBy('id')
            ->get()->map(fn ($f): array => [$f->id, $f->user_id])->all());
        // Sin triggers residuales: la tabla vuelve a aceptar inserciones del esquema anterior.
        $this->historica(null, 'Posterior', leida: false);
        $this->assertSame([], $this->triggers());
    }

    // --- Utilidades ------------------------------------------------------------------------

    /** @param list<string> $faltantes */
    private function assertUpRechazaSinCambios(array $faltantes): void
    {
        $antes = $this->esquema();

        try {
            $this->migracion()->up();
            $this->fail('up() debía rechazar el esquema incompleto.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('incompleto', $e->getMessage());
            foreach ($faltantes as $nombre) {
                $this->assertStringContainsString($nombre, $e->getMessage());
            }
        }

        $this->assertSame($antes, $this->esquema(), 'up() no debe crear, borrar ni reconstruir nada');
    }

    private function assertReversionRechazada(): void
    {
        $antes = $this->esquema();

        try {
            $this->migracion()->down();
            $this->fail('down() debía rechazar la reversión.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('audiencia', $e->getMessage());
        }

        $this->assertSame($antes, $this->esquema());
    }

    /** Instala la migración con datos y retira los triggers indicados para simular un esquema parcial. */
    private function instalarYQuitar(array $triggers): void
    {
        $usuario = $this->usuario();
        $this->historica($usuario, 'Aviso personal', leida: false);
        $this->historica(null, 'Histórica', leida: true);
        $this->migrar();
        foreach ($triggers as $trigger) {
            DB::unprepared("DROP TRIGGER $trigger");
        }
    }

    /** @return array{columnas: list<string>, triggers: array<string, string>, filas: list<array<string, mixed>>} */
    private function esquema(): array
    {
        return [
            'columnas' => Schema::getColumnListing('notificaciones_internas'),
            'triggers' => $this->triggers(),
            'filas' => $this->filas(),
        ];
    }

    /** @return array<string, string> */
    private function triggers(): array
    {
        return DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'notificaciones_internas')
            ->orderBy('name')->pluck('sql', 'name')->all();
    }

    /** @return list<array<string, mixed>> */
    private function filas(): array
    {
        return DB::table('notificaciones_internas')->orderBy('id')->get()->map(fn ($f): array => (array) $f)->all();
    }

    private function migrar(): void
    {
        Artisan::call('migrate', ['--path' => database_path('migrations/'.self::MIGRACION), '--realpath' => true, '--force' => true]);
        $this->assertTrue(Schema::hasColumn('notificaciones_internas', 'audiencia'));
    }

    private function migracion(): object
    {
        return require database_path('migrations/'.self::MIGRACION);
    }

    private function usuario(): int
    {
        return DB::table('users')->insertGetId(['name' => 'Ficticio', 'email' => 'ficticio@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function crear(?int $userId, string $audiencia): int
    {
        return DB::table('notificaciones_internas')->insertGetId([
            'user_id' => $userId, 'audiencia' => $audiencia, 'titulo' => 'Posterior', 'mensaje' => 'Mensaje ficticio.',
            'tipo' => 'info', 'leida' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function historica(?int $userId, string $titulo, bool $leida): int
    {
        return DB::table('notificaciones_internas')->insertGetId([
            'user_id' => $userId, 'titulo' => $titulo, 'mensaje' => 'Mensaje histórico ficticio.',
            'tipo' => 'info', 'leida' => $leida, 'vinculo' => '/admin/procesos',
            'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-02 11:00:00',
        ]);
    }
}
