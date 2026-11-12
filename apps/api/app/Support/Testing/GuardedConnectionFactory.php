<?php

declare(strict_types=1);

namespace App\Support\Testing;

use Illuminate\Database\Connectors\ConnectionFactory;

final class GuardedConnectionFactory extends ConnectionFactory
{
    /** @param array<string, mixed> $config */
    protected function createConnection($driver, $connection, $database, $prefix = '', array $config = [])
    {
        if ($driver === 'pgsql') {
            return new GuardedPostgresConnection($connection, $database, $prefix, $config);
        }

        return parent::createConnection($driver, $connection, $database, $prefix, $config);
    }
}
