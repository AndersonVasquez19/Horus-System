<?php

declare(strict_types=1);

namespace App\Support\Testing;

use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Env;
use Throwable;

/**
 * Segunda sesión PostgreSQL para las pruebas de concurrencia del perfil aislado (L2.2-C).
 *
 * No añade ningún nombre de conexión ni modifica config('database'): construye OTRA instancia de
 * la misma conexión "pgsql" ya validada, con la fábrica protegida del perfil, y no la registra en
 * DatabaseManager. Por eso:
 *  - no acepta parámetros: host, puerto, base, usuario y marcador salen solo de
 *    TestDatabaseSafety::postgresConfiguration(), que vuelve a validar entorno y configuración;
 *  - abre su PDO a través de GuardedPostgresConnector, que exige la configuración exacta y verifica
 *    la identidad del servidor (marcador independiente, mínimo privilegio) antes de permitir escrituras;
 *  - como no pasa por DatabaseManager, el listener ConnectionEstablished de TestDatabaseSafety no se
 *    dispara: la protección efectiva es el conector anterior, que se ejecuta al abrir el PDO;
 *  - tampoco recibe el reconnector de DatabaseManager: si pierde el PDO (por ejemplo, tras close())
 *    no puede reconectarse por ninguna vía y lanza LostConnectionException.
 *
 * Solo funciona con HORUS_PG_TEST_PROFILE=1; fuera del perfil se rechaza sin conectar.
 */
final class ConcurrentTestSession
{
    public static function open(Application $app): Connection
    {
        $guard = new PostgresTestGuard;

        if ((string) Env::get('HORUS_PG_TEST_PROFILE', '') !== '1') {
            $guard->reject('a second session requires the isolated PostgreSQL profile.');
        }

        // PSR-11 resuelve los bindings dinámicos del perfil, incluidos sus extenders.
        $factory = $app->get('db.factory');
        $connector = $app->get('db.connector.pgsql');
        if (! $factory instanceof GuardedConnectionFactory
            || ! $connector instanceof GuardedPostgresConnector) {
            $guard->reject('a second session requires the guarded factory and connector.');
        }

        // Revalida entorno y configuración completos en cada apertura (rechaza cualquier alteración).
        $expected = PostgresTestGuard::laravelConnection(TestDatabaseSafety::postgresConfiguration($app));

        $connection = $factory->make($expected, 'pgsql');

        if (! $connection instanceof GuardedPostgresConnection || $connection->getConfig() !== $expected) {
            $guard->reject('second session does not match the validated connection.');
        }

        return $connection;
    }

    /**
     * Revierte cualquier transacción abierta y cierra la sesión. Nunca confirma nada.
     */
    public static function close(Connection $connection): void
    {
        try {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        } catch (Throwable) {
            // Una sesión rota no puede confirmar; se descarta igualmente.
        } finally {
            $connection->disconnect();
        }
    }
}
