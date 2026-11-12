<?php

declare(strict_types=1);

namespace App\Support\Testing;

use Illuminate\Database\Connectors\ConnectorInterface;
use Illuminate\Database\Connectors\SQLiteConnector;
use PDO;

/** Also guards PDO resolution that DatabaseManager may perform before connection events. */
final class MemoryOnlyConnector implements ConnectorInterface
{
    public function __construct(
        private readonly bool $allowed,
        private readonly ConnectorInterface $connector = new SQLiteConnector,
    ) {}

    /** @param array<string, mixed> $config */
    public function connect(array $config): PDO
    {
        if (! $this->allowed || ($config['driver'] ?? null) !== 'sqlite'
            || ($config['database'] ?? null) !== ':memory:' || ! empty($config['url'])) {
            (new PostgresTestGuard)->reject('persistent and secondary drivers are forbidden in this test profile.');
        }

        return $this->connector->connect($config);
    }
}
