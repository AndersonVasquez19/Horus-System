<?php

declare(strict_types=1);

namespace Tests\PostgresIntegrity\Concerns;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Utilidades de las pruebas PostgreSQL del Libro de Reclamaciones (solo perfil aislado; ver
 * RequiresIsolatedPostgres). Datos ficticios. Ningún método cambia la configuración del servidor:
 * los tiempos de espera se fijan con SET de sesión y se restauran al valor previo.
 */
trait ReclamacionesPostgres
{
    /** @return array<string, mixed> */
    protected function filaReclamacion(array $extra = []): array
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

    /**
     * La sentencia debe fallar con $sqlstate. Se ejecuta en un SAVEPOINT (o en una transacción propia si no hay
     * ninguna abierta) para que el error no deje abortada la transacción de la prueba (25P02).
     */
    protected function assertSqlState(string $sqlstate, callable $sentencia, ?Connection $sesion = null): QueryException
    {
        $sesion ??= DB::connection();
        try {
            $sesion->transaction(fn () => $sentencia());
        } catch (QueryException $e) {
            $this->assertSame($sqlstate, $e->errorInfo[0] ?? $e->getCode(), 'SQLSTATE inesperado: '.$e->getMessage());

            return $e;
        }
        $this->fail("La base debió rechazar la sentencia con $sqlstate.");
    }

    /**
     * La acción debe quedar bloqueada por la otra sesión: con un lock_timeout corto, solo para esta sesión, la
     * espera termina en 55P03. Un interbloqueo (40P01) es un fallo explícito. La acción debe gestionar su propia
     * transacción (->transaction()) para que el error la revierta. El valor previo de lock_timeout se restaura.
     */
    protected function assertBloqueada(callable $accion, ?Connection $sesion = null, string $espera = '500ms'): QueryException
    {
        $sesion ??= DB::connection();
        $previo = (string) $sesion->selectOne("SELECT current_setting('lock_timeout') AS v")->v;
        $sesion->statement("SET lock_timeout = '$espera'");
        try {
            $accion();
        } catch (QueryException $e) {
            $this->assertNotSame('40P01', $e->getCode(), 'Interbloqueo detectado');
            $this->assertSame('55P03', $e->getCode(), 'Se esperaba lock_not_available: '.$e->getMessage());

            return $e;
        } finally {
            if ($sesion->transactionLevel() === 0) {
                $sesion->statement("SET lock_timeout = '$previo'");
            }
        }
        $this->fail('La acción debía esperar el bloqueo de la otra sesión.');
    }

    /** La acción no debe esperar ningún bloqueo: se ejecuta con lock_timeout corto y debe terminar. */
    protected function sinEspera(callable $accion, ?Connection $sesion = null): mixed
    {
        $sesion ??= DB::connection();
        $previo = (string) $sesion->selectOne("SELECT current_setting('lock_timeout') AS v")->v;
        $sesion->statement("SET lock_timeout = '500ms'");
        try {
            return $accion();
        } finally {
            if ($sesion->transactionLevel() === 0) {
                $sesion->statement("SET lock_timeout = '$previo'");
            }
        }
    }

    /**
     * Activa la recepción y, si se pide, el envío SOLO durante $accion y restaura después la configuración exacta.
     * Nunca se envía correo: la prueba usa Mail::fake(). Se restaura enseguida porque el guard del perfil exige
     * mail.default=array al abrir cualquier conexión (incluida la segunda sesión).
     */
    protected function conRecepcion(callable $accion, bool $envio = false): mixed
    {
        $claves = ['reclamaciones.enabled', 'reclamaciones.correo.enabled', 'mail.default', 'mail.from.address'];
        $antes = [];
        foreach ($claves as $clave) {
            $antes[$clave] = config($clave);
        }
        config(['reclamaciones.enabled' => true, 'mail.default' => 'smtp', 'mail.from.address' => 'libro@horus.test']);
        if ($envio) {
            config(['reclamaciones.correo.enabled' => true]);
        }
        try {
            return $accion();
        } finally {
            config($antes);
        }
    }
}
