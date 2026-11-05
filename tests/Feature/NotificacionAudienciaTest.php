<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AprobacionInterna;
use App\Models\NotificacionInterna;
use App\Models\User;
use Database\Seeders\ProcesosInternosSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/**
 * Audiencia explícita de las notificaciones internas: restricciones de coherencia en base de datos,
 * productores (aprobaciones) y seeder. Datos ficticios. Solo SQLite en memoria (triggers equivalentes a
 * los CHECK de PostgreSQL); la variante PostgreSQL de la migración no se ha ejecutado.
 */
class NotificacionAudienciaTest extends TestCase
{
    use RefreshDatabase;

    // Nombre con el que cada regla aborta (RAISE en SQLite; nombre del CHECK en PostgreSQL).
    private const AUDIENCIA_VALIDA = 'notificaciones_internas_audiencia_valida';

    private const GENERAL_SIN_DESTINATARIO = 'notificaciones_internas_general_sin_destinatario';

    private const INDETERMINADA_SIN_DESTINATARIO = 'notificaciones_internas_indeterminada_sin_destinatario';

    private const PERSONAL_CON_DESTINATARIO = 'notificaciones_internas_personal_con_destinatario';

    private const AUDIENCIA_INMUTABLE = 'notificaciones_internas_audiencia_inmutable';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // --- Restricciones de coherencia --------------------------------------------------------

    public function test_a_personal_notification_requires_a_recipient(): void
    {
        $this->assertRechazada(self::PERSONAL_CON_DESTINATARIO, fn () => NotificacionInterna::create($this->datos(['user_id' => null, 'audiencia' => 'personal'])));
        $this->assertRechazada(self::PERSONAL_CON_DESTINATARIO, fn () => DB::table('notificaciones_internas')->insert($this->fila(['user_id' => null, 'audiencia' => 'personal'])));
    }

    public function test_a_general_notification_cannot_have_a_recipient(): void
    {
        $usuario = $this->staff('secretaria');

        $this->assertRechazada(self::GENERAL_SIN_DESTINATARIO, fn () => NotificacionInterna::create($this->datos(['user_id' => $usuario->id, 'audiencia' => 'general'])));
        $this->assertRechazada(self::GENERAL_SIN_DESTINATARIO, fn () => DB::table('notificaciones_internas')->insert($this->fila(['user_id' => $usuario->id, 'audiencia' => 'general'])));
    }

    public function test_an_indeterminate_notification_cannot_have_a_recipient(): void
    {
        $usuario = $this->staff('secretaria');

        $this->assertRechazada(self::INDETERMINADA_SIN_DESTINATARIO, fn () => DB::table('notificaciones_internas')->insert($this->fila(['user_id' => $usuario->id, 'audiencia' => 'indeterminada'])));
    }

    public function test_audience_must_be_declared_and_valid(): void
    {
        $usuario = $this->staff('secretaria');

        $this->assertRechazada(self::AUDIENCIA_VALIDA, fn () => NotificacionInterna::create($this->datos(['user_id' => $usuario->id])));
        $this->assertRechazada(self::AUDIENCIA_VALIDA, fn () => NotificacionInterna::create($this->datos(['user_id' => null])));
        $this->assertRechazada(self::AUDIENCIA_VALIDA, fn () => DB::table('notificaciones_internas')->insert($this->fila(['user_id' => null, 'audiencia' => null])));
        $this->assertRechazada(self::AUDIENCIA_VALIDA, fn () => DB::table('notificaciones_internas')->insert($this->fila(['user_id' => null, 'audiencia' => 'todos'])));
        $this->assertRechazada(self::AUDIENCIA_VALIDA, fn () => DB::table('notificaciones_internas')->insert($this->fila(['user_id' => $usuario->id, 'audiencia' => 'PERSONAL'])));
    }

    public function test_an_orphaned_personal_notification_cannot_be_republished_as_general(): void
    {
        $usuario = $this->staff('coordinador');
        $notificacion = NotificacionInterna::create($this->datos(['user_id' => $usuario->id, 'audiencia' => 'personal']));
        DB::table('users')->where('id', $usuario->id)->delete();

        $this->assertRechazada(self::AUDIENCIA_INMUTABLE, fn () => DB::table('notificaciones_internas')->where('id', $notificacion->id)->update(['audiencia' => 'general']));
        $this->assertRechazada(self::AUDIENCIA_INMUTABLE, fn () => DB::table('notificaciones_internas')->where('id', $notificacion->id)->update(['audiencia' => 'indeterminada']));

        $this->assertSame('personal', DB::table('notificaciones_internas')->where('id', $notificacion->id)->value('audiencia'));
    }

