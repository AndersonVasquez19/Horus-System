<?php

declare(strict_types=1);

namespace Tests\PostgresIntegrity;

use App\Models\AprobacionInterna;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\PostgresIntegrity\Concerns\RequiresIsolatedPostgres;
use Tests\TestCase;

/**
 * Concurrencia real de dos sesiones PostgreSQL para el módulo de Aprobaciones.
 * PREPARADAS, NO EJECUTADAS en base real. Solo perfil aislado (127.0.0.1:55433).
 *
 * Utiliza DatabaseTruncation: la segunda sesión (ConcurrentTestSession) solo observa filas
 * confirmadas. Se comprueba la serialización estricta por `lockForUpdate()` sobre la fila
 * de `aprobaciones_internas` evitando decisiones simultáneas o estados inconsistentes.
 */
class AprobacionesConcurrenciaPostgresTest extends TestCase
{
    use DatabaseTruncation;
    use RequiresIsolatedPostgres;

    private const LOCK_NOT_AVAILABLE = '55P03';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * Dos decisores intentando resolver la misma solicitud: la segunda sesión queda bloqueada (55P03)
     * mientras la primera retiene el bloqueo, y tras el commit de la primera, la segunda recibe 409 Conflict.
     */
    public function test_dos_decisiones_concurrentes_se_bloquean_y_la_segunda_falla_por_conflicto(): void
    {
        $admin = $this->staffWithRole('admin');
        $solicitante = $this->staffWithRole('coordinador');
        $solicitud = AprobacionInterna::create([
            'titulo' => 'Compra concurrente de servidores',
            'tipo' => AprobacionInterna::TIPO_COMPRA,
            'estado' => AprobacionInterna::ESTADO_PENDIENTE,
            'solicitante_id' => $solicitante->id,
            'referencia' => 'SRV-PG-001',
        ]);

        $b = $this->secondSession();

        // Sesión B: otra petición que ya tomó el bloqueo FOR UPDATE y está decidiendo la solicitud.
        $b->beginTransaction();
        $b->select('select id from aprobaciones_internas where id = ? for update', [$solicitud->id]);
        $b->update("update aprobaciones_internas set estado = 'aprobado', aprobador_id = ?, updated_at = now() where id = ?", [
            $admin->id,
            $solicitud->id,
        ]);

        // Sesión principal: intenta resolver. Con lock_timeout corto debe quedar BLOQUEADA (55P03).
        $this->limitLockWait();
        $this->assertBlocked(fn () => $this->actingAs($admin)->withoutExceptionHandling()
            ->post(route('admin.aprobaciones.aprobar', $solicitud)));

        // Sesión B confirma su decisión en PostgreSQL.
        $b->commit();

        // Sesión principal repite la petición tras liberarse el bloqueo: lee el estado 'aprobado' bajo bloqueo y responde 409.
        $this->withExceptionHandling()
            ->actingAs($admin)
            ->post(route('admin.aprobaciones.aprobar', $solicitud))
            ->assertStatus(409);

        $this->assertSame(AprobacionInterna::ESTADO_APROBADO, $solicitud->fresh()->estado);
    }

    /**
     * Si la primera sesión sufre un rollback tras tomar el bloqueo, el bloqueo se libera
     * y la segunda sesión puede resolver la solicitud pendiente sin anomalías.
     */
    public function test_rollback_de_primera_sesion_libera_bloqueo_y_permite_resolucion(): void
    {
        $admin = $this->staffWithRole('admin');
        $solicitante = $this->staffWithRole('coordinador');
        $solicitud = AprobacionInterna::create([
            'titulo' => 'Compra con fallo previo',
            'tipo' => AprobacionInterna::TIPO_COMPRA,
            'estado' => AprobacionInterna::ESTADO_PENDIENTE,
            'solicitante_id' => $solicitante->id,
        ]);

        $b = $this->secondSession();

        $b->beginTransaction();
        $b->select('select id from aprobaciones_internas where id = ? for update', [$solicitud->id]);

        $this->limitLockWait();
        $this->assertBlocked(fn () => $this->actingAs($admin)->withoutExceptionHandling()
            ->post(route('admin.aprobaciones.aprobar', $solicitud)));

        // B aborta / revierte su transacción.
        $b->rollBack();

        // La sesión principal puede ahora adquirir el bloqueo, encontrar la solicitud aún 'pendiente' y aprobarla.
        $this->withExceptionHandling()
            ->actingAs($admin)
            ->post(route('admin.aprobaciones.aprobar', $solicitud))
            ->assertRedirect(route('admin.aprobaciones.index'));

        $this->assertSame(AprobacionInterna::ESTADO_APROBADO, $solicitud->fresh()->estado);
        $this->assertSame($admin->id, $solicitud->fresh()->aprobador_id);
    }

    private function assertBlocked(callable $request): void
    {
        try {
            $request();
            $this->fail('La petición debía esperar el bloqueo de la otra sesión.');
        } catch (QueryException $error) {
            $this->assertSame(self::LOCK_NOT_AVAILABLE, $error->getCode());
        } finally {
            DB::statement('SET lock_timeout = 0');
        }
    }
}
