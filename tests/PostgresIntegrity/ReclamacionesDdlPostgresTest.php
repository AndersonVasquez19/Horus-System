<?php

declare(strict_types=1);

namespace Tests\PostgresIntegrity;

use App\Models\Reclamacion;
use App\Models\User;
use App\Services\GestionReclamaciones;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresIntegrity\Concerns\ReclamacionesPostgres;
use Tests\PostgresIntegrity\Concerns\RequiresIsolatedPostgres;
use Tests\TestCase;

/**
 * Sprint 20 · Fase 2.1 — DDL de las migraciones 000030/000031 en PostgreSQL (escenarios D1-D11).
 * PREPARADAS, NO EJECUTADAS. Solo perfil aislado (horus_l2_test en 127.0.0.1:55433); se omiten en cualquier
 * otro entorno antes de arrancar Laravel. Datos ficticios.
 *
 * RefreshDatabase: cada prueba corre dentro de una transacción que se revierte; el DDL de PostgreSQL es
 * transaccional para down()/up(). D9 se retiró a una clase sin arranque Laravel y bloqueada:
 * db:wipe desconecta la conexión y NO queda contenido por esta transacción.
 * Cada sentencia que debe fallar va en su propio SAVEPOINT (assertSqlState).
 */
class ReclamacionesDdlPostgresTest extends TestCase
{
    use ReclamacionesPostgres;
    use RefreshDatabase;
    use RequiresIsolatedPostgres;

    private const TABLAS = ['reclamaciones', 'reclamacion_contadores', 'reclamacion_historial', 'reclamacion_envios'];

    private const FUNCION = 'reclamacion_historial_inmutable';

    private function migracion(string $archivo): object
    {
        return require base_path('database/migrations/'.$archivo.'.php');
    }

    /** @return list<string> */
    private function funciones(): array
    {
        return array_map(fn (object $f): string => (string) $f->proname, DB::select(
            "SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = current_schema() AND p.proname LIKE 'reclamacion%'"
        ));
    }

    /** @return list<object> */
    private function triggers(): array
    {
        return DB::select(<<<'SQL'
            SELECT t.tgname, c.relname, t.tgenabled, t.tgtype, p.proname
            FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace n ON n.oid = c.relnamespace
            JOIN pg_proc p ON p.oid = t.tgfoid
            WHERE NOT t.tgisinternal AND n.nspname = current_schema() AND c.relname LIKE 'reclamacion%'
            SQL);
    }

    /** @return array<string, string> nombre => definición, de los CHECK de una tabla */
    private function checks(string $tabla): array
    {
        $filas = DB::select("SELECT conname, pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE contype = 'c' AND conrelid = ?::regclass", [$tabla]);

        return array_column(array_map(fn (object $f): array => ['n' => $f->conname, 'd' => $f->def], $filas), 'd', 'n');
    }

    // D1 ------------------------------------------------------------------------------------------------------