    public function test_an_orphaned_personal_notification_cannot_be_reassigned_to_another_user(): void
    {
        $usuarioA = $this->staff('coordinador');
        $usuarioB = $this->staff('secretaria');
        $notificacion = NotificacionInterna::create($this->datos(['user_id' => $usuarioA->id, 'audiencia' => 'personal']));

        $this->assertRechazada(self::AUDIENCIA_INMUTABLE, fn () => DB::table('notificaciones_internas')->where('id', $notificacion->id)->update(['user_id' => $usuarioB->id]));

        DB::table('users')->where('id', $usuarioA->id)->delete();
        $this->assertRechazada(self::AUDIENCIA_INMUTABLE, fn () => DB::table('notificaciones_internas')->where('id', $notificacion->id)->update(['user_id' => $usuarioB->id]));

        $this->assertNull(DB::table('notificaciones_internas')->where('id', $notificacion->id)->value('user_id'));
    }

    public function test_marking_as_read_and_foreign_key_null_on_delete_keep_working(): void
    {
        $usuario = $this->staff('coordinador');
        $notificacion = NotificacionInterna::create($this->datos(['user_id' => $usuario->id, 'audiencia' => 'personal']));

        $notificacion->update(['leida' => true]);
        $usuario->delete();

        $fila = $notificacion->fresh();
        $this->assertNotNull($fila);
        $this->assertTrue($fila->leida);
        $this->assertNull($fila->user_id);
        $this->assertSame('personal', $fila->audiencia);
    }

    /** La utilidad de rechazo no debe aceptar un error SQL ajeno a la regla esperada. */
    public function test_rejection_helper_does_not_accept_an_unrelated_sql_error(): void
    {
        $usuario = $this->staff('secretaria');

        $aceptado = true;
        try {
            $this->assertRechazada(self::PERSONAL_CON_DESTINATARIO, fn () => DB::table('notificaciones_internas')
                ->insert($this->fila(['user_id' => $usuario->id, 'audiencia' => 'personal', 'columna_inexistente' => 1])));
        } catch (AssertionFailedError) {
            $aceptado = false;
        }
        $this->assertFalse($aceptado, 'Un error de columna inexistente no prueba la restricción.');

        $aceptado = true;
        try {
            // Rechazada, pero por otra regla: tampoco cuenta.
            $this->assertRechazada(self::PERSONAL_CON_DESTINATARIO, fn () => DB::table('notificaciones_internas')
                ->insert($this->fila(['user_id' => $usuario->id, 'audiencia' => 'general'])));
        } catch (AssertionFailedError) {
            $aceptado = false;
        }
        $this->assertFalse($aceptado, 'Un rechazo por otra restricción no prueba la esperada.');
    }

    // --- API central del modelo -------------------------------------------------------------

