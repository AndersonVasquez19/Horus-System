<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Migraciones 000030/000031: pruebas comunes a SQLite (suite habitual) y PostgreSQL (perfil aislado).
 *
 * Portabilidad: cada sentencia que debe fallar se ejecuta en su propio SAVEPOINT (DB::transaction anidada
 * dentro de la transacción de RefreshDatabase), porque en PostgreSQL un error deja la transacción abortada
 * (25P02) y la siguiente sentencia fallaría por esa causa. Los duplicados se comprueban por la excepción de
 * Laravel (UniqueConstraintViolationException), no por el texto del motor; en PostgreSQL se exige además el
 * SQLSTATE. Las comprobaciones exclusivas de PostgreSQL (catálogo detallado, reconstrucciones repetidas,
 * concurrencia) están en tests/PostgresIntegrity.
 */
class ReclamacionesMigracionTest extends TestCase
{
    use RefreshDatabase;

    private const TABLAS = ['reclamaciones', 'reclamacion_contadores', 'reclamacion_historial', 'reclamacion_envios'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
    }

    private function migracion(string $archivo): object
    {
        return require base_path('database/migrations/'.$archivo.'.php');
    }

    /** @return array<string, mixed> */
    private function fila(array $extra = []): array
    {
        $uuid = (string) Str::uuid();

        return $extra + [
            'identificador' => $uuid, 'numero' => substr(str_replace('-', '', $uuid), 0, 9).'-2026', 'token_envio' => hash('sha256', $uuid),
            'canal' => 'web', 'estado' => 'abierto', 'recibida_at' => now(), 'fecha_limite' => '2026-11-16',
            'nombres' => 'Persona', 'apellidos' => 'Ficticia', 'tipo_documento' => 'dni', 'numero_documento' => '12345678',
            'domicilio' => 'Calle Ficticia 1', 'telefono' => '900000000', 'email' => 'f@example.test', 'canal_respuesta' => 'correo',
            'es_menor' => false, 'tipo' => 'reclamo', 'bien_tipo' => 'servicio', 'bien_descripcion' => 'Curso', 'monto_bien' => 10,
            'detalle' => 'Detalle', 'pedido' => 'Pedido', 'privacidad_version' => 'v1', 'informado_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    /** Ejecuta en un savepoint una sentencia que la base debe rechazar y devuelve la excepción. */
    private function rechaza(callable $sentencia, ?string $sqlstatePostgres = null): QueryException
    {
        try {
            DB::transaction(fn () => $sentencia());
        } catch (QueryException $e) {
            if ($sqlstatePostgres !== null && DB::getDriverName() === 'pgsql') {
                $this->assertSame($sqlstatePostgres, $e->errorInfo[0] ?? null, 'SQLSTATE inesperado');
            }

            return $e;
        }
        $this->fail('La base debió rechazar la sentencia.');
    }

    /**
     * Triggers, índices propios y funciones del módulo según el motor (solo lectura del catálogo).
     *
     * @return list<string>
     */
    private function objetosDelModulo(): array
    {
        $tablas = "'".implode("', '", self::TABLAS)."'";
        if (DB::getDriverName() === 'pgsql') {
            $filas = DB::select(<<<SQL
                SELECT t.tgname AS nombre FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
                    JOIN pg_namespace n ON n.oid = c.relnamespace
                    WHERE NOT t.tgisinternal AND n.nspname = current_schema() AND c.relname IN ($tablas)
                UNION ALL
                SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND indexname = 'reclamacion_envio_tipo_canal_unico'
                UNION ALL
                SELECT p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
                    WHERE n.nspname = current_schema() AND p.proname LIKE 'reclamacion%'
                SQL);
        } else {
            $filas = DB::select("SELECT name AS nombre FROM sqlite_master WHERE (type = 'trigger' AND tbl_name IN ($tablas)) OR (type = 'index' AND name = 'reclamacion_envio_tipo_canal_unico')");
        }

        return array_map(fn (object $o): string => (string) $o->nombre, $filas);
    }

    public function test_crea_exactamente_las_cuatro_tablas_previstas(): void
    {
        foreach (self::TABLAS as $tabla) {
            $this->assertTrue(Schema::hasTable($tabla), $tabla);
        }
        $columnas = Schema::getColumnListing('reclamaciones');
        foreach (['fecha_incidente', 'area', 'revision', 'acepta_comunicaciones', 'respondida_por', 'envio_clave_hash'] as $descartada) {
            $this->assertNotContains($descartada, $columnas);
        }
        $this->assertSame(['id', 'reclamacion_id', 'evento', 'actor_id', 'actor_nombre', 'metadatos', 'nota', 'created_at'], Schema::getColumnListing('reclamacion_historial'));
        $this->assertNotEmpty($this->objetosDelModulo(), 'Triggers/índice/función del módulo presentes tras migrar');
    }

    public function test_rollback_elimina_tablas_triggers_indices_y_funcion_y_se_puede_reaplicar(): void
    {
        $antes = $this->objetosDelModulo();
        sort($antes);
        $migracion = $this->migracion('2026_10_30_000030_create_reclamaciones_tables');
        $migracion->down();
        foreach (self::TABLAS as $tabla) {
            $this->assertFalse(Schema::hasTable($tabla), $tabla);
        }
        $this->assertSame([], $this->objetosDelModulo(), 'Sin objetos residuales tras down()');

        $migracion->up();
        foreach (self::TABLAS as $tabla) {
            $this->assertTrue(Schema::hasTable($tabla), $tabla);
        }
        $despues = $this->objetosDelModulo();
        sort($despues);
        $this->assertSame($antes, $despues, 'La reaplicación recrea exactamente los mismos objetos');
        DB::table('reclamaciones')->insert($this->fila());
        $this->assertSame(1, DB::table('reclamaciones')->count());
        // La inmutabilidad vuelve a estar activa tras reaplicar.
        $id = (int) DB::table('reclamaciones')->value('id');
        $hid = DB::table('reclamacion_historial')->insertGetId(['reclamacion_id' => $id, 'evento' => 'registrada', 'actor_nombre' => 'Sistema', 'metadatos' => '{}', 'created_at' => now()]);
        $this->rechaza(fn () => DB::table('reclamacion_historial')->where('id', $hid)->delete(), 'P0001');
    }

    public function test_restricciones_de_unicidad(): void
    {
        $fila = $this->fila();
        DB::table('reclamaciones')->insert($fila);
        foreach (['identificador', 'numero', 'token_envio'] as $columna) {
            $e = $this->rechaza(fn () => DB::table('reclamaciones')->insert($this->fila([$columna => $fila[$columna]])), '23505');
            $this->assertInstanceOf(UniqueConstraintViolationException::class, $e, $columna);
        }
        $this->assertSame(1, DB::table('reclamaciones')->count());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function filasInvalidas(): array
    {
        return [
            'reclamo sin bien' => [['tipo' => 'reclamo', 'bien_tipo' => null, 'bien_descripcion' => null]],
            'bien sin descripcion' => [['tipo' => 'queja', 'bien_tipo' => 'producto', 'bien_descripcion' => null]],
            'monto negativo' => [['monto_bien' => -1]],
            'menor sin representante' => [['es_menor' => true]],
            'suspension incompleta' => [['suspension_desde' => '2026-10-26']],
            'suspension invertida' => [['suspension_desde' => '2026-10-30', 'suspension_hasta' => '2026-10-27']],
            'respuesta sin estado respondido' => [['respuesta' => 'x', 'respondida_at' => '2026-10-27 10:00:00']],
            'respondido sin respuesta' => [['estado' => 'respondido']],
            'respaldo sin hoja' => [['canal' => 'respaldo']],
            'web con hoja' => [['numero_hoja_respaldo' => 'H-1']],
            'estado desconocido' => [['estado' => 'en_atencion']],
            'tipo desconocido' => [['tipo' => 'sugerencia']],
        ];
    }

    /** @param array<string, mixed> $extra */
    #[DataProvider('filasInvalidas')]
    public function test_la_base_rechaza_filas_incoherentes(array $extra): void
    {
        $this->rechaza(fn () => DB::table('reclamaciones')->insert($this->fila($extra)), '23514');
        $this->assertSame(0, DB::table('reclamaciones')->count(), 'La transacción de la prueba sigue utilizable tras el rechazo');
    }

    public function test_acepta_filas_coherentes_incluida_la_queja_sin_bien_y_el_monto_nulo(): void
    {
        DB::table('reclamaciones')->insert($this->fila(['tipo' => 'queja', 'bien_tipo' => null, 'bien_descripcion' => null, 'monto_bien' => null]));
        DB::table('reclamaciones')->insert($this->fila(['monto_bien' => null]));
        DB::table('reclamaciones')->insert($this->fila(['es_menor' => true, 'representante_nombre' => 'R', 'representante_domicilio' => 'D', 'representante_telefono' => '1', 'representante_email' => 'r@example.test']));
        DB::table('reclamaciones')->insert($this->fila(['estado' => 'respondido', 'respuesta' => 'Texto', 'respondida_at' => '2026-10-27 10:00:00']));
        $this->assertSame(4, DB::table('reclamaciones')->count());
    }

    public function test_historial_es_de_solo_insercion_y_conserva_la_autoria_al_borrar_la_cuenta(): void
    {
        $actor = User::factory()->create();
        $id = DB::table('reclamaciones')->insertGetId($this->fila());
        $historial = ['reclamacion_id' => $id, 'evento' => 'nota_interna', 'actor_id' => $actor->id, 'actor_nombre' => 'Actor', 'metadatos' => '{}', 'nota' => 'n', 'created_at' => now()];
        $hid = DB::table('reclamacion_historial')->insertGetId($historial);

        foreach ([fn () => DB::table('reclamacion_historial')->where('id', $hid)->update(['nota' => 'cambiada']),
            fn () => DB::table('reclamacion_historial')->where('id', $hid)->update(['actor_nombre' => 'Otro']),
            fn () => DB::table('reclamacion_historial')->where('id', $hid)->update(['actor_id' => null, 'nota' => 'x']),
            fn () => DB::table('reclamacion_historial')->where('id', $hid)->delete()] as $intento) {
            $e = $this->rechaza($intento, 'P0001');
            $this->assertStringContainsString('solo insercion', $e->getMessage());
        }

        // ON DELETE SET NULL de la FK sí está permitido: la autoría sigue en actor_nombre.
        $actor->delete();
        $fila = DB::table('reclamacion_historial')->where('id', $hid)->first();
        $this->assertNull($fila->actor_id);
        $this->assertSame('Actor', $fila->actor_nombre);
        $this->assertSame('n', $fila->nota);
    }

    public function test_las_fk_son_restrict_y_no_se_borra_la_reclamacion_con_evidencia(): void
    {
        $id = DB::table('reclamaciones')->insertGetId($this->fila());
        DB::table('reclamacion_historial')->insert(['reclamacion_id' => $id, 'evento' => 'registrada', 'actor_nombre' => 'Sistema', 'metadatos' => '{}', 'created_at' => now()]);
        // PostgreSQL 18 distingue RESTRICT (23001) de una referencia inexistente (23503).
        $this->rechaza(fn () => DB::table('reclamaciones')->where('id', $id)->delete(), '23001');
        $this->assertSame(1, DB::table('reclamaciones')->count());
    }

    public function test_indice_parcial_de_envios_permite_propuestas_sucesivas_pero_no_duplicar_copia_ni_respuesta(): void
    {
        $id = DB::table('reclamaciones')->insertGetId($this->fila());
        $envio = fn (string $tipo, string $canal = 'correo') => ['reclamacion_id' => $id, 'tipo' => $tipo, 'canal' => $canal, 'clave' => (string) Str::uuid(),
            'estado' => 'pendiente', 'intentos' => 0, 'asunto' => 'A', 'cuerpo' => 'C', 'created_at' => now(), 'updated_at' => now()];
        DB::table('reclamacion_envios')->insert($envio('propuesta'));
        DB::table('reclamacion_envios')->insert($envio('propuesta'));
        DB::table('reclamacion_envios')->insert($envio('copia_hoja'));
        DB::table('reclamacion_envios')->insert($envio('respuesta', 'correo'));
        DB::table('reclamacion_envios')->insert($envio('respuesta', 'carta'));
        $this->assertSame(5, DB::table('reclamacion_envios')->count());
        foreach ([$envio('copia_hoja'), $envio('respuesta', 'correo')] as $duplicado) {
            $e = $this->rechaza(fn () => DB::table('reclamacion_envios')->insert($duplicado), '23505');
            $this->assertInstanceOf(UniqueConstraintViolationException::class, $e);
        }
        $this->assertSame(5, DB::table('reclamacion_envios')->count());
    }

    public function test_envios_rechaza_copia_por_carta_e_intentos_negativos(): void
    {
        $id = DB::table('reclamaciones')->insertGetId($this->fila());
        $base = ['reclamacion_id' => $id, 'tipo' => 'copia_hoja', 'canal' => 'carta', 'clave' => (string) Str::uuid(), 'estado' => 'pendiente',
            'intentos' => 0, 'asunto' => 'A', 'cuerpo' => 'C', 'created_at' => now(), 'updated_at' => now()];
        $this->rechaza(fn () => DB::table('reclamacion_envios')->insert($base), '23514');
        $this->rechaza(fn () => DB::table('reclamacion_envios')->insert(['canal' => 'correo', 'estado' => 'enviado', 'clave' => (string) Str::uuid()] + $base), '23514');
        $this->assertSame(0, DB::table('reclamacion_envios')->count());
    }

    public function test_permisos_se_crean_por_migracion_solo_para_admin_sin_resincronizar(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $coordinador = Role::findByName('coordinador', 'web');
        $coordinador->givePermissionTo(Permission::findOrCreate('permiso.manual.sintetico', 'web'));
        $antes = $coordinador->permissions()->pluck('name')->sort()->values()->all();

        Permission::query()->whereIn('name', RolesAndPermissionsSeeder::RECLAMACIONES_PERMISSIONS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertSame(0, Permission::query()->where('name', 'like', 'reclamaciones.%')->count());

        $migracion = $this->migracion('2026_10_30_000031_add_reclamaciones_permissions');
        $migracion->up();
        $migracion->up(); // idempotente

        $this->assertSame(['reclamaciones.gestionar', 'reclamaciones.responder', 'reclamaciones.ver'],
            Permission::query()->where('name', 'like', 'reclamaciones.%')->orderBy('name')->pluck('name')->all());
        $this->assertSame(RolesAndPermissionsSeeder::RECLAMACIONES_PERMISSIONS, array_values(array_intersect(RolesAndPermissionsSeeder::RECLAMACIONES_PERMISSIONS, Role::findByName('admin', 'web')->permissions()->pluck('name')->all())));
        $this->assertSame($antes, Role::findByName('coordinador', 'web')->permissions()->pluck('name')->sort()->values()->all(), 'La migración no debe tocar otros roles');
        foreach (['coordinador', 'secretaria'] as $rol) {
            $this->assertEmpty(array_filter(Role::findByName($rol, 'web')->permissions()->pluck('name')->all(), fn ($p) => str_starts_with($p, 'reclamaciones.')));
        }
        $migracion->down(); // no-op documentado
        $this->assertSame(3, Permission::query()->where('name', 'like', 'reclamaciones.%')->count());
    }

    public function test_down_de_000031_conserva_permisos_y_concesiones_hechas_despues_por_el_propietario(): void
    {
        // Decisión de diseño: el down() no borra. Un permiso concedido a mano a otro rol (decisión C1 del
        // propietario) o usado por otro módulo no debe revocarse en silencio al revertir el lote.
        $this->seed(RolesAndPermissionsSeeder::class);
        $migracion = $this->migracion('2026_10_30_000031_add_reclamaciones_permissions');
        $coordinador = Role::findByName('coordinador', 'web');
        $coordinador->givePermissionTo('reclamaciones.ver');
        $ids = Permission::query()->where('name', 'like', 'reclamaciones.%')->orderBy('name')->pluck('id')->all();

        $migracion->down();
        $migracion->up(); // reaplicación tras el rollback: mismos permisos, sin duplicados

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertSame($ids, Permission::query()->where('name', 'like', 'reclamaciones.%')->orderBy('name')->pluck('id')->all());
        $this->assertTrue(Role::findByName('coordinador', 'web')->hasPermissionTo('reclamaciones.ver', 'web'));
        $this->assertFalse(Role::findByName('coordinador', 'web')->hasPermissionTo('reclamaciones.gestionar', 'web'));
    }

    public function test_el_seeder_concede_los_permisos_solo_a_admin(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->assertSame(3, count(array_intersect(RolesAndPermissionsSeeder::RECLAMACIONES_PERMISSIONS, Role::findByName('admin', 'web')->permissions()->pluck('name')->all())));
        $this->assertSame([], array_values(array_intersect(RolesAndPermissionsSeeder::RECLAMACIONES_PERMISSIONS, [...RolesAndPermissionsSeeder::COORDINADOR_PERMISSIONS, ...RolesAndPermissionsSeeder::SECRETARIA_PERMISSIONS])));
    }
}
