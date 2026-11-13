<?php

namespace Tests;

use App\Models\User;
use App\Support\Testing\GuardedPostgresConnection;
use App\Support\Testing\PostgresTestGuard;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
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

    /**
     * Funciones de PostgreSQL que crean las migraciones y que `db:wipe` NO borra (solo borra tablas, vistas y tipos).
     * Si sobreviven al wipe de `migrate:fresh`, la migración 000032 se niega a continuar (salvaguarda deliberada).
     *
     * @var list<string>
     */
    private const FUNCIONES_PG_DE_MIGRACIONES = [
        'notificaciones_internas_audiencia_coherente',
        'reclamacion_historial_inmutable',
    ];

    /**
     * Solo bajo el perfil PostgreSQL aislado y antes del único `migrate:fresh` de cada proceso, elimina las funciones
     * que el wipe dejaría atrás. CASCADE es seguro: el wipe siguiente borra las tablas y las migraciones recrean función
     * y trigger. No se ejecuta en SQLite ni en pruebas que no migran; con RefreshDatabase/DatabaseTruncation solo antes de
     * la primera migración del proceso (después la base ya está migrada y no debe tocarse); con DatabaseMigrations,
     * antes de cada prueba (cada una hace su propio `migrate:fresh`).
     */
    protected function setUpTraits()
    {
        $this->eliminarFuncionesResidualesAntesDelWipe();

        return parent::setUpTraits();
    }

    private function eliminarFuncionesResidualesAntesDelWipe(): void
    {
        if (getenv('HORUS_PG_TEST_PROFILE') !== '1') {
            return;
        }

        $usos = class_uses_recursive(static::class);
        // DatabaseMigrations ejecuta `migrate:fresh` antes de CADA prueba (no una vez por proceso): las funciones se
        // limpian siempre. RefreshDatabase y DatabaseTruncation solo migran una vez por proceso.
        $migraEnCadaPrueba = isset($usos[DatabaseMigrations::class]);
        $migraUnaVez = isset($usos[RefreshDatabase::class]) || isset($usos[DatabaseTruncation::class]);

        if (! $migraEnCadaPrueba && (! $migraUnaVez || RefreshDatabaseState::$migrated)) {
            return;
        }

        $this->assertIsolatedTestDatabase();

        foreach (self::FUNCIONES_PG_DE_MIGRACIONES as $funcion) {
            DB::statement("DROP FUNCTION IF EXISTS {$funcion}() CASCADE");
        }
    }
}