    /**
     * La bandeja y los contadores usan scopeVisiblePara(); la autorización de marcado usa esVisiblePara().
     * Ambos deben coincidir en cada combinación de audiencia y usuario.
     */
    public function test_visibility_scope_and_instance_check_always_agree(): void
    {
        $yo = $this->staff('secretaria');
        $otro = $this->staff('coordinador');
        $eliminado = $this->staff('coordinador');

        $casos = [
            'personal propia' => NotificacionInterna::crearPersonal($yo->id, ['titulo' => 'Propia', 'mensaje' => 'M']),
            'personal ajena' => NotificacionInterna::crearPersonal($otro->id, ['titulo' => 'Ajena', 'mensaje' => 'M']),
            'personal huérfana' => NotificacionInterna::crearPersonal($eliminado->id, ['titulo' => 'Huérfana', 'mensaje' => 'M']),
            'general' => NotificacionInterna::crearGeneral(['titulo' => 'General', 'mensaje' => 'M']),
            'indeterminada' => NotificacionInterna::query()->findOrFail(
                DB::table('notificaciones_internas')->insertGetId($this->fila(['user_id' => null, 'audiencia' => 'indeterminada']))
            ),
        ];
        $eliminado->delete();

        $esperado = [
            'personal propia' => [true, false],
            'personal ajena' => [false, true],
            'personal huérfana' => [false, false],
            'general' => [true, true],
            'indeterminada' => [false, false],
        ];
        foreach ($casos as $caso => $notificacion) {
            $notificacion = $notificacion->fresh();
            foreach ([$yo, $otro] as $i => $usuario) {
                $enScope = NotificacionInterna::query()->visiblePara($usuario)->whereKey($notificacion->id)->exists();
                $this->assertSame($enScope, $notificacion->esVisiblePara($usuario), "Divergencia en «{$caso}» (usuario {$i})");
                $this->assertSame($esperado[$caso][$i], $enScope, "Visibilidad inesperada en «{$caso}» (usuario {$i})");
            }
        }

        // Audiencia desconocida o general con destinatario: la base de datos no las admite, así que el scope
        // nunca puede devolverlas; la comprobación de instancia también debe negarlas.
        foreach ([['otra', null], ['otra', $yo->id], ['general', $yo->id], ['indeterminada', $yo->id]] as [$audiencia, $userId]) {
            $fuera = (new NotificacionInterna)->forceFill(['audiencia' => $audiencia, 'user_id' => $userId]);
            $this->assertFalse($fuera->esVisiblePara($yo), "«{$audiencia}» con user_id ".var_export($userId, true));
        }
        $this->assertRechazada(self::AUDIENCIA_VALIDA, fn () => DB::table('notificaciones_internas')->insert($this->fila(['user_id' => null, 'audiencia' => 'otra'])));
    }

    public function test_model_factories_declare_the_audience_explicitly(): void
    {
        $usuario = $this->staff('secretaria');

        $personal = NotificacionInterna::crearPersonal($usuario->id, ['titulo' => 'P', 'mensaje' => 'M', 'tipo' => 'info']);
        $general = NotificacionInterna::crearGeneral(['titulo' => 'G', 'mensaje' => 'M', 'tipo' => 'info']);

        $this->assertSame([$usuario->id, 'personal'], [$personal->user_id, $personal->fresh()->audiencia]);
        $this->assertSame([null, 'general'], [$general->user_id, $general->fresh()->audiencia]);

        // Los atributos no pueden sobrescribir el destino declarado.
        $intento = NotificacionInterna::crearGeneral(['titulo' => 'G2', 'mensaje' => 'M', 'user_id' => $usuario->id, 'audiencia' => 'personal']);
        $this->assertSame([null, 'general'], [$intento->fresh()->user_id, $intento->fresh()->audiencia]);
    }

    public function test_visibility_scope_only_returns_own_personal_and_general_notifications(): void
    {
        $yo = $this->staff('secretaria');
        $otro = $this->staff('coordinador');
        $eliminado = $this->staff('coordinador');

        $propia = NotificacionInterna::crearPersonal($yo->id, ['titulo' => 'Propia', 'mensaje' => 'M']);
        $general = NotificacionInterna::crearGeneral(['titulo' => 'General', 'mensaje' => 'M']);
        NotificacionInterna::crearPersonal($otro->id, ['titulo' => 'Ajena', 'mensaje' => 'M']);
        NotificacionInterna::crearPersonal($eliminado->id, ['titulo' => 'Huérfana', 'mensaje' => 'M']);
        DB::table('notificaciones_internas')->insert($this->fila(['user_id' => null, 'audiencia' => 'indeterminada']));
        $eliminado->delete();

        $ids = NotificacionInterna::query()->visiblePara($yo)->orderBy('id')->pluck('id')->all();

        $this->assertSame([$propia->id, $general->id], $ids);
        $this->assertTrue($propia->esVisiblePara($yo));
        $this->assertTrue($general->esVisiblePara($otro));
        $this->assertFalse($propia->esVisiblePara($otro));
    }

    // --- Productores: aprobaciones -----------------------------------------------------------

