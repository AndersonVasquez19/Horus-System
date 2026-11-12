<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NotificacionInterna;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Privacidad de notificaciones personales tras eliminar a su destinatario.
 *
 * Defecto (Fase 1): la FK `notificaciones_internas.user_id` usa nullOnDelete(); al eliminar al usuario A,
 * sus notificaciones personales quedaban con user_id NULL y la bandeja, el contador del dashboard y
 * marcarLeida() las interpretaban como generales, visibles para cualquier otro usuario.
 *
 * Corrección (Fase 2): la columna `audiencia` (personal | general | indeterminada) es independiente de
 * user_id. Una personal conserva su clasificación aunque la FK anule user_id; una general se declara
 * explícitamente; una indeterminada (histórico sin destinatario cierto) no se muestra a nadie.
 * Datos ficticios. Solo SQLite en memoria.
 */
class NotificacionPrivacidadUsuarioEliminadoTest extends TestCase
{
    use RefreshDatabase;

    private const TITULO_PRIVADO = 'Aviso personal confidencial de A';

    private User $admin;

    private User $usuarioA;

    private User $usuarioB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = $this->staff('admin');
        $this->usuarioA = $this->staff('coordinador');
        $this->usuarioB = $this->staff('secretaria');
    }

    // --- Eliminación mediante el flujo HTTP existente -------------------------------------

    public function test_http_deleted_user_personal_notification_is_not_listed_for_other_users(): void
    {
        $huerfana = $this->notificacionHuerfanaPorHttp();
        $propiaB = $this->notificar($this->usuarioB, 'Aviso propio de B');

        $this->bandeja($this->usuarioB)
            ->assertDontSee(self::TITULO_PRIVADO)
            ->assertViewHas('notificaciones', fn ($p): bool => $this->ids($p) === [$propiaB->id]);

        // El administrador que eliminó al usuario tampoco hereda sus avisos personales.
        $this->bandeja($this->admin)
            ->assertDontSee(self::TITULO_PRIVADO)
            ->assertViewHas('notificaciones', fn ($p): bool => ! in_array($huerfana->id, $this->ids($p), true));
    }

    public function test_http_deleted_user_personal_notification_is_not_counted_in_inbox_pending(): void
    {
        $this->notificacionHuerfanaPorHttp();

        $this->bandeja($this->usuarioB)->assertViewHas('pendientes', 0);
        $this->bandeja($this->admin)->assertViewHas('pendientes', 0);
    }

    public function test_http_deleted_user_personal_notification_is_not_counted_in_dashboard(): void
    {
        $this->notificacionHuerfanaPorHttp();

        foreach ([$this->usuarioB, $this->admin] as $usuario) {
            $this->actingAs($usuario)
                ->get(route('admin.dashboard'))
                ->assertOk()
                ->assertViewHas('notificacionesPendientes', 0);
        }
    }

    public function test_other_users_get_403_marking_http_orphaned_personal_notification(): void
    {
        $huerfana = $this->notificacionHuerfanaPorHttp();

        foreach ([$this->usuarioB, $this->admin] as $usuario) {
            $this->actingAs($usuario)
                ->patch(route('admin.notificaciones.leida', $huerfana))
                ->assertForbidden();
        }

        $this->assertFalse($huerfana->fresh()->leida);
        $this->assertSame(NotificacionInterna::AUDIENCIA_PERSONAL, $huerfana->fresh()->audiencia);
    }

    // --- Eliminación mediante Query Builder (sin eventos de Eloquent) ----------------------

    public function test_query_builder_deleted_user_personal_notification_is_not_listed_for_other_user(): void
    {
        $huerfana = $this->notificacionHuerfanaPorQueryBuilder();

        $this->bandeja($this->usuarioB)
            ->assertDontSee(self::TITULO_PRIVADO)
            ->assertViewHas('notificaciones', fn ($p): bool => ! in_array($huerfana->id, $this->ids($p), true))
            ->assertViewHas('pendientes', 0);
    }

    public function test_query_builder_deleted_user_personal_notification_is_not_counted_in_dashboard(): void
    {
        $this->notificacionHuerfanaPorQueryBuilder();

        $this->actingAs($this->usuarioB)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewHas('notificacionesPendientes', 0);
    }

    public function test_other_user_gets_403_marking_query_builder_orphaned_personal_notification(): void
    {
        $huerfana = $this->notificacionHuerfanaPorQueryBuilder();

        $this->actingAs($this->usuarioB)
            ->patch(route('admin.notificaciones.leida', $huerfana))
            ->assertForbidden();

        $this->assertFalse($huerfana->fresh()->leida);
    }

    // --- Rollback de la eliminación administrativa -----------------------------------------

    /**
     * Si la auditoría falla DESPUÉS del DELETE, la transacción de AdminProtection revierte todo:
     * A sigue existiendo y su notificación conserva el destinatario (no queda huérfana).
     * Sin mocks: el DELETE y el SET NULL de la FK se ejecutan de verdad en SQLite y se deshacen.
     */
    public function test_failed_audit_after_admin_deletion_rolls_back_user_and_notification_link(): void
    {
        $notificacion = $this->notificar($this->usuarioA, self::TITULO_PRIVADO);
        $borrado = false;
        $auditoriaIntentada = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$borrado, &$auditoriaIntentada): void {
            if (str_starts_with($sql, 'delete from "users"')) {
                $borrado = true;
            }
            if ($borrado && str_starts_with($sql, 'insert into "audit_logs"')) {
                $auditoriaIntentada = true;
                throw new \RuntimeException('Fallo de auditoría simulado tras la eliminación');
            }
        });

        $this->actingAs($this->admin)
            ->delete(route('admin.usuarios.destroy', $this->usuarioA))
            ->assertStatus(500);

        $this->assertTrue($borrado, 'El DELETE del usuario llegó a ejecutarse');
        $this->assertTrue($auditoriaIntentada, 'El fallo ocurrió en la auditoría posterior a la eliminación');
        $this->assertModelExists($this->usuarioA);
        $fila = $notificacion->fresh();
        $this->assertSame($this->usuarioA->id, $fila->user_id);
        $this->assertSame(NotificacionInterna::AUDIENCIA_PERSONAL, $fila->audiencia);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'user_deleted')->count());

        $this->bandeja($this->usuarioA)
            ->assertViewHas('notificaciones', fn ($p): bool => $this->ids($p) === [$notificacion->id]);
        $this->bandeja($this->usuarioB)
            ->assertViewHas('notificaciones', fn ($p): bool => $this->ids($p) === []);
    }

    // --- Notificaciones indeterminadas (histórico sin destinatario cierto) -----------------

    public function test_indeterminate_notifications_are_hidden_from_every_inbox_and_counter(): void
    {
        $indeterminada = $this->indeterminada('Aviso histórico sin destinatario');

        foreach ([$this->admin, $this->usuarioA, $this->usuarioB] as $usuario) {
            $this->bandeja($usuario)
                ->assertDontSee('Aviso histórico sin destinatario')
                ->assertViewHas('notificaciones', fn ($p): bool => $this->ids($p) === [])
                ->assertViewHas('pendientes', 0);

            $this->actingAs($usuario)
                ->get(route('admin.dashboard'))
                ->assertOk()
                ->assertViewHas('notificacionesPendientes', 0);
        }

        $this->assertSame(NotificacionInterna::AUDIENCIA_INDETERMINADA, $indeterminada->fresh()->audiencia);
    }

    public function test_indeterminate_notifications_cannot_be_marked_as_read(): void
    {
        $indeterminada = $this->indeterminada('Aviso histórico sin destinatario');

        foreach ([$this->admin, $this->usuarioA, $this->usuarioB] as $usuario) {
            $this->actingAs($usuario)
                ->patch(route('admin.notificaciones.leida', $indeterminada))
                ->assertForbidden();
        }

        $this->assertFalse($indeterminada->fresh()->leida);
        $this->assertSame(NotificacionInterna::AUDIENCIA_INDETERMINADA, $indeterminada->fresh()->audiencia);
    }

    // --- Comportamiento que debe conservarse -----------------------------------------------

    public function test_own_personal_notifications_keep_working_while_an_orphan_exists(): void
    {
        $this->notificacionHuerfanaPorHttp();
        $propia = $this->notificar($this->usuarioB, 'Aviso propio de B');

        $this->bandeja($this->usuarioB)
            ->assertSee('Aviso propio de B')
            ->assertViewHas('notificaciones', fn ($p): bool => $this->ids($p) === [$propia->id])
            ->assertViewHas('pendientes', 1);

        $this->actingAs($this->usuarioB)
            ->from(route('admin.notificaciones.index'))
            ->patch(route('admin.notificaciones.leida', $propia))
            ->assertRedirect(route('admin.notificaciones.index'))
            ->assertSessionHas('success');

        $this->assertTrue($propia->fresh()->leida);
    }

    public function test_explicit_general_notifications_keep_working_for_every_staff_user(): void
    {
        $general = $this->general('Aviso general explícito');

        foreach ([$this->admin, $this->usuarioA, $this->usuarioB] as $usuario) {
            $this->bandeja($usuario)
                ->assertSee('Aviso general explícito')
                ->assertViewHas('notificaciones', fn ($p): bool => $this->ids($p) === [$general->id])
                ->assertViewHas('pendientes', 1);

            $this->actingAs($usuario)
                ->get(route('admin.dashboard'))
                ->assertOk()
                ->assertViewHas('notificacionesPendientes', 1);
        }

        $this->actingAs($this->usuarioB)
            ->patch(route('admin.notificaciones.leida', $general))
            ->assertRedirect();

        $this->assertTrue($general->fresh()->estaLeidaPara($this->usuarioB));
        $this->assertFalse($general->fresh()->estaLeidaPara($this->usuarioA));
    }

    public function test_explicit_general_notification_survives_deleting_an_unrelated_user(): void
    {
        $general = $this->general('Aviso general explícito');

        $this->actingAs($this->admin)
            ->delete(route('admin.usuarios.destroy', $this->usuarioA))
            ->assertRedirect(route('admin.usuarios.index'));

        $this->assertModelMissing($this->usuarioA);
        $this->assertSame(NotificacionInterna::AUDIENCIA_GENERAL, $general->fresh()->audiencia);

        $this->bandeja($this->usuarioB)
            ->assertSee('Aviso general explícito')
            ->assertViewHas('notificaciones', fn ($p): bool => $this->ids($p) === [$general->id])
            ->assertViewHas('pendientes', 1);
    }

    public function test_marking_an_already_read_notification_again_still_succeeds(): void
    {
        $propia = $this->notificar($this->usuarioB, 'Aviso ya leído', leida: true);
        $general = $this->general('General ya leída', leida: true);

        foreach ([$propia, $general] as $notificacion) {
            $this->actingAs($this->usuarioB)
                ->from(route('admin.notificaciones.index'))
                ->patch(route('admin.notificaciones.leida', $notificacion))
                ->assertRedirect(route('admin.notificaciones.index'))
                ->assertSessionHas('success');

            $this->assertTrue($notificacion->fresh()->leida);
        }
    }

    /**
     * Decisión del propietario (10/11/2026): la lectura de una general es por usuario. Que A la marque no
     * cambia lo que ve B: sigue viéndola, sin leer y contada como pendiente.
     */
    public function test_general_notification_read_state_is_per_user(): void
    {
        $general = $this->general('Aviso general compartido');

        $this->actingAs($this->usuarioA)
            ->patch(route('admin.notificaciones.leida', $general))
            ->assertRedirect();

        $this->bandeja($this->usuarioA)
            ->assertViewHas('pendientes', 0);

        $this->bandeja($this->usuarioB)
            ->assertSee('Aviso general compartido')
            ->assertViewHas('notificaciones', function ($p) use ($general): bool {
                return $this->ids($p) === [$general->id] && $p->first()->estaLeidaPara($this->usuarioB) === false;
            })
            ->assertViewHas('pendientes', 1);
    }

    // --- Utilidades ------------------------------------------------------------------------

    private function notificacionHuerfanaPorHttp(): NotificacionInterna
    {
        $notificacion = $this->notificar($this->usuarioA, self::TITULO_PRIVADO);

        $this->actingAs($this->admin)
            ->delete(route('admin.usuarios.destroy', $this->usuarioA))
            ->assertRedirect(route('admin.usuarios.index'));

        return $this->comprobarHuerfana($notificacion);
    }

    private function notificacionHuerfanaPorQueryBuilder(): NotificacionInterna
    {
        $notificacion = $this->notificar($this->usuarioA, self::TITULO_PRIVADO);

        DB::table('users')->where('id', $this->usuarioA->id)->delete();

        return $this->comprobarHuerfana($notificacion);
    }

    /** El usuario desapareció, la fila se conserva con user_id NULL y sigue clasificada como personal. */
    private function comprobarHuerfana(NotificacionInterna $notificacion): NotificacionInterna
    {
        $this->assertModelMissing($this->usuarioA);
        $fila = $notificacion->fresh();
        $this->assertNotNull($fila, 'La notificación personal no debe desaparecer en este escenario.');
        $this->assertNull($fila->user_id);
        $this->assertSame(NotificacionInterna::AUDIENCIA_PERSONAL, $fila->audiencia);

        return $notificacion;
    }

    private function bandeja(User $usuario): TestResponse
    {
        return $this->actingAs($usuario)->get(route('admin.notificaciones.index'))->assertOk();
    }

    /** @return list<int> */
    private function ids(mixed $paginador): array
    {
        return collect($paginador->items())->map(fn (NotificacionInterna $n): int => $n->id)->sort()->values()->all();
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function notificar(User $user, string $titulo, bool $leida = false): NotificacionInterna
    {
        return $this->crear(['user_id' => $user->id, 'audiencia' => 'personal'], $titulo, $leida);
    }

    private function general(string $titulo, bool $leida = false): NotificacionInterna
    {
        return $this->crear(['user_id' => null, 'audiencia' => 'general'], $titulo, $leida);
    }

    /** Las indeterminadas solo provienen de la clasificación histórica; se simula con una inserción directa. */
    private function indeterminada(string $titulo): NotificacionInterna
    {
        $id = DB::table('notificaciones_internas')->insertGetId([
            'user_id' => null, 'audiencia' => 'indeterminada', 'titulo' => $titulo,
            'mensaje' => 'Mensaje ficticio de prueba.', 'tipo' => 'info', 'leida' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return NotificacionInterna::query()->findOrFail($id);
    }

    /** @param array{user_id: int|null, audiencia: string} $destino */
    private function crear(array $destino, string $titulo, bool $leida): NotificacionInterna
    {
        return NotificacionInterna::create($destino + [
            'titulo' => $titulo,
            'mensaje' => 'Mensaje ficticio de prueba.',
            'tipo' => 'info',
            'leida' => $leida,
        ]);
    }
}
