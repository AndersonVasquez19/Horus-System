<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Reclamacion;
use App\Models\User;
use App\Services\GestionReclamaciones;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/** Datos sintéticos y activación simulada del Libro de Reclamaciones en pruebas (SQLite en memoria). */
trait PreparaReclamaciones
{
    protected User $admin;

    protected function prepararReclamaciones(bool $habilitar = true): void
    {
        $this->assertIsolatedTestDatabase();
        // Lunes 26/10/2026, mediodía en Lima.
        $this->travelTo(Carbon::parse('2026-10-26 12:00:00', 'America/Lima'));
        Http::preventStrayRequests();
        Queue::fake();
        $this->admin = $this->adminUser();
        if ($habilitar) {
            $this->habilitarReclamaciones();
        }
    }

    /** Simula la activación solo en esta prueba; nunca cambia los valores predeterminados de config/. */
    protected function habilitarReclamaciones(bool $envio = false): void
    {
        config(['reclamaciones.enabled' => true, 'mail.default' => 'smtp', 'mail.from.address' => 'libro@horus.test']);
        if ($envio) {
            config(['reclamaciones.correo.enabled' => true]);
        }
    }

    /** @return array<string, mixed> */
    protected function datosReclamacion(array $extra = []): array
    {
        return $extra + [
            'tipo' => 'reclamo', 'nombres' => 'Persona', 'apellidos' => 'Ficticia', 'tipo_documento' => 'dni',
            'numero_documento' => '12345678', 'domicilio' => 'Calle Ficticia 123, Cajamarca', 'telefono' => '+51 900 000 000',
            'email' => 'persona.ficticia@example.test', 'canal_respuesta' => 'correo', 'es_menor' => false,
            'bien_tipo' => 'servicio', 'bien_descripcion' => 'Curso sintético de prueba', 'monto_bien' => '150.00',
            'detalle' => 'Detalle sintético del reclamo para pruebas automatizadas.', 'pedido' => 'Pedido sintético.',
        ];
    }

    /** @return array{reclamacion: Reclamacion, repetida: bool} */
    protected function registrar(array $extra = [], ?string $token = null): array
    {
        return app(GestionReclamaciones::class)->registrarPublica($this->datosReclamacion($extra), $token ?? (string) Str::uuid());
    }

    protected function usuarioConPermisos(string $rol, array $permisos = []): User
    {
        $usuario = User::factory()->create();
        $usuario->assignRole($rol);
        foreach ($permisos as $permiso) {
            $usuario->givePermissionTo($permiso);
        }

        return $usuario;
    }
}
