<?php

declare(strict_types=1);

namespace Tests\PostgresIntegrity;

use App\Mail\ReclamacionMail;
use App\Models\AuditLog;
use App\Models\ReclamacionEnvio;
use App\Models\User;
use App\Services\EnvioReclamaciones;
use App\Services\GestionReclamaciones;
use App\Services\NumeradorReclamaciones;
use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\PostgresIntegrity\Concerns\ReclamacionesPostgres;
use Tests\PostgresIntegrity\Concerns\RequiresIsolatedPostgres;
use Tests\Support\PreparaReclamaciones;
use Tests\TestCase;

/**
 * Sprint 20 · Fase 2.1 — concurrencia real de dos sesiones PostgreSQL (escenarios C1-C13). PREPARADAS, NO
 * EJECUTADAS. Solo perfil aislado; se omiten en cualquier otro entorno antes de arrancar Laravel. Datos ficticios.
 *
 * DatabaseTruncation (no RefreshDatabase): la segunda sesión solo ve datos confirmados. Las tablas se vacían al
 * empezar cada prueba y también al terminar (para no dejar filas confirmadas a las clases siguientes).
 *
 * Protocolo determinista del repositorio (ConcurrencyPostgresTest), sin hilos ni procesos adicionales: la sesión B
 * (ConcurrentTestSession, misma conexión validada) toma un bloqueo dentro de una transacción; la sesión principal
 * ejecuta el CÓDIGO REAL con un lock_timeout corto, de sesión, y debe quedar bloqueada (55P03); B confirma o
 * revierte y la principal repite. Son bloqueos reales del servidor entre dos backends distintos; lo que no se
 * mide es el entrelazado arbitrario de un estrés con N procesos (no necesario para estas garantías).
 */
class ReclamacionesConcurrenciaPostgresTest extends TestCase
{
    use DatabaseTruncation;
    use PreparaReclamaciones;
    use ReclamacionesPostgres;
    use RequiresIsolatedPostgres {
        setUp as requireIsolatedPostgres;
        tearDown as cerrarSesiones;
    }

    private Connection $b;

    protected function setUp(): void
    {
        $this->requireIsolatedPostgres();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-10-26 12:00:00', 'America/Lima'));
        $this->admin = $this->adminUser();
        // La segunda sesión se abre con la configuración intacta (el guard exige mail.default=array).
        $this->b = $this->secondSession();
    }

    protected function tearDown(): void
    {
        AuditLog::flushEventListeners();
        if ($this->app !== null && isset($this->b)) {
            // Liberar primero cualquier transacción abierta (el TRUNCATE esperaría sus bloqueos) y vaciar después
            // las tablas: las filas confirmadas no deben llegar a las clases que se ejecuten a continuación.
            foreach ([$this->b, DB::connection()] as $sesion) {
                while ($sesion->transactionLevel() > 0) {
                    $sesion->rollBack();
                }
            }
            $this->truncateTablesForAllConnections();
        }
        $this->cerrarSesiones();
    }

    private function numerador(): NumeradorReclamaciones
    {
        return app(NumeradorReclamaciones::class);
    }

    private function servicio(): GestionReclamaciones
    {
        return app(GestionReclamaciones::class);
    }

    private function ultimo(int $anio): ?int
    {
        $v = DB::table('reclamacion_contadores')->where('anio', $anio)->value('ultimo');

        return $v === null ? null : (int) $v;
    }

    /** B inicia una transacción y asigna (código real) el siguiente número de $anio, dejando el bloqueo tomado. */
    private function bRetieneNumero(int $anio): string
    {
        $this->b->beginTransaction();

        return $this->numerador()->siguiente($anio, $this->b);
    }

    // C1 ------------------------------------------------------------------------------------------------------

    public function test_c1_mismo_anio_con_contador_existente_serializa_y_entrega_el_siguiente(): void
    {
        DB::table('reclamacion_contadores')->insert(['anio' => 2026, 'ultimo' => 5]);
        $this->assertSame('000000006-2026', $this->bRetieneNumero(2026));

        $this->assertBloqueada(fn () => DB::transaction(fn () => $this->numerador()->siguiente(2026)));
        $this->assertSame(5, $this->ultimo(2026), 'Lo no confirmado de B no es visible');

        $this->b->commit();
        $this->assertSame('000000007-2026', DB::transaction(fn () => $this->numerador()->siguiente(2026)), 'Tras esperar se lee la versión confirmada');
        $this->assertSame(7, $this->ultimo(2026));
    }

    // C2 ------------------------------------------------------------------------------------------------------

