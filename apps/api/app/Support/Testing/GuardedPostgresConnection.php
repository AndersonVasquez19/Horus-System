<?php

declare(strict_types=1);

namespace App\Support\Testing;

use Illuminate\Database\PostgresConnection;
use Illuminate\Filesystem\Filesystem;

final class GuardedPostgresConnection extends PostgresConnection
{
    public function getSchemaState(?Filesystem $files = null, ?callable $processFactory = null): GuardedPostgresSchemaState
    {
        return new GuardedPostgresSchemaState($this, $files, $processFactory);
    }
}
