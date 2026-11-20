<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\AccionAdministrativaDenegada;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditoriaAdministrativa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Sprint 21.1: forma, lista cerrada y privacidad de las denegaciones administrativas.
 */
class AuditoriaAdministrativaTest extends TestCase
{
    use RefreshDatabase;

    public function test_denial_row_has_the_closed_schema_and_the_explicit_actor(): void
    {
        $actor = User::factory()->create();

        $fila = AuditoriaAdministrativa::denegacion(
            $actor->id, 'R1', 'admin_protegido_edicion', 'usuario.actualizar', User::class, 99,
            ['objetivo_es_admin' => true],
        );

        $this->assertSame('acceso_denegado', $fila->action);
        $this->assertSame($actor->id, $fila->user_id);
        $this->assertSame(User::class, $fila->model_type);
        $this->assertSame(99, (int) $fila->model_id);
        $this->assertSame([
            'esquema' => 1,
            'categoria' => 'autorizacion',
            'regla' => 'R1',
            'motivo' => 'admin_protegido_edicion',
            'operacion' => 'usuario.actualizar',
            'contexto' => ['objetivo_es_admin' => true],
        ], $fila->changes);
    }

    public function test_unknown_rule_or_reason_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuditoriaAdministrativa::denegacion(null, 'R1', 'motivo-inventado', 'usuario.actualizar', User::class, null);
    }

    public function test_context_keeps_only_allowed_keys_and_never_free_text(): void
    {
        $contexto = AuditoriaAdministrativa::contexto([
            'roles_solicitados' => ['auditor', "con\ncontrol\x00", str_repeat('x', 300), 'auditor', 12],
            'permisos_excedidos' => 'no-es-lista',
            'objetivo_es_admin' => 1,
            'email' => 'persona@example.com',
            'password' => 'Secreta12345',
            'name' => 'Nombre Apellido',
            'dni' => '70000001',
            'token' => 'abc',
        ]);

        $this->assertSame(['roles_solicitados', 'objetivo_es_admin'], array_keys($contexto));
        $this->assertSame(['auditor', 'concontrol', str_repeat('x', 100)], $contexto['roles_solicitados']);
        $this->assertTrue($contexto['objetivo_es_admin']);
    }

    public function test_context_lists_are_capped(): void
    {
        $permisos = array_map(static fn (int $i): string => 'permiso.'.$i, range(1, 120));

        $this->assertCount(50, AuditoriaAdministrativa::contexto(['permisos_excedidos' => $permisos])['permisos_excedidos']);
    }

    public function test_stored_row_never_contains_secrets_or_personal_data(): void
    {
        $actor = User::factory()->create();
        AuditoriaAdministrativa::denegacion($actor->id, 'R2b', 'rol_fuera_de_alcance', 'usuario.actualizar', User::class, 5, [
            'roles_solicitados' => ['auditor'],
            'email' => 'persona@example.com',
            'password' => 'Secreta12345',
            'cookie' => 'laravel_session=abc',
        ]);

        $json = (string) json_encode(AuditLog::query()->latest('id')->firstOrFail()->changes);

        foreach (['persona@example.com', 'Secreta12345', 'laravel_session', 'password', 'cookie', 'email'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $json);
        }
    }

    public function test_exception_registers_once_and_is_a_generic_403(): void
    {
        $actor = User::factory()->create();
        $excepcion = new AccionAdministrativaDenegada('R3', 'admin_protegido_eliminacion', 'usuario.eliminar', User::class, 7, $actor->id);

        $this->assertSame(403, $excepcion->getStatusCode());
        $this->assertSame('No tienes permiso para realizar esta acción.', $excepcion->getMessage());

        $excepcion->registrar();
        $excepcion->registrar();

        $this->assertSame(1, AuditLog::where('action', 'acceso_denegado')->count());
    }

    public function test_a_failing_denial_audit_never_changes_the_response(): void
    {
        AuditLog::creating(static function (): void {
            throw new RuntimeException('fallo de auditoría simulado');
        });

        $excepcion = new AccionAdministrativaDenegada('R3', 'admin_protegido_eliminacion', 'usuario.eliminar', User::class, 7, null);
        $excepcion->registrar(); // no debe lanzar

        $this->assertSame(0, AuditLog::count());
        $this->assertSame(403, $excepcion->getStatusCode());
    }
}
