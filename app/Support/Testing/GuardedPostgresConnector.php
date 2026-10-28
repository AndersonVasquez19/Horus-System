<?php

declare(strict_types=1);

namespace App\Support\Testing;

use Closure;
use Illuminate\Database\Connectors\ConnectorInterface;
use Illuminate\Database\Connectors\PostgresConnector;
use PDO;
use Throwable;

final class GuardedPostgresConnector implements ConnectorInterface
{
    /** @param Closure(): array<string, mixed> $configuration */
    public function __construct(
        private readonly Closure $configuration,
        private readonly ConnectorInterface $connector = new PostgresConnector,
    ) {}

    /** @param array<string, mixed> $config */
    public function connect(array $config): PDO
    {
        $guard = new PostgresTestGuard;
        $expected = PostgresTestGuard::laravelConnection(($this->configuration)());
        if ($config !== $expected) {
            $guard->reject('connection changed after configuration validation.');
        }

        try {
            // libpq applies these before the first SQL statement, including connector SETs.
            $pdo = $this->connector->connect($config + ['server_options' => [
                'default_transaction_read_only' => 'on',
                'statement_timeout' => '5000',
                'lock_timeout' => '2000',
            ]]);
        } catch (Throwable) {
            $guard->reject('read-only connection failed; no writes authorized.');
        }

        return $guard->authorize($config, static fn (): array => self::identity($pdo), static function () use ($pdo): PDO {
            // Keep the exact verified session; reconnects pass through this connector again.
            $pdo->exec('SET default_transaction_read_only = off');

            return $pdo;
        });
    }

    /** @return array<string, mixed> */
    public static function identity(PDO $pdo): array
    {
        return $pdo->query(<<<'SQL'
            SELECT current_database() AS database, current_user AS username,
                host(inet_server_addr()) AS server_address, inet_server_port()::text AS server_port,
                CASE WHEN current_setting('search_path') IN ('public', '"public"')
                    AND current_schemas(false) = ARRAY['public']::name[]
                    THEN 'public' ELSE 'unexpected' END AS search_path,
                current_setting('default_transaction_read_only') AS read_only,
                current_setting('TimeZone') AS timezone,
                current_setting('transaction_isolation') AS isolation,
                (SELECT purpose FROM horus_test_control.authorization
                    WHERE database_name = current_database() AND role_name = current_user
                    AND database_oid = (SELECT oid FROM pg_database WHERE datname = current_database())) AS marker,
                (SELECT CASE WHEN rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls
                    THEN 1 ELSE 0 END FROM pg_roles WHERE rolname = current_user) AS unsafe_role,
                (SELECT count(*) FROM pg_auth_members WHERE member =
                    (SELECT oid FROM pg_roles WHERE rolname = current_user)) AS role_memberships,
                (SELECT count(*) FROM pg_database WHERE datname <> current_database()
                    AND has_database_privilege(current_user, oid, 'CONNECT')) AS other_database_access,
                CASE WHEN has_schema_privilege(current_user, 'horus_test_control', 'CREATE')
                    OR has_table_privilege(current_user, 'horus_test_control.authorization',
                        'INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER')
                    OR has_any_column_privilege(current_user, 'horus_test_control.authorization', 'INSERT')
                    OR has_any_column_privilege(current_user, 'horus_test_control.authorization', 'UPDATE')
                    OR has_any_column_privilege(current_user, 'horus_test_control.authorization', 'REFERENCES')
                    OR EXISTS (SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                        WHERE n.nspname = 'horus_test_control' AND c.relname = 'authorization'
                        AND pg_has_role(current_user, c.relowner, 'MEMBER'))
                    THEN 1 ELSE 0 END AS writable_marker
            SQL)->fetch(PDO::FETCH_ASSOC);
    }
}
