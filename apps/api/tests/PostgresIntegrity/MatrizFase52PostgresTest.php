<?php

declare(strict_types=1);

namespace Tests\PostgresIntegrity;

use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\PostgresIntegrity\Concerns\RequiresIsolatedPostgres;
use Tests\TestCase;

/**
 * Fase 5.2 — huecos de la matriz PostgreSQL que no cubrían las pruebas anteriores: idempotencia y unicidades de
 * cotizaciones, historial sin huecos, unicidades de estudiantes/cursos/servicios bajo concurrencia, límites numéricos,
 * CHECK administrativos (000041), índices de la 000037, tabla de la 000038, RESTRICT / SET NULL y rollback.
 *
 * Datos ficticios y solo la base desechable del perfil aislado (127.0.0.1:55433, horus_l2_test).
 * Protocolo de concurrencia (igual que MatriculaOperacionesConcurrenciaPostgresTest, sin hilos ni procesos): la sesión B
 * abre una transacción y escribe sin confirmar; la sesión principal, con lock_timeout corto, debe quedar BLOQUEADA
 * (55P03) porque la comprobación de unicidad espera a la transacción de B; al confirmar B, la misma escritura termina
 * en 23505 con el nombre de la restricción.
 */
class MatrizFase52PostgresTest extends TestCase
{
    use DatabaseTruncation;
    use RequiresIsolatedPostgres;

    private const LOCK_NOT_AVAILABLE = '55P03';

    private const UNIQUE_VIOLATION = '23505';

    private const CHECK_VIOLATION = '23514';

    private const NUMERIC_OUT_OF_RANGE = '22003';

    /** ON DELETE RESTRICT se informa como restrict_violation en PostgreSQL 18.6. */
    private const RESTRICT_VIOLATION = '23001';

    // Utilidades ----------------------------------------------------------------------------------------------

