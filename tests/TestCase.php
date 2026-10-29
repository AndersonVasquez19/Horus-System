<?php

namespace Tests;

use App\Models\User;
use App\Support\Testing\GuardedPostgresConnection;
use App\Support\Testing\PostgresTestGuard;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /**
     * Defensa en profundidad para pruebas que escriben: fuera del perfil PostgreSQL aislado exige
     * SQLite :memory: (como antes); dentro, exige la conexión protegida sobre una base de la lista fija.
     * TestDatabaseSafety ya ha validado configuración, identidad del servidor y marcador antes de llegar aquí.
     */
    protected function assertIsolatedTestDatabase(): void
    {
        $connection = DB::connection();

        if (getenv('HORUS_PG_TEST_PROFILE') === '1') {
            $this->assertSame('pgsql', $connection->getDriverName());
            $this->assertInstanceOf(GuardedPostgresConnection::class, $connection);
            $this->assertArrayHasKey($connection->getDatabaseName(), PostgresTestGuard::TARGETS);

            return;
        }

        $this->assertSame('sqlite', $connection->getDriverName());
        $this->assertSame(':memory:', $connection->getDatabaseName());
    }

    /**
     * Usuario con el rol admin y la matriz de permisos real del seeder.
     */
    protected function adminUser(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }
}
