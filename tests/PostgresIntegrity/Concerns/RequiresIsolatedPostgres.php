<?php

declare(strict_types=1);

namespace Tests\PostgresIntegrity\Concerns;

use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use App\Support\Testing\ConcurrentTestSession;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Pruebas de integridad que solo tienen sentido contra la instancia PostgreSQL desechable de L2.2-B
 * (127.0.0.1:55432, base horus_l2_test). PREPARADAS, NO EJECUTADAS.
 *
 * Esta carpeta no figura en phpunit.xml ni en phpunit.pgsql.xml: nunca entra en una suite por
 * accidente. Además, cada prueba se omite antes de arrancar Laravel si no corre bajo el perfil
 * aislado (que valida identidad, puerto y base mediante PostgresTestGuard).
 */
trait RequiresIsolatedPostgres
{
    protected function setUp(): void
    {
        if (getenv('HORUS_PG_TEST_PROFILE') !== '1') {
            $this->markTestSkipped('Requiere el perfil PostgreSQL aislado (HORUS_PG_TEST_PROFILE=1).');
        }

        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requiere el driver pgsql.');
        }
    }

    /** @var list<Connection> */
    private array $concurrentSessions = [];

    protected function tearDown(): void
    {
        // Revierte y cierra cada segunda sesión aunque la prueba haya fallado a mitad de transacción.
        foreach ($this->concurrentSessions as $session) {
            ConcurrentTestSession::close($session);
        }
        $this->concurrentSessions = [];

        parent::tearDown();
    }

    /**
     * Segunda sesión PostgreSQL para simular otra petición concurrente.
     *
     * Otra instancia de la MISMA conexión "pgsql" validada, creada con la fábrica protegida
     * (App\Support\Testing\ConcurrentTestSession): no hay nombre lógico nuevo ni configuración
     * aceptada desde fuera, y su PDO pasa por la misma verificación de identidad del servidor.
     */
    protected function secondSession(): Connection
    {
        $session = ConcurrentTestSession::open($this->app);
        $this->concurrentSessions[] = $session;

        $this->assertNotSame(DB::connection(), $session);
        $this->assertSame(DB::connection()->getConfig(), $session->getConfig());

        return $session;
    }

    /** Limita la espera de bloqueos de la sesión principal para que un bloqueo se observe como error. */
    protected function limitLockWait(string $timeout = '2s'): void
    {
        DB::statement("SET lock_timeout = '{$timeout}'");
    }

    protected function makeStudent(string $dni, string $nombres = 'Estudiante', string $apellidos = 'Ficticio'): Estudiante
    {
        return Estudiante::create([
            'dni' => $dni,
            'nombres' => $nombres,
            'apellidos' => $apellidos,
            'estado' => 'activo',
        ]);
    }

    protected function makeCourse(string $codigo, int $cupos, string $nombre = 'Curso ficticio'): Curso
    {
        return Curso::create([
            'codigo' => $codigo,
            'nombre' => $nombre,
            'descripcion' => 'Curso ficticio para pruebas de integridad.',
            'categoria' => 'tecnologia',
            'duracion_horas' => 20,
            'precio' => 150,
            'modalidad' => 'virtual',
            'horario' => 'Lunes, 18:00-20:00',
            'cupos_maximos' => $cupos,
            'cupos_disponibles' => $cupos,
            'estado' => 'activo',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function enrollmentPayload(Estudiante $estudiante, Curso $curso, string $estado = 'pendiente'): array
    {
        return [
            'estudiante_id' => $estudiante->id,
            'curso_id' => $curso->id,
            'fecha_matricula' => now()->toDateString(),
            'estado' => $estado,
        ];
    }

    protected function activeEnrollments(Estudiante $estudiante, Curso $curso): int
    {
        return Matricula::query()
            ->where('estudiante_id', $estudiante->id)
            ->where('curso_id', $curso->id)
            ->where('estado', '!=', 'anulada')
            ->count();
    }

    protected function staffWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