    private function ahora(): string
    {
        return now()->toDateTimeString();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function filaCotizacion(array $extra = []): array
    {
        return $extra + [
            'identificador' => (string) Str::uuid(),
            'codigo' => 'COT-'.Str::upper(Str::random(10)),
            'recibida_at' => $this->ahora(),
            'necesidad_cliente' => 'Necesidad ficticia.',
            'contacto_nombre' => 'Contacto Ficticio',
            'contacto_email' => 'contacto@ficticio.test',
            'estado' => 'recibida',
            'revision' => 1,
            'creador_ambito' => 1,
            'creacion_clave_hash' => hash('sha256', 'clave-'.Str::random(8)),
            'creacion_huella' => hash('sha256', 'huella'),
            'origen' => 'administrativo',
            'created_at' => $this->ahora(),
            'updated_at' => $this->ahora(),
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function filaHistorial(int $solicitudId, int $revision, array $extra = []): array
    {
        return $extra + [
            'solicitud_id' => $solicitudId,
            'revision' => $revision,
            'evento' => 'seguimiento_registrado',
            'metadatos' => '{}',
            'actor_ambito' => 1,
            'clave_hash' => null,
            'huella' => null,
            'created_at' => $this->ahora(),
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function filaServicio(array $extra = []): array
    {
        return $extra + [
            'identificador_publico' => (string) Str::uuid(),
            'codigo' => 'SRV-'.Str::upper(Str::random(8)),
            'nombre' => 'Servicio ficticio',
            'estado' => 'inactivo',
            'revision' => 1,
            'created_at' => $this->ahora(),
            'updated_at' => $this->ahora(),
        ];
    }

    private function assertBlocked(callable $escritura): void
    {
        try {
            $escritura();
            $this->fail('La escritura debía quedar bloqueada por la transacción de la otra sesión.');
        } catch (QueryException $e) {
            $this->assertSame(self::LOCK_NOT_AVAILABLE, $e->getCode(), 'Debe esperar el bloqueo (55P03), no fallar por otra causa.');
        }
    }

    private function assertSqlState(string $esperado, callable $escritura, ?string $restriccion = null): void
    {
        try {
            $escritura();
            $this->fail("Se esperaba SQLSTATE {$esperado}.");
        } catch (QueryException $e) {
            $this->assertSame($esperado, $e->getCode(), $e->getMessage());
            if ($restriccion !== null) {
                $this->assertStringContainsString($restriccion, $e->getMessage());
            }
        }
    }

    /**
     * Sesión B escribe sin confirmar; la principal queda bloqueada; al confirmar B, la misma fila acaba en 23505.
     *
     * @param  callable(Connection): mixed  $escribe  Inserta la fila conflictiva en la conexión indicada.
     */
    private function carreraDeUnicidad(callable $escribe, string $restriccion): void
    {
        $b = $this->secondSession();
        $b->beginTransaction();
        $escribe($b);

        $this->limitLockWait();
        $this->assertBlocked(fn () => $escribe(DB::connection()));

        $b->commit();

        $this->assertSqlState(self::UNIQUE_VIOLATION, fn () => $escribe(DB::connection()), $restriccion);
    }

    // Cotizaciones: idempotencia e historial ---------------------------------------------------------------------

    public function test_cotizacion_con_la_misma_clave_de_creacion_espera_y_termina_en_23505(): void
    {
        $clave = hash('sha256', 'clave-compartida');

        $this->carreraDeUnicidad(function (Connection $c) use ($clave): void {
            // Mismo (creador_ambito, creacion_clave_hash); código e identificador distintos en cada intento (por defecto).
            $c->table('solicitudes_cotizacion')->insert($this->filaCotizacion(['creacion_clave_hash' => $clave]));
        }, 'cotizaciones_creacion_unica');

        $this->assertSame(1, DB::table('solicitudes_cotizacion')->count(), 'Un solo expediente por clave de idempotencia.');
    }

    public function test_misma_clave_con_otro_ambito_si_se_admite(): void
    {
        $clave = hash('sha256', 'clave-compartida');
        DB::table('solicitudes_cotizacion')->insert($this->filaCotizacion(['creacion_clave_hash' => $clave, 'creador_ambito' => 1]));
        DB::table('solicitudes_cotizacion')->insert($this->filaCotizacion(['creacion_clave_hash' => $clave, 'creador_ambito' => 2]));

        $this->assertSame(2, DB::table('solicitudes_cotizacion')->count());
    }

    public function test_seguimiento_con_la_misma_clave_espera_y_termina_en_23505(): void
    {
        $id = (int) DB::table('solicitudes_cotizacion')->insertGetId($this->filaCotizacion());
        $clave = hash('sha256', 'seguimiento-1');
        $revision = 1;

        $this->carreraDeUnicidad(function (Connection $c) use ($id, $clave, &$revision): void {
            // Revisión distinta cada vez: solo choca la identidad del seguimiento (solicitud, ámbito, clave).
            $c->table('cotizacion_historial')->insert($this->filaHistorial($id, ++$revision, ['clave_hash' => $clave]));
        }, 'cotizacion_seguimiento_unico');

        $this->assertSame(1, DB::table('cotizacion_historial')->where('solicitud_id', $id)->count());
    }

    public function test_historial_sin_huecos_ni_repetidas_bajo_concurrencia(): void
    {
        $id = (int) DB::table('solicitudes_cotizacion')->insertGetId($this->filaCotizacion());
        DB::table('cotizacion_historial')->insert($this->filaHistorial($id, 1, ['evento' => 'creada']));

        $b = $this->secondSession();
        $b->beginTransaction();
        $b->table('cotizacion_historial')->insert($this->filaHistorial($id, 2));

        $this->limitLockWait();
        $this->assertBlocked(fn () => DB::table('cotizacion_historial')->insert($this->filaHistorial($id, 2)));

        $b->commit();

        $this->assertSqlState(self::UNIQUE_VIOLATION, fn () => DB::table('cotizacion_historial')->insert($this->filaHistorial($id, 2)), 'cotizacion_historial_revision_unica');

        DB::table('cotizacion_historial')->insert($this->filaHistorial($id, 3));
        $this->assertSame([1, 2, 3], DB::table('cotizacion_historial')->where('solicitud_id', $id)->orderBy('revision')->pluck('revision')->map(fn ($r) => (int) $r)->all());
    }

    public function test_eventos_sin_clave_no_chocan_entre_si(): void
    {
        $id = (int) DB::table('solicitudes_cotizacion')->insertGetId($this->filaCotizacion());
        foreach ([1, 2, 3] as $revision) {
            DB::table('cotizacion_historial')->insert($this->filaHistorial($id, $revision, ['clave_hash' => null]));
        }

        $this->assertSame(3, DB::table('cotizacion_historial')->where('solicitud_id', $id)->count(), 'NULL no participa en cotizacion_seguimiento_unico.');
    }

    // Unicidades bajo concurrencia -------------------------------------------------------------------------------

    public function test_dni_de_estudiante_unico_bajo_concurrencia(): void
    {
        $this->carreraDeUnicidad(function (Connection $c): void {
            $c->table('estudiantes')->insert([
                'dni' => '44556677', 'nombres' => 'Ficticio', 'apellidos' => 'Concurrente', 'estado' => 'activo',
                'created_at' => $this->ahora(), 'updated_at' => $this->ahora(),
            ]);
        }, 'estudiantes_dni_unique');

        $this->assertSame(1, DB::table('estudiantes')->where('dni', '44556677')->count());
    }

    public function test_codigo_de_curso_unico_bajo_concurrencia(): void
    {
        $this->carreraDeUnicidad(function (Connection $c): void {
            $c->table('cursos')->insert([
                'codigo' => 'PG52-CURSO', 'nombre' => 'Curso ficticio', 'descripcion' => 'Ficticio', 'categoria' => 'tecnologia',
                'duracion_horas' => 10, 'precio' => 100, 'modalidad' => 'virtual', 'horario' => 'Lunes',
                'cupos_maximos' => 5, 'cupos_disponibles' => 5, 'estado' => 'activo',
                'created_at' => $this->ahora(), 'updated_at' => $this->ahora(),
            ]);
        }, 'cursos_codigo_unique');

        $this->assertSame(1, DB::table('cursos')->where('codigo', 'PG52-CURSO')->count());
    }

    public function test_codigo_de_servicio_unico_bajo_concurrencia(): void
    {
        $this->carreraDeUnicidad(function (Connection $c): void {
            $c->table('servicios_tecnologicos')->insert($this->filaServicio(['codigo' => 'PG52-SERVICIO']));
        }, 'servicios_tecnologicos_codigo_unique');

        $this->assertSame(1, DB::table('servicios_tecnologicos')->where('codigo', 'PG52-SERVICIO')->count());
    }

    // Límites numéricos y CHECK ----------------------------------------------------------------------------------

    public function test_limites_numericos_de_cursos_rechazados_por_la_base(): void
    {
        $curso = $this->makeCourse('PG52-LIM', 5);

        // Los valores máximos válidos pasan.
        DB::table('cursos')->where('id', $curso->id)->update(['precio' => '99999999.99', 'duracion_horas' => 2147483647, 'cupos_maximos' => 2147483647]);
        $this->assertSame('99999999.99', (string) DB::table('cursos')->where('id', $curso->id)->value('precio'));

        // Un paso más allá: error numérico de PostgreSQL, nunca un valor truncado.
        $this->assertSqlState(self::NUMERIC_OUT_OF_RANGE, fn () => DB::table('cursos')->where('id', $curso->id)->update(['precio' => '100000000.00']));
        $this->assertSqlState(self::NUMERIC_OUT_OF_RANGE, fn () => DB::table('cursos')->where('id', $curso->id)->update(['duracion_horas' => 2147483648]));
        $this->assertSqlState(self::NUMERIC_OUT_OF_RANGE, fn () => DB::table('cursos')->where('id', $curso->id)->update(['cupos_maximos' => 2147483648]));
    }

    public function test_check_de_estados_administrativos_de_la_000041(): void
    {
        $fila = ['titulo' => 'Solicitud ficticia', 'tipo' => 'solicitud', 'created_at' => $this->ahora(), 'updated_at' => $this->ahora()];

        foreach (['pendiente', 'aprobado', 'rechazado'] as $estado) {
            DB::table('aprobaciones_internas')->insert($fila + ['estado' => $estado]);
        }
        $this->assertSqlState(self::CHECK_VIOLATION, fn () => DB::table('aprobaciones_internas')->insert($fila + ['estado' => 'inventado']), 'aprobaciones_internas_estado_valido');

        foreach (['aprobaciones_internas_estado_valido', 'matricula_notifications_status_valido'] as $nombre) {
            $validada = DB::selectOne('SELECT convalidated FROM pg_constraint WHERE conname = ? AND contype = ?', [$nombre, 'c']);
            $this->assertNotNull($validada, "{$nombre} existe");
            $this->assertTrue((bool) $validada->convalidated, "{$nombre} está validada");
        }
    }

    // Referencias --------------------------------------------------------------------------------------------------

    public function test_restrict_y_set_null_en_servicios_y_cotizaciones(): void
    {
        $servicioId = (int) DB::table('servicios_tecnologicos')->insertGetId($this->filaServicio());
        $uuid = (string) DB::table('servicios_tecnologicos')->where('id', $servicioId)->value('identificador_publico');
        $usuario = User::factory()->create();
        $solicitudId = (int) DB::table('solicitudes_cotizacion')->insertGetId($this->filaCotizacion([
            'servicio_id' => $servicioId, 'servicio_uuid' => $uuid, 'servicio_nombre_historico' => 'Servicio ficticio',
            'creado_por' => $usuario->id, 'responsable_id' => $usuario->id,
        ]));
        DB::table('cotizacion_historial')->insert($this->filaHistorial($solicitudId, 1, ['actor_id' => $usuario->id]));

        // RESTRICT: ni el servicio ni la solicitud con historial pueden borrarse.
        $this->assertSqlState(self::RESTRICT_VIOLATION, fn () => DB::table('servicios_tecnologicos')->where('id', $servicioId)->delete());
        $this->assertSqlState(self::RESTRICT_VIOLATION, fn () => DB::table('solicitudes_cotizacion')->where('id', $solicitudId)->delete());

        // SET NULL: borrar la cuenta conserva el expediente y su historial.
        DB::table('users')->where('id', $usuario->id)->delete();
        $solicitud = DB::table('solicitudes_cotizacion')->where('id', $solicitudId)->first();
        $this->assertNull($solicitud->creado_por);
        $this->assertNull($solicitud->responsable_id);
        $this->assertNull(DB::table('cotizacion_historial')->where('solicitud_id', $solicitudId)->value('actor_id'));
        $this->assertSame(1, DB::table('servicios_tecnologicos')->where('id', $servicioId)->count());
    }

    public function test_referencia_inexistente_es_foreign_key_violation(): void
    {
        $this->assertSqlState('23503', fn () => DB::table('cotizacion_historial')->insert($this->filaHistorial(999999999, 1)));
    }

    // Transacciones ------------------------------------------------------------------------------------------------

    public function test_fallo_a_mitad_de_transaccion_no_deja_filas_ni_bloqueos(): void
    {
        try {
            DB::transaction(function (): void {
                $this->makeStudent('55667788');
                $this->makeCourse('PG52-ROLLBACK', 3);
                throw new RuntimeException('fallo forzado');
            });
            $this->fail('La transacción debía fallar.');
        } catch (RuntimeException $e) {
            $this->assertSame('fallo forzado', $e->getMessage());
        }

        $this->assertSame(0, DB::table('estudiantes')->where('dni', '55667788')->count());
        $this->assertSame(0, DB::table('cursos')->where('codigo', 'PG52-ROLLBACK')->count());

        // Ningún bloqueo huérfano: otra sesión puede tomar y soltar el mismo DNI sin esperar.
        $b = $this->secondSession();
        $b->table('estudiantes')->insert([
            'dni' => '55667788', 'nombres' => 'Ficticio', 'apellidos' => 'Libre', 'estado' => 'activo',
            'created_at' => $this->ahora(), 'updated_at' => $this->ahora(),
        ]);
        $this->assertSame(1, DB::table('estudiantes')->where('dni', '55667788')->count());
    }

    // Migraciones e índices ----------------------------------------------------------------------------------------

    public function test_migraciones_recientes_registradas_y_estructuras_de_la_000037_y_000038(): void
    {
        foreach (['2026_11_02_000033_add_contenido_to_servicio_publicaciones', '2026_11_03_000034_add_fuente_to_chat_logs',
            '2026_11_10_000037_add_indices_consultas_panel', '2026_11_10_000038_create_notificacion_lecturas_table',
            '2026_11_10_000039_add_check_estados_academicos', '2026_11_10_000040_restrict_on_delete_matriculas',
            '2026_11_10_000041_add_check_estados_administrativos', '2026_11_12_000042_add_panel_acceso_permissions'] as $migracion) {
            $this->assertSame(1, DB::table('migrations')->where('migration', $migracion)->count(), "{$migracion} registrada");
        }
        $this->assertSame(0, DB::table('migrations')->where('migration', 'like', '%000035%')->orWhere('migration', 'like', '%000036%')->count(), 'Las suspendidas no se aplican.');

        $indices = [
            'audit_logs_created_at_index' => 'created_at', 'audit_logs_user_id_index' => 'user_id',
            'notificaciones_internas_destino_index' => 'user_id, audiencia, leida',
            'aprobaciones_internas_estado_index' => 'estado', 'matriculas_curso_id_index' => 'curso_id',
            'matriculas_fecha_matricula_index' => 'fecha_matricula', 'chat_logs_created_at_index' => 'created_at',
            'chat_logs_intent_created_at_index' => 'intent_detectado, created_at', 'notificacion_lecturas_unica' => 'notificacion_id, user_id',
        ];
        foreach ($indices as $nombre => $columnas) {
            $def = DB::selectOne("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND indexname = ?", [$nombre]);
            $this->assertNotNull($def, "índice {$nombre}");
            $this->assertStringContainsString('('.$columnas.')', $def->indexdef, "columnas de {$nombre}");
        }
    }
}