    public function test_c2_primera_alta_del_anio_sin_contador_espera_la_insercion_de_la_otra_sesion(): void
    {
        $this->assertNull($this->ultimo(2031));
        $this->assertSame('000000001-2031', $this->bRetieneNumero(2031));

        // ON CONFLICT DO NOTHING espera a que B resuelva su inserción en el índice único.
        $this->assertBloqueada(fn () => DB::transaction(fn () => $this->numerador()->siguiente(2031)));

        $this->b->commit();
        $this->assertSame('000000002-2031', DB::transaction(fn () => $this->numerador()->siguiente(2031)));
        $this->assertSame(1, DB::table('reclamacion_contadores')->where('anio', 2031)->count(), 'Una sola fila por año');
    }

    // C3 ------------------------------------------------------------------------------------------------------

    public function test_c3_anios_distintos_no_se_bloquean(): void
    {
        $this->bRetieneNumero(2026);
        $numero = $this->sinEspera(fn () => DB::transaction(fn () => $this->numerador()->siguiente(2027)));
        $this->assertSame('000000001-2027', $numero);
        $this->b->rollBack();
        $this->assertNull($this->ultimo(2026));
        $this->assertSame(1, $this->ultimo(2027));
    }

    // C4 ------------------------------------------------------------------------------------------------------

    public function test_c4_rollback_propio_no_consume_numero(): void
    {
        DB::beginTransaction();
        $this->assertSame('000000001-2026', $this->numerador()->siguiente(2026));
        DB::rollBack();
        $this->assertNull($this->ultimo(2026), 'También se revierte la creación de la fila del año');
        $this->assertSame('000000001-2026', DB::transaction(fn () => $this->numerador()->siguiente(2026)), 'Sin hueco');
    }

    // C5 ------------------------------------------------------------------------------------------------------

    public function test_c5_rollback_del_primer_trabajador_libera_su_numero_al_segundo(): void
    {
        $this->assertSame('000000001-2026', $this->bRetieneNumero(2026));
        $this->assertBloqueada(fn () => DB::transaction(fn () => $this->numerador()->siguiente(2026)));
        $this->b->rollBack();
        $this->assertSame('000000001-2026', DB::transaction(fn () => $this->numerador()->siguiente(2026)));
    }

    public function test_c5_rollback_del_segundo_trabajador_tras_esperar_y_recuperacion_del_bloqueo(): void
    {
        DB::beginTransaction();
        $this->assertSame('000000001-2026', $this->numerador()->siguiente(2026));

        // B (segundo trabajador) espera, agota su lock_timeout y su transacción se revierte sola.
        $this->assertBloqueada(fn () => $this->b->transaction(fn () => $this->numerador()->siguiente(2026, $this->b)), $this->b);
        $this->assertSame(0, $this->b->transactionLevel());

        DB::commit();
        // Recuperación: el bloqueo se liberó con el COMMIT; B obtiene el siguiente sin esperar.
        $this->assertSame('000000002-2026', $this->sinEspera(fn () => $this->b->transaction(fn () => $this->numerador()->siguiente(2026, $this->b)), $this->b));
        $this->assertSame(2, $this->ultimo(2026));
    }

    // C6 ------------------------------------------------------------------------------------------------------

    public function test_c6_excepcion_despues_de_asignar_revierte_todo_y_no_deja_bloqueo_huerfano(): void
    {
        AuditLog::creating(function (): void {
            throw new RuntimeException('fallo de auditoría simulado');
        });
        try {
            $this->servicio()->registrarRespaldo($this->admin, $this->datosReclamacion(), 'H-1');
            $this->fail('Debió fallar');
        } catch (RuntimeException) {
        }
        AuditLog::flushEventListeners();

        $this->assertSame(0, DB::table('reclamaciones')->count());
        $this->assertSame(0, DB::table('reclamacion_historial')->count());
        $this->assertNull($this->ultimo(2026), 'El número asignado antes del fallo no se consume');
        $this->assertSame('000000001-2026', $this->sinEspera(fn () => $this->b->transaction(fn () => $this->numerador()->siguiente(2026, $this->b)), $this->b));
    }

    // C7 ------------------------------------------------------------------------------------------------------

    public function test_c7_veinte_altas_alternadas_entre_sesiones_sin_duplicados_ni_huecos(): void
    {
        $numeros = [];
        for ($i = 0; $i < 10; $i++) {
            $numeros[] = DB::transaction(fn () => $this->numerador()->siguiente(2026));
            $numeros[] = $this->b->transaction(fn () => $this->numerador()->siguiente(2026, $this->b));
        }
        $esperados = array_map(fn (int $n) => sprintf('%09d-2026', $n), range(1, 20));
        $this->assertSame($esperados, $numeros);
        $this->assertSame(20, $this->ultimo(2026));

        // Las altas completas por el servicio continúan la misma serie.
        $r = $this->servicio()->registrarRespaldo($this->admin, $this->datosReclamacion(), 'H-21')['reclamacion'];
        $this->assertSame('000000021-2026', $r->numero);
        $this->assertSame(1, DB::table('reclamaciones')->where('numero', '000000021-2026')->count());
    }