    public function test_d1_catalogo_tras_migrar(): void
    {
        foreach (self::TABLAS as $tabla) {
            $this->assertNotNull(DB::selectOne('SELECT to_regclass(?) AS r', [$tabla])->r, $tabla);
        }
        $this->assertSame([self::FUNCION], $this->funciones(), 'Una única función del módulo');
        $triggers = $this->triggers();
        $this->assertCount(1, $triggers, 'Un único trigger del módulo');
        $this->assertSame([self::FUNCION, 'reclamacion_historial', 'O', self::FUNCION], [$triggers[0]->tgname, $triggers[0]->relname, $triggers[0]->tgenabled, $triggers[0]->proname]);
        // tgtype: ROW(1) | BEFORE(2) | DELETE(8) | UPDATE(16) = 27; sin INSERT(4) ni TRUNCATE(32).
        $this->assertSame(27, (int) $triggers[0]->tgtype);
        $this->assertStringContainsString('solo insercion', (string) DB::selectOne('SELECT pg_get_functiondef(?::regprocedure) AS d', [self::FUNCION.'()'])->d);

        $nombrados = ['reclamacion_canal_hoja', 'reclamacion_suspension_coherente', 'reclamacion_menor_representante', 'reclamacion_bien_par',
            'reclamacion_reclamo_con_bien', 'reclamacion_monto_no_negativo', 'reclamacion_respuesta_coherente'];
        $checks = $this->checks('reclamaciones');
        foreach ($nombrados as $nombre) {
            $this->assertArrayHasKey($nombre, $checks);
        }
        foreach (['canal', 'estado', 'tipo_documento', 'canal_respuesta', 'tipo', 'bien_tipo'] as $enum) {
            $this->assertArrayHasKey('reclamaciones_'.$enum.'_check', $checks, "enum $enum");
        }
        $checksEnvios = $this->checks('reclamacion_envios');
        foreach (['reclamacion_envio_intentos', 'reclamacion_envio_copia_por_correo', 'reclamacion_envio_propuesta_por_correo', 'reclamacion_envio_enviado_fecha'] as $nombre) {
            $this->assertArrayHasKey($nombre, $checksEnvios);
        }
        $this->assertArrayHasKey('reclamacion_historial_evento_check', $this->checks('reclamacion_historial'));

        $parcial = (string) DB::selectOne("SELECT indexdef FROM pg_indexes WHERE schemaname = current_schema() AND indexname = 'reclamacion_envio_tipo_canal_unico'")->indexdef;
        $this->assertStringContainsString('CREATE UNIQUE INDEX', $parcial);
        $this->assertStringContainsString("'propuesta'", $parcial);
        $this->assertMatchesRegularExpression('/WHERE .*tipo.*<>/', $parcial);
        foreach (['reclamaciones_identificador_unique', 'reclamaciones_numero_unique', 'reclamaciones_token_envio_unique', 'reclamacion_envios_clave_unique'] as $unico) {
            $this->assertNotNull(DB::selectOne('SELECT 1 AS ok FROM pg_indexes WHERE schemaname = current_schema() AND indexname = ?', [$unico]), $unico);
        }

        $fks = DB::select(<<<'SQL'
            SELECT c.conrelid::regclass::text AS tabla, a.attname AS columna, c.confrelid::regclass::text AS destino, c.confdeltype
            FROM pg_constraint c JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1]
            WHERE c.contype = 'f' AND c.conrelid::regclass::text LIKE 'reclamacion%'
            ORDER BY 1, 2
            SQL);
        $mapa = [];
        foreach ($fks as $fk) {
            $mapa[$fk->tabla.'.'.$fk->columna] = $fk->destino.':'.$fk->confdeltype;
        }
        ksort($mapa, SORT_STRING); // orden binario en PHP: no depende de la collation de la base
        $this->assertSame([
            'reclamacion_envios.reclamacion_id' => 'reclamaciones:r',
            'reclamacion_historial.actor_id' => 'users:n',
            'reclamacion_historial.reclamacion_id' => 'reclamaciones:r',
            'reclamaciones.responsable_id' => 'users:n',
        ], $mapa);

