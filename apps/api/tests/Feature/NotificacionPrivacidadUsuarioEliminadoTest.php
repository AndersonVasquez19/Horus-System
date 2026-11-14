<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NotificacionInterna;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\LlamaUsuariosDelPanel;
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
    use LlamaUsuariosDelPanel;
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

        $b = $this->bandeja($this->usuarioB);
        $this->assertStringNotContainsString(self::TITULO_PRIVADO, $b->getContent());
        $this->assertSame([$propiaB->id], $this->ids($b));

        // El administrador que eliminó al usuario tampoco hereda sus avisos personales.
        $admin = $this->bandeja($this->admin);
        $this->assertStringNotContainsString(self::TITULO_PRIVADO, $admin->getContent());
        $this->assertNotContains($huerfana->id, $this->ids($admin));
    }

    public function test_http_deleted_user_personal_notification_is_not_counted_in_inbox_pending(): void
    {
        $this->notificacionHuerfanaPorHttp();

        $this->assertSame(0, $this->pendientes($this->bandeja($this->usuarioB)));
        $this->assertSame(0, $this->pendientes($this->bandeja($this->admin)));
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
            $this->marcarLeida($usuario, $huerfana)->assertForbidden();
        }

        $this->assertFalse($huerfana->fresh()->leida);
        $this->assertSame(NotificacionInterna::AUDIENCIA_PERSONAL, $huerfana->fresh()->audiencia);
    }

    // --- Eliminación mediante Query Builder (sin eventos de Eloquent) ----------------------

    public function test_query_builder_deleted_user_personal_notification_is_not_listed_for_other_user(): void
    {
        $huerfana = $this->notificacionHuerfanaPorQueryBuilder();

        $b = $this->bandeja($this->usuarioB);
        $this->assertStringNotContainsString(self::TITULO_PRIVADO, $b->getContent());
        $this->assertNotContains($huerfana->id, $this->ids($b));
        $this->assertSame(0, $this->pendientes($b));
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

        $this->marcarLeida($this->usuarioB, $huerfana)->assertForbidden();

        $this->assertFalse($huerfana->fresh()->leida);
    }

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
            ->eliminarUsuarioPanel($this->usuarioA)
            ->assertStatus(500);

        $this->assertTrue($borrado, 'El DELETE del usuario llegó a ejecutarse');
        $this->assertTrue($auditoriaIntentada, 'El fallo ocurrió en la auditoría posterior a la eliminación');
        $this->assertModelExists($this->usuarioA);
        $fila = $notificacion->fresh();
        $this->assertSame($this->usuarioA->id, $fila->user_id);
        $this->assertSame(NotificacionInterna::AUDIENCIA_PERSONAL, $fila->audiencia);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'user_deleted')->count());

        $this->assertSame([$notificacion->id], $this->ids($this->bandeja($this->usuarioA)));
        $this->assertSame([], $this->ids($this->bandeja($this->usuarioB)));
    }

    // --- Notificaciones indeterminadas (histórico sin destinatario cierto) -----------------

    public function test_indeterminate_notifications_are_hidden_from_every_inbox_and_counter(): void
    {
        $indeterminada = $this->indeterminada('Aviso histórico sin destinatario');

        foreach ([$this->admin, $this->usuarioA, $this->usuarioB] as $usuario) {
            $bandeja = $this->bandeja($usuario);
            $this->assertSame([], $this->ids($bandeja));
            $this->assertSame(0, $this->pendientes($bandeja));
            $this->assertStringNotContainsString('sin destinatario', $bandeja->getContent());

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
            $this->marcarLeida($usuario, $indeterminada)->assertForbidden();
        }

        $this->assertFalse($indeterminada->fresh()->leida);
        $this->assertSame(NotificacionInterna::AUDIENCIA_INDETERMINADA, $indeterminada->fresh()->audiencia);
    }

    // --- Comportamiento que debe conservarse -----------------------------------------------

    public function test_own_personal_notifications_keep_working_while_an_orphan_exists(): void
    {
        $this->notificacionHuerfanaPorHttp();
        $propia = $this->notificar($this->usuarioB, 'Aviso propio de B');

        $b = $this->bandeja($this->usuarioB);
        $this->assertSame([$propia->id], $this->ids($b));
        $this->assertSame(1, $this->pendientes($b));

        $this->marcarLeida($this->usuarioB, $propia)->assertOk();

        $this->assertTrue($propia->fresh()->leida);
    }

    public function test_explicit_general_notifications_keep_working_for_every_staff_user(): void
    {
        $general = $this->general('Aviso general explícito');

        foreach ([$this->admin, $this->usuarioA, $this->usuarioB] as $usuario) {
            $bandeja = $this->bandeja($usuario);
            $this->assertSame([$general->id], $this->ids($bandeja));
            $this->assertSame(1, $this->pendientes($bandeja));

            $this->actingAs($usuario)
                ->get(route('admin.dashboard'))
                ->assertOk()
                ->assertViewHas('notificacionesPendientes', 1);
        }

        $this->marcarLeida($this->usuarioB, $general)->assertOk();

        $this->assertTrue($general->fresh()->estaLeidaPara($this->usuarioB));
        $this->assertFalse($general->fresh()->estaLeidaPara($this->usuarioA));
    }

    public function test_explicit_general_notification_survives_deleting_an_unrelated_user(): void
    {
        $general = $this->general('Aviso general explícito');

        $this->actingAs($this->admin)
            ->eliminarUsuarioPanel($this->usuarioA)
            ->assertSuccessful();

        $this->assertModelMissing($this->usuarioA);
        $this->assertSame(NotificacionInterna::AUDIENCIA_GENERAL, $general->fresh()->audiencia);

        $b = $this->bandeja($this->usuarioB);
        $this->assertSame([$general->id], $this->ids($b));
        $this->assertSame(1, $this->pendientes($b));
    }

    public function test_marking_an_already_read_notification_again_still_succeeds(): void
    {
        $propia = $this->notificar($this->usuarioB, 'Aviso ya leído', leida: true);
        $general = $this->general('General ya leída', leida: true);

        foreach ([$propia, $general] as $notificacion) {
            $this->marcarLeida($this->usuarioB, $notificacion)->assertOk();

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

        $this->marcarLeida($this->usuarioA, $general)->assertOk();

        $this->assertSame(0, $this->pendientes($this->bandeja($this->usuarioA)));

        $b = $this->bandeja($this->usuarioB);
        $this->assertSame([$general->id], $this->ids($b));
        $this->assertFalse($b->json('data.0.leida'));
        $this->assertSame(1, $this->pendientes($b));
    }

    // --- Utilidades ------------------------------------------------------------------------

    private function notificacionHuerfanaPorHttp(): NotificacionInterna
    {
        $notificacion = $this->notificar($this->usuarioA, self::TITULO_PRIVADO);

        $this->actingAs($this->admin)
            ->eliminarUsuarioPanel($this->usuarioA)
            ->assertSuccessful();

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

    /** Bandeja por la API del panel (`GET /api/panel/v1/compartido/notificaciones`): `data` y `meta.pendientes`. */
    private function bandeja(User $usuario): TestResponse
    {
        return $this->actingAs($usuario)
            ->json('GET', '/api/panel/v1/compartido/notificaciones', [], ['X-Horus-Web-Token' => $this->tokenDelPanel()])
            ->assertOk();
    }

    private function marcarLeida(User $usuario, NotificacionInterna $notificacion): TestResponse
    {
        return $this->actingAs($usuario)
            ->json('POST', '/api/panel/v1/compartido/notificaciones/'.$notificacion->id.'/leida', [], ['X-Horus-Web-Token' => $this->tokenDelPanel()]);
    }

    private function tokenDelPanel(): string
    {
        $token = (string) config('horus-web.service_token');
        if ($token === '') {
            $token = 'token-de-servicio-del-bff-para-pruebas-0123456789';
            config(['horus-web.service_token' => $token]);
        }

        return $token;
    }

    /** @return list<int> */
    private function ids(TestResponse $bandeja): array
    {
        $ids = array_map('intval', $bandeja->json('data.*.id') ?? []);
        sort($ids);

        return $ids;
    }

    private function pendientes(TestResponse $bandeja): int
    {
        return (int) $bandeja->json('meta.pendientes');
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