    public function test_approval_producers_create_personal_notifications(): void
    {
        $solicitante = $this->staff('secretaria');
        $aprobador = $this->staff('admin');

        $aprobacion = AprobacionInterna::create([
            'titulo' => 'Compra ficticia', 'tipo' => 'compra', 'estado' => 'pendiente',
            'solicitante_id' => $solicitante->id, 'aprobador_id' => $aprobador->id,
        ]);
        $aprobacion->update(['estado' => 'aprobado']);

        $avisos = NotificacionInterna::query()->orderBy('id')->get(['user_id', 'audiencia', 'titulo'])->toArray();
        $this->assertSame([
            ['user_id' => $aprobador->id, 'audiencia' => 'personal', 'titulo' => 'Nueva solicitud de aprobación'],
            ['user_id' => $solicitante->id, 'audiencia' => 'personal', 'titulo' => 'Cambio de aprobación'],
        ], $avisos);
    }

    public function test_approval_without_recipient_does_not_create_a_general_notification(): void
    {
        AprobacionInterna::create(['titulo' => 'Sin aprobador', 'tipo' => 'compra', 'estado' => 'pendiente']);
        AprobacionInterna::query()->firstOrFail()->update(['estado' => 'rechazado']);

        $this->assertDatabaseCount('notificaciones_internas', 0);
    }

    // --- Seeder ---------------------------------------------------------------------------

    public function test_seeder_declares_audiences_and_is_idempotent(): void
    {
        $admin = $this->staffConCorreo('admin', 'admin@horusgroup.com');
        $coordinador = $this->staffConCorreo('coordinador', 'coordinador@horusgroup.com');
        $this->staffConCorreo('secretaria', 'secretaria@horusgroup.com');

        $this->seed(ProcesosInternosSeeder::class);
        $this->seed(ProcesosInternosSeeder::class);

        $filas = NotificacionInterna::query()->orderBy('id')->get(['titulo', 'user_id', 'audiencia'])->toArray();
        $this->assertSame([
            ['titulo' => 'Nuevo proceso asignado', 'user_id' => $admin->id, 'audiencia' => 'personal'],
            ['titulo' => 'Revisión de cupos', 'user_id' => $coordinador->id, 'audiencia' => 'personal'],
            ['titulo' => 'Cierre mensual', 'user_id' => null, 'audiencia' => 'general'],
        ], $filas);
    }

    public function test_seeder_skips_personal_notifications_without_recipient(): void
    {
        $this->seed(ProcesosInternosSeeder::class);

        $filas = NotificacionInterna::query()->get(['titulo', 'user_id', 'audiencia'])->toArray();
        $this->assertSame([['titulo' => 'Cierre mensual', 'user_id' => null, 'audiencia' => 'general']], $filas);
        $this->assertSame(0, NotificacionInterna::query()->where('audiencia', '!=', 'general')->whereNull('user_id')->count());
    }

    // --- Utilidades ------------------------------------------------------------------------

    /**
     * Exige que la base de datos rechace la operación precisamente por $restriccion (el mensaje de la
     * excepción la nombra) y que la tabla quede exactamente igual. Cualquier otro error SQL —columna
     * inexistente, otra regla— hace fallar la prueba en lugar de contar como rechazo.
     */
    private function assertRechazada(string $restriccion, callable $operacion): void
    {
        $antes = DB::table('notificaciones_internas')->orderBy('id')->get()->map(fn ($f): array => (array) $f)->all();

        try {
            DB::transaction(fn () => $operacion());
            $this->fail("La base de datos debía rechazar la operación por {$restriccion}.");
        } catch (QueryException $e) {
            $this->assertMatchesRegularExpression(
                '/\b'.preg_quote($restriccion, '/').'\b/',
                $e->getMessage(),
                "Se esperaba el rechazo por {$restriccion}; el error fue otro."
            );
        }

        $despues = DB::table('notificaciones_internas')->orderBy('id')->get()->map(fn ($f): array => (array) $f)->all();
        $this->assertSame($antes, $despues, 'La operación rechazada no debe dejar cambios.');
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function datos(array $extra): array
    {
        return $extra + ['titulo' => 'Aviso ficticio', 'mensaje' => 'Mensaje ficticio.', 'tipo' => 'info', 'leida' => false];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function fila(array $extra): array
    {
        return $this->datos($extra) + ['created_at' => now(), 'updated_at' => now()];
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function staffConCorreo(string $role, string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->assignRole($role);

        return $user;
    }
}
