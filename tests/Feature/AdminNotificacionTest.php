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
 * Una notificación general se declara con audiencia = 'general' (y user_id nulo). user_id nulo por sí
 * solo NO implica general: también lo tienen las personales huérfanas y las indeterminadas, que no se
 * muestran a nadie (ver NotificacionPrivacidadUsuarioEliminadoTest y NotificacionAudienciaTest).
 *
 * Lectura (decisión del propietario, 10/11/2026): una personal guarda "leída" en su fila; una general se
 * marca por usuario en notificacion_lecturas y queda leída solo para quien la marca
 * (NotificacionLecturaPorUsuarioTest).
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

            $this->assertTrue($notificacion->fresh()->estaLeidaPara($this->yo));
        }

        // La personal se marca en su fila; la general, solo para quien la marcó.
        $this->assertTrue($propia->fresh()->leida);
        $this->assertFalse($general->fresh()->leida);
        $this->assertFalse($general->fresh()->estaLeidaPara($this->otro));
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
        // Sin destinatario se declara expresamente como general (user_id NULL ya no implica general).
        return NotificacionInterna::create([
            'user_id' => $user?->id,
            'audiencia' => $user === null ? 'general' : 'personal',
            'titulo' => $titulo,
            'mensaje' => 'Mensaje ficticio de prueba.',
            'tipo' => 'info',
            'leida' => $leida,
        ]);
    }
}