    // C8 ------------------------------------------------------------------------------------------------------

    public function test_c8_mismo_token_mientras_otra_sesion_retiene_el_contador(): void
    {
        $token = (string) Str::uuid();
        $numeroB = $this->bRetieneNumero(2026);
        $this->b->table('reclamaciones')->insert($this->filaReclamacion(['numero' => $numeroB, 'token_envio' => hash('sha256', $token)]));

        $this->assertBloqueada(fn () => $this->conRecepcion(fn () => $this->servicio()->registrarPublica($this->datosReclamacion(), $token)));
        $this->b->commit();

        $resultado = $this->conRecepcion(fn () => $this->servicio()->registrarPublica($this->datosReclamacion(), $token));
        $this->assertTrue($resultado['repetida']);
        $this->assertSame($numeroB, $resultado['reclamacion']->numero);
        $this->assertSame(1, DB::table('reclamaciones')->count());
        $this->assertSame(0, DB::table('reclamacion_envios')->count(), 'El reenvío no crea otra copia');
        $this->assertSame(1, $this->ultimo(2026));
    }

    // C9 ------------------------------------------------------------------------------------------------------

    public function test_c9_mismo_token_confirmado_por_otra_sesion_durante_el_alta_termina_en_23505_y_repetida(): void
    {
        $token = (string) Str::uuid();
        $principal = DB::connection();
        $hecho = false;
        DB::listen(function (QueryExecuted $q) use ($principal, $token, &$hecho): void {
            // Justo después de que la sesión principal incremente el contador, B confirma una alta con el mismo token.
            if (! $hecho && $q->connection === $principal && str_starts_with($q->sql, 'update "reclamacion_contadores"')) {
                $hecho = true;
                $this->b->table('reclamaciones')->insert($this->filaReclamacion(['numero' => '000000900-2026', 'token_envio' => hash('sha256', $token)]));
            }
        });

        $resultado = $this->conRecepcion(fn () => $this->servicio()->registrarPublica($this->datosReclamacion(), $token));

        $this->assertTrue($hecho, 'La carrera se provocó');
        $this->assertTrue($resultado['repetida'], 'La violación 23505 se reconcilia devolviendo la ganadora');
        $this->assertSame('000000900-2026', $resultado['reclamacion']->numero);
        $this->assertSame(1, DB::table('reclamaciones')->count());
        $this->assertSame(0, DB::table('reclamacion_historial')->count());
        $this->assertNull($this->ultimo(2026), 'El incremento de la transacción perdedora se revirtió');
        $this->assertSame('000000001-2026', $this->servicio()->registrarRespaldo($this->admin, $this->datosReclamacion(), 'H-1')['reclamacion']->numero, 'Sin hueco');
    }

    // C10 -----------------------------------------------------------------------------------------------------

    private function envioPendiente(): ReclamacionEnvio
    {
        $r = $this->conRecepcion(fn () => $this->servicio()->registrarPublica($this->datosReclamacion(), (string) Str::uuid())['reclamacion']);

        return ReclamacionEnvio::query()->where('reclamacion_id', $r->id)->where('tipo', 'copia_hoja')->sole();
    }

    public function test_c10_dos_trabajadores_sobre_el_mismo_envio_no_duplican_el_correo(): void
    {
        $envio = $this->envioPendiente();
        $this->b->beginTransaction();
        $this->b->select('SELECT id FROM reclamacion_envios WHERE id = ? FOR UPDATE', [$envio->id]);
        $this->b->update("UPDATE reclamacion_envios SET estado = 'enviado', enviado_at = ?, intentos = 1 WHERE id = ?", [now(), $envio->id]);

        $this->assertBloqueada(fn () => $this->conRecepcion(fn () => app(EnvioReclamaciones::class)->enviar($envio->id), envio: true));
        Mail::assertNothingSent();

        $this->b->commit();
        $this->assertSame(EnvioReclamaciones::OMITIDO, $this->conRecepcion(fn () => app(EnvioReclamaciones::class)->enviar($envio->id), envio: true));
        Mail::assertNothingSent();
        $this->assertSame(['enviado', 1], [$envio->fresh()->estado, $envio->fresh()->intentos]);
    }

    public function test_c10_reserva_vigente_de_otro_trabajador_no_se_toma_dos_veces(): void
    {
        $envio = $this->envioPendiente();
        // Hora de la aplicación (la prueba viaja en el tiempo): la reserva es reciente respecto de now() del código.
        $this->b->update("UPDATE reclamacion_envios SET estado = 'procesando', intentos = 1, updated_at = ? WHERE id = ?", [now(), $envio->id]);
        $this->assertSame(EnvioReclamaciones::OMITIDO, $this->conRecepcion(fn () => app(EnvioReclamaciones::class)->enviar($envio->id), envio: true));
        Mail::assertNothingSent();
        $this->assertSame(['procesando', 1], [$envio->fresh()->estado, $envio->fresh()->intentos]);
    }