        $tipos = [];
        foreach (DB::select("SELECT table_name, column_name, data_type, character_maximum_length, numeric_precision, numeric_scale FROM information_schema.columns WHERE table_schema = current_schema() AND table_name LIKE 'reclamacion%'") as $c) {
            $tipos[$c->table_name.'.'.$c->column_name] = $c;
        }
        $this->assertSame('uuid', $tipos['reclamaciones.identificador']->data_type);
        $this->assertSame('uuid', $tipos['reclamacion_envios.clave']->data_type);
        $this->assertSame(['character', 64], [$tipos['reclamaciones.token_envio']->data_type, (int) $tipos['reclamaciones.token_envio']->character_maximum_length]);
        $this->assertSame(['numeric', 12, 2], [$tipos['reclamaciones.monto_bien']->data_type, (int) $tipos['reclamaciones.monto_bien']->numeric_precision, (int) $tipos['reclamaciones.monto_bien']->numeric_scale]);
        $this->assertSame('timestamp with time zone', $tipos['reclamaciones.recibida_at']->data_type);
        $this->assertSame('date', $tipos['reclamaciones.fecha_limite']->data_type);
        $this->assertSame('json', $tipos['reclamacion_historial.metadatos']->data_type);
        $this->assertSame('boolean', $tipos['reclamaciones.es_menor']->data_type);
        $this->assertSame('smallint', $tipos['reclamacion_contadores.anio']->data_type);
        $this->assertSame('UTC', (string) DB::selectOne("SELECT current_setting('TimeZone') AS v")->v);
    }

    // D2 ------------------------------------------------------------------------------------------------------

    /** @return array<string, array{string, array<string, mixed>}> */
    public static function violacionesCheck(): array
    {
        return [
            'canal_hoja respaldo' => ['reclamacion_canal_hoja', ['canal' => 'respaldo']],
            'canal_hoja web' => ['reclamacion_canal_hoja', ['numero_hoja_respaldo' => 'H-1']],
            'suspension incompleta' => ['reclamacion_suspension_coherente', ['suspension_desde' => '2026-10-26']],
            'suspension invertida' => ['reclamacion_suspension_coherente', ['suspension_desde' => '2026-10-30', 'suspension_hasta' => '2026-10-27']],
            'menor' => ['reclamacion_menor_representante', ['es_menor' => true]],
            'bien par' => ['reclamacion_bien_par', ['tipo' => 'queja', 'bien_tipo' => 'producto', 'bien_descripcion' => null]],
            'reclamo con bien' => ['reclamacion_reclamo_con_bien', ['tipo' => 'reclamo', 'bien_tipo' => null, 'bien_descripcion' => null]],
            'monto' => ['reclamacion_monto_no_negativo', ['monto_bien' => -0.01]],
            'respuesta sin estado' => ['reclamacion_respuesta_coherente', ['respuesta' => 'x', 'respondida_at' => '2026-10-27 10:00:00+00']],
            'respondido sin respuesta' => ['reclamacion_respuesta_coherente', ['estado' => 'respondido']],
            'enum estado' => ['reclamaciones_estado_check', ['estado' => 'en_atencion']],
            'enum tipo' => ['reclamaciones_tipo_check', ['tipo' => 'sugerencia']],
            'enum documento' => ['reclamaciones_tipo_documento_check', ['tipo_documento' => 'libreta']],
        ];
    }

    /** @param array<string, mixed> $extra */
    #[DataProvider('violacionesCheck')]
    public function test_d2_cada_check_rechaza_con_23514_y_su_nombre(string $restriccion, array $extra): void
    {
        $e = $this->assertSqlState('23514', fn () => DB::table('reclamaciones')->insert($this->filaReclamacion($extra)));
        $this->assertStringContainsString('"'.$restriccion.'"', $e->getMessage());
        $this->assertSame(0, DB::table('reclamaciones')->count());
    }

    public function test_d2_checks_de_envios(): void
    {
        $id = DB::table('reclamaciones')->insertGetId($this->filaReclamacion());
        $base = ['reclamacion_id' => $id, 'tipo' => 'respuesta', 'canal' => 'correo', 'estado' => 'pendiente', 'intentos' => 0,
            'asunto' => 'A', 'cuerpo' => 'C', 'created_at' => now(), 'updated_at' => now()];
        foreach ([
            'reclamacion_envio_copia_por_correo' => ['tipo' => 'copia_hoja', 'canal' => 'carta'],
            'reclamacion_envio_propuesta_por_correo' => ['tipo' => 'propuesta', 'canal' => 'carta'],
            'reclamacion_envio_enviado_fecha' => ['estado' => 'enviado'],
            'reclamacion_envios_estado_check' => ['estado' => 'perdido'],
        ] as $restriccion => $extra) {
            $e = $this->assertSqlState('23514', fn () => DB::table('reclamacion_envios')->insert(['clave' => (string) Str::uuid()] + $extra + $base));
            $this->assertStringContainsString('"'.$restriccion.'"', $e->getMessage());
        }
        // intentos es smallint sin signo en el esquema lógico: el CHECK impide negativos.
        $this->assertSqlState('23514', fn () => DB::table('reclamacion_envios')->insert(['clave' => (string) Str::uuid(), 'intentos' => -1] + $base));
        $this->assertSame(0, DB::table('reclamacion_envios')->count());
    }

    // D3 ------------------------------------------------------------------------------------------------------

    public function test_d3_unicidades_e_indice_parcial(): void
    {
        $fila = $this->filaReclamacion();
        $id = DB::table('reclamaciones')->insertGetId($fila);
        foreach (['identificador', 'numero', 'token_envio'] as $columna) {
            $this->assertSqlState('23505', fn () => DB::table('reclamaciones')->insert($this->filaReclamacion([$columna => $fila[$columna]])));
        }
        $envio = fn (string $tipo, string $canal = 'correo', ?string $clave = null) => ['reclamacion_id' => $id, 'tipo' => $tipo, 'canal' => $canal,
            'clave' => $clave ?? (string) Str::uuid(), 'estado' => 'pendiente', 'intentos' => 0, 'asunto' => 'A', 'cuerpo' => 'C', 'created_at' => now(), 'updated_at' => now()];
        DB::table('reclamacion_envios')->insert($envio('propuesta'));
        DB::table('reclamacion_envios')->insert($envio('propuesta'));
        DB::table('reclamacion_envios')->insert($envio('copia_hoja'));
        DB::table('reclamacion_envios')->insert($envio('respuesta', 'correo'));
        DB::table('reclamacion_envios')->insert($envio('respuesta', 'carta'));
        foreach ([$envio('copia_hoja'), $envio('respuesta', 'correo'), $envio('respuesta', 'carta')] as $duplicado) {
            $e = $this->assertSqlState('23505', fn () => DB::table('reclamacion_envios')->insert($duplicado));
            $this->assertStringContainsString('reclamacion_envio_tipo_canal_unico', $e->getMessage());
        }
        $clave = (string) DB::table('reclamacion_envios')->value('clave');
        $this->assertSqlState('23505', fn () => DB::table('reclamacion_envios')->insert($envio('propuesta', 'correo', $clave)));
        $this->assertSame(5, DB::table('reclamacion_envios')->count());
    }

    // D4 / D5 -------------------------------------------------------------------------------------------------

    public function test_d4_historial_inmutable_y_d5_set_null_al_borrar_el_actor(): void
    {
        $actor = User::factory()->create();
        $id = DB::table('reclamaciones')->insertGetId($this->filaReclamacion());
        $hid = DB::table('reclamacion_historial')->insertGetId(['reclamacion_id' => $id, 'evento' => 'nota_interna', 'actor_id' => $actor->id,
            'actor_nombre' => 'Actor', 'metadatos' => '{"a":1}', 'nota' => 'n', 'created_at' => now()]);
        foreach ([
            ['nota' => 'cambiada'], ['actor_nombre' => 'Otro'], ['metadatos' => '{"a":2}'], ['evento' => 'registrada'],
            ['created_at' => now()->addDay()], ['actor_id' => null, 'nota' => 'x'], ['actor_id' => User::factory()->create()->id],
        ] as $cambio) {
            $e = $this->assertSqlState('P0001', fn () => DB::table('reclamacion_historial')->where('id', $hid)->update($cambio));
            $this->assertStringContainsString('solo insercion', $e->getMessage());
        }
        $this->assertSqlState('P0001', fn () => DB::table('reclamacion_historial')->where('id', $hid)->delete());

        // D5: ON DELETE SET NULL es la única modificación admitida; el resto de la fila queda intacta.
        $actor->delete();
        $fila = DB::table('reclamacion_historial')->where('id', $hid)->first();
        $this->assertNull($fila->actor_id);
        $this->assertSame(['Actor', 'n', '{"a":1}', 'nota_interna'], [$fila->actor_nombre, $fila->nota, $fila->metadatos, $fila->evento]);
        // Ya nulo: tampoco se puede volver a asignar un actor.
        $this->assertSqlState('P0001', fn () => DB::table('reclamacion_historial')->where('id', $hid)->update(['actor_id' => User::factory()->create()->id]));
    }

    public function test_d5_set_null_del_responsable_conserva_la_reclamacion(): void
    {
        $responsable = User::factory()->create();
        $id = DB::table('reclamaciones')->insertGetId($this->filaReclamacion(['responsable_id' => $responsable->id]));
        $responsable->delete();
        $this->assertNull(DB::table('reclamaciones')->where('id', $id)->value('responsable_id'));
    }

    // D6 ------------------------------------------------------------------------------------------------------

    public function test_d6_restrict_impide_borrar_reclamaciones_con_evidencia(): void
    {
        $conHistorial = DB::table('reclamaciones')->insertGetId($this->filaReclamacion());
        DB::table('reclamacion_historial')->insert(['reclamacion_id' => $conHistorial, 'evento' => 'registrada', 'actor_nombre' => 'Sistema', 'metadatos' => '{}', 'created_at' => now()]);
        $conEnvio = DB::table('reclamaciones')->insertGetId($this->filaReclamacion());
        DB::table('reclamacion_envios')->insert(['reclamacion_id' => $conEnvio, 'tipo' => 'copia_hoja', 'canal' => 'correo', 'clave' => (string) Str::uuid(),
            'estado' => 'pendiente', 'intentos' => 0, 'asunto' => 'A', 'cuerpo' => 'C', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$conHistorial, $conEnvio] as $id) {
            $this->assertSqlState('23001', fn () => DB::table('reclamaciones')->where('id', $id)->delete());
        }
        $this->assertSame(2, DB::table('reclamaciones')->count());
    }

    // D7 ------------------------------------------------------------------------------------------------------

    public function test_referencia_inexistente_conserva_sqlstate_foreign_key_violation(): void
    {
        $id = DB::table('reclamaciones')->insertGetId($this->filaReclamacion());
        DB::table('reclamaciones')->where('id', $id)->delete();
        $this->assertSqlState('23503', fn () => DB::table('reclamacion_historial')->insert([
            'reclamacion_id' => $id, 'evento' => 'registrada', 'actor_nombre' => 'Sistema',
            'metadatos' => '{}', 'created_at' => now(),
        ]));
        $this->assertSame(0, DB::table('reclamacion_historial')->where('reclamacion_id', $id)->count());
    }

    public function test_d7_uuid_malformado_en_sql_crudo_es_22p02_y_el_servicio_no_lo_envia(): void
    {
        $fila = $this->filaReclamacion();
        DB::table('reclamaciones')->insert($fila);
        // Diferencia de motor documentada (B3): PostgreSQL rechaza el literal; SQLite devolvería 0 filas.
        $this->assertSqlState('22P02', fn () => DB::table('reclamaciones')->where('identificador', 'no-es-uuid')->count());
        $this->assertSame(1, DB::table('reclamaciones')->where('identificador', strtoupper($fila['identificador']))->count(), 'PostgreSQL acepta mayúsculas');

        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $servicio = app(GestionReclamaciones::class);
        foreach (['no-es-uuid', '', str_repeat('f', 37), (string) Str::uuid()] as $identificador) {
            try {
                $servicio->consultar($admin, $identificador);
                $this->fail('Debió responder no encontrada');
            } catch (ModelNotFoundException $e) {
                $this->assertSame(Reclamacion::class, $e->getModel());
            }
        }
        // La transacción de la prueba sigue utilizable (no hubo 22P02 dentro del servicio).
        $this->assertSame($fila['identificador'], $servicio->consultar($admin, strtoupper($fila['identificador']))->identificador);
    }

    // D8 ------------------------------------------------------------------------------------------------------

    public function test_d8_down_no_deja_residuos_y_up_reaplica_la_proteccion(): void
    {
        $migracion = $this->migracion('2026_10_30_000030_create_reclamaciones_tables');
        $migracion->down();
        foreach (self::TABLAS as $tabla) {
            $this->assertNull(DB::selectOne('SELECT to_regclass(?) AS r', [$tabla])->r, $tabla);
        }
        $this->assertSame([], $this->funciones(), 'down() elimina la función');
        $this->assertSame([], $this->triggers());
        $this->assertNull(DB::selectOne("SELECT 1 AS ok FROM pg_indexes WHERE indexname = 'reclamacion_envio_tipo_canal_unico'"));

        $migracion->up();
        $this->assertSame([self::FUNCION], $this->funciones());
        $this->assertCount(1, $this->triggers());
        $id = DB::table('reclamaciones')->insertGetId($this->filaReclamacion());
        $hid = DB::table('reclamacion_historial')->insertGetId(['reclamacion_id' => $id, 'evento' => 'registrada', 'actor_nombre' => 'Sistema', 'metadatos' => '{}', 'created_at' => now()]);
        $this->assertSqlState('P0001', fn () => DB::table('reclamacion_historial')->where('id', $hid)->delete());
        $this->assertSqlState('23514', fn () => DB::table('reclamaciones')->insert($this->filaReclamacion(['monto_bien' => -1])));
    }

    // D9 está bloqueada en ReclamacionesReconstruccionD9Test, sin RefreshDatabase ni ejecutor destructivo.

    // D10 -----------------------------------------------------------------------------------------------------

    public function test_d10_permisos_idempotentes_sin_resincronizar_y_down_conservador(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $coordinador = Role::findByName('coordinador', 'web');
        $coordinador->givePermissionTo('reclamaciones.ver');
        $antes = $coordinador->permissions()->pluck('name')->sort()->values()->all();
        $ids = Permission::query()->where('name', 'like', 'reclamaciones.%')->orderBy('name')->pluck('id')->all();

        $migracion = $this->migracion('2026_10_30_000031_add_reclamaciones_permissions');
        $migracion->up();
        $migracion->up();
        $migracion->down();
        $migracion->up();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertSame($ids, Permission::query()->where('name', 'like', 'reclamaciones.%')->orderBy('name')->pluck('id')->all(), 'Sin duplicados ni recreaciones');
        $this->assertSame($antes, Role::findByName('coordinador', 'web')->permissions()->pluck('name')->sort()->values()->all());
        $this->assertSame(3, DB::table('role_has_permissions')->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('role_has_permissions.role_id', Role::findByName('admin', 'web')->id)->where('permissions.name', 'like', 'reclamaciones.%')->count());
    }

    // D11 -----------------------------------------------------------------------------------------------------

    public function test_d11_truncate_no_esta_cubierto_por_el_trigger(): void
    {
        // Límite documentado (B6): el trigger es de fila y no cubre TRUNCATE; el propietario de la tabla puede
        // vaciarla. Se ejecuta en un savepoint y se revierte; la prueba deja constancia del límite, no lo aprueba.
        $id = DB::table('reclamaciones')->insertGetId($this->filaReclamacion());
        DB::table('reclamacion_historial')->insert(['reclamacion_id' => $id, 'evento' => 'registrada', 'actor_nombre' => 'Sistema', 'metadatos' => '{}', 'created_at' => now()]);
        $this->assertTrue((bool) DB::selectOne("SELECT has_table_privilege(current_user, 'reclamacion_historial', 'TRUNCATE') AS p")->p);
        DB::beginTransaction();
        try {
            DB::statement('TRUNCATE reclamacion_historial');
            $this->assertSame(0, DB::table('reclamacion_historial')->count());
        } finally {
            DB::rollBack();
        }
        $this->assertSame(1, DB::table('reclamacion_historial')->count());
        $this->assertSame([], array_filter($this->triggers(), fn (object $t) => ((int) $t->tgtype & 32) === 32), 'No hay trigger de TRUNCATE');
    }
}
