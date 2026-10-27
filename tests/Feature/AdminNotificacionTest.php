<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NotificacionInterna;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bandeja de notificaciones internas: cada usuario ve las suyas y las generales, nunca las ajenas.
 * Datos ficticios.
 *
 * PENDIENTE DE NEGOCIO: el estado "leída" se guarda en la propia notificación. Una notificación
 * general (user_id nulo) marcada como leída por un usuario queda leída para todos. Estas pruebas
 * no fijan ese efecto a propósito; cambiarlo a un estado por usuario requiere decisión específica.
 */
class AdminNotificacionTest extends TestCase
{
    use RefreshDatabase;

    private User $yo;

    private User $otro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->yo = $this->staff('secretaria');
        $this->otro = $this->staff('coordinador');
    }

    public function test_inbox_lists_own_and_general_notifications_but_not_other_users(): void
    {
        $this->notify($this->yo, 'Aviso propio');
        $this->notify(null, 'Aviso general');
        $this->notify($this->otro, 'Aviso ajeno confidencial');
        $this->notify($this->yo, 'Aviso propio leído', leida: true);

        $this->actingAs($this->yo)
            ->get(route('admin.notificaciones.index'))
            ->assertOk()
            ->assertSee('Aviso propio')
            ->assertSee('Aviso general')
            ->assertDontSee('Aviso ajeno confidencial')
            ->assertViewHas('pendientes', 2);
    }

    public function test_user_can_mark_own_and_general_notifications_as_read(): void
    {
        $propia = $this->notify($this->yo, 'Propia');
        $general = $this->notify(null, 'General');

        foreach ([$propia, $general] as $notificacion) {
            $this->actingAs($this->yo)
                ->from(route('admin.notificaciones.index'))
                ->patch(route('admin.notificaciones.leida', $notificacion))
                ->assertRedirect(route('admin.notificaciones.index'))
                ->assertSessionHas('success');

            $this->assertTrue($notificacion->fresh()->leida);
        }
    }

    public function test_user_cannot_mark_another_users_notification_as_read(): void
    {
        $ajena = $this->notify($this->otro, 'Ajena');

        $this->actingAs($this->yo)
            ->patch(route('admin.notificaciones.leida', $ajena))
            ->assertForbidden();

        $this->assertFalse($ajena->fresh()->leida);
    }

    public function test_inbox_requires_a_staff_session(): void
    {
        $notificacion = $this->notify(null, 'General');

        $this->get(route('admin.notificaciones.index'))->assertRedirect(route('login'));
        $this->patch(route('admin.notificaciones.leida', $notificacion))->assertRedirect(route('login'));

        // Una cuenta sin rol administrativo (p. ej. registro público) no entra al panel.
        $this->actingAs(User::factory()->create())
            ->get(route('admin.notificaciones.index'))
            ->assertForbidden();

        $this->assertFalse($notificacion->fresh()->leida);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function notify(?User $user, string $titulo, bool $leida = false): NotificacionInterna
    {
        return NotificacionInterna::create([
            'user_id' => $user?->id,
            'titulo' => $titulo,
            'mensaje' => 'Mensaje ficticio de prueba.',
            'tipo' => 'info',
            'leida' => $leida,
        ]);
    }
}
