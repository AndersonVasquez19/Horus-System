<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Reclamacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Academic\Concerns\LlamaAlPanel;
use Tests\Support\PreparaReclamaciones;
use Tests\TestCase;

/** Plan de mejora del panel, Fase 3.12: el tablero muestra el semáforo de plazos del Libro de Reclamaciones. */
class SemaforoReclamacionesTableroTest extends TestCase
{
    use LlamaAlPanel;
    use PreparaReclamaciones;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->prepararReclamaciones();
    }

    private function conLimite(string $fecha, array $extra = []): Reclamacion
    {
        $r = $this->registrar($extra)['reclamacion'];
        DB::table('reclamaciones')->where('id', $r->id)->update(['fecha_limite' => $fecha]);

        return $r;
    }

    public function test_cuenta_vencidas_hoy_y_por_vencer_sin_datos_personales(): void
    {
        // Hoy es lunes 26/10/2026 (Lima); aviso a 3 días hábiles → jueves 29/10.
        $this->conLimite('2026-10-23');           // vencida
        $this->conLimite('2026-10-26');           // vence hoy
        $this->conLimite('2026-10-28');           // por vencer
        $this->conLimite('2026-11-20');           // dentro de plazo
        $suspendida = $this->conLimite('2026-10-20');
        DB::table('reclamaciones')->where('id', $suspendida->id)->update(['suspension_desde' => '2026-10-21', 'suspension_hasta' => '2026-10-23']);

        $r = $this->panelComo($this->admin, 'GET', 'academico/resumen')->assertOk();

        $this->assertSame(['vencidas' => 1, 'vencen_hoy' => 1, 'por_vencer' => 1], $r->json('data.acciones.reclamaciones'));
        $this->assertStringNotContainsString('Ficticia', (string) json_encode($r->json('data.acciones.reclamaciones')));
    }

    public function test_quien_no_tiene_permiso_de_reclamaciones_no_recibe_el_bloque(): void
    {
        $usuario = $this->usuarioConRol('secretaria');
        $rol = \Spatie\Permission\Models\Role::findByName('secretaria');
        $rol->revokePermissionTo('reclamaciones.ver');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $r = $this->panelComo($usuario->fresh(), 'GET', 'academico/resumen')->assertOk();

        $this->assertArrayNotHasKey('reclamaciones', $r->json('data.acciones') ?? []);
    }
}