    // C11 -----------------------------------------------------------------------------------------------------

    public function test_c11_orden_envio_reclamacion_espera_sin_interbloqueo(): void
    {
        $envio = $this->envioPendiente();
        $this->b->beginTransaction();
        $this->b->select('SELECT id FROM reclamaciones WHERE id = ? FOR UPDATE', [$envio->reclamacion_id]);

        // reservar() solo bloquea el envío; el correo se envía (fake) y exito() espera la reclamación de B.
        $this->assertBloqueada(fn () => $this->conRecepcion(fn () => app(EnvioReclamaciones::class)->enviar($envio->id), envio: true));
        Mail::assertSent(ReclamacionMail::class, 1);
        // Ventana documentada: enviado al proveedor pero aún no registrado; la reserva sigue vigente.
        $this->assertSame(['procesando', 1], [$envio->fresh()->estado, $envio->fresh()->intentos]);

        $this->b->commit();
        $this->assertSame(EnvioReclamaciones::OMITIDO, $this->conRecepcion(fn () => app(EnvioReclamaciones::class)->enviar($envio->id), envio: true), 'Sin reenvío inmediato');
        Mail::assertSent(ReclamacionMail::class, 1);
    }

    public function test_c11_responder_por_carta_no_necesita_el_bloqueo_de_un_envio_existente(): void
    {
        $envio = $this->envioPendiente();
        $identificador = (string) DB::table('reclamaciones')->where('id', $envio->reclamacion_id)->value('identificador');
        $this->b->beginTransaction();
        $this->b->select('SELECT id FROM reclamacion_envios WHERE id = ? FOR UPDATE', [$envio->id]);

        // No existe la arista reclamación → envío existente: no hay ciclo posible con el flujo de correo.
        $r = $this->sinEspera(fn () => $this->servicio()->registrarRespuesta($this->admin, $identificador, 'Respuesta por carta.', 'carta', 'Carta 1'));
        $this->assertSame('respondido', $r->estado);
        $this->b->rollBack();
    }

    // C12 -----------------------------------------------------------------------------------------------------

    public function test_c12_borrar_el_actor_espera_al_historial_en_curso_y_el_trigger_admite_el_set_null(): void
    {
        $actor = User::factory()->create(['name' => 'Actor ficticio']);
        $id = DB::table('reclamaciones')->insertGetId($this->filaReclamacion());
        DB::beginTransaction();
        DB::table('reclamacion_historial')->insert(['reclamacion_id' => $id, 'evento' => 'nota_interna', 'actor_id' => $actor->id,
            'actor_nombre' => 'Actor ficticio', 'metadatos' => '{}', 'nota' => 'n', 'created_at' => now()]);

        $this->assertBloqueada(fn () => $this->b->transaction(fn () => $this->b->delete('DELETE FROM users WHERE id = ?', [$actor->id])), $this->b);

        DB::commit();
        $this->sinEspera(fn () => $this->b->transaction(fn () => $this->b->delete('DELETE FROM users WHERE id = ?', [$actor->id])), $this->b);
        $fila = DB::table('reclamacion_historial')->where('reclamacion_id', $id)->sole();
        $this->assertNull($fila->actor_id);
        $this->assertSame(['Actor ficticio', 'n'], [$fila->actor_nombre, $fila->nota]);
    }

    // C13 -----------------------------------------------------------------------------------------------------

    public function test_c13_aislamiento_read_committed_y_tiempos_de_espera_de_sesion(): void
    {
        foreach ([DB::connection(), $this->b] as $sesion) {
            $this->assertSame('read committed', (string) $sesion->selectOne("SELECT current_setting('transaction_isolation') AS v")->v);
            $this->assertSame('read committed', (string) $sesion->selectOne("SELECT current_setting('default_transaction_isolation') AS v")->v);
            // Fijados por GuardedPostgresConnector para la sesión; no es configuración del servidor.
            $this->assertSame('2s', (string) $sesion->selectOne("SELECT current_setting('lock_timeout') AS v")->v);
            $this->assertSame('5s', (string) $sesion->selectOne("SELECT current_setting('statement_timeout') AS v")->v);
        }
        $this->assertNotSame(
            DB::connection()->selectOne('SELECT pg_backend_pid() AS p')->p,
            $this->b->selectOne('SELECT pg_backend_pid() AS p')->p,
            'Dos backends distintos',
        );
    }
}
