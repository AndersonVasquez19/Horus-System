<?php

declare(strict_types=1);

namespace App\Support\Testing;

use Illuminate\Database\Schema\PostgresSchemaState;

final class GuardedPostgresSchemaState extends PostgresSchemaState
{
    public function load($path): never
    {
        // Reject before constructing psql/pg_restore, regardless of path or extension.
        (new PostgresTestGuard)->reject('schema dump loading is forbidden in the protected PostgreSQL profile.');
    }
}
