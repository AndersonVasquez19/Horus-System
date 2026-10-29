<?php

namespace Tests\Feature;

use App\Jobs\SendMatriculaWelcome;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\MatriculaNotification;
use App\Services\MatriculaNotificationService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * S15-03: los avisos anteriores a una activación nunca se despachan automáticamente.
 * SQLite :memory:, HTTP simulado y bloqueado; no demuestra concurrencia PostgreSQL.
 */
class MatriculaNotificationBacklogTest extends TestCase
{
    use DatabaseMigrations;

    private const MIGRATION = '2026_10_28_000023_hold_matricula_notifications_backlog.php';

    private int $dni = 70000000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        config([
            'matricula-mail.enabled' => false,
            'matricula-mail.connection' => 'database',
            'matricula-mail.queue' => 'default',
            'services.resend.key' => 'fake-key-not-real',
            'mail.from.address' => 'academia@horus.example',
            'mail.from.name' => 'Horus Group',
        ]);
        Http::preventStrayRequests();
        Http::fake(['api.resend.com/*' => Http::response(['id' => 'provider-test-id'])]);
    }

    public function test_disabled_delivery_still_registers_enrollment_and_holds_its_notice(): void
    {
        $matricula = $this->enrollment();

        $this->assertNotNull($matricula->fresh());
        $row = MatriculaNotification::sole();
        $this->assertSame('held', $row->status);
        $this->assertSame(MatriculaNotificationService::HELD_DISABLED, $row->last_error);
        $this->assertSame(0, $row->attempts);
        $this->assertNull($row->payload);
        Http::assertNothingSent();
    }

    public function test_notice_before_activation_is_never_sent_while_later_notice_is(): void
    {
        $antes = $this->enrollment();
        $this->activate();
        $despues = $this->enrollment();

        $this->assertSame('pending', $this->notice($despues)->status);
        $this->assertSame(1, $this->service()->dispatchDue());
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true])->assertSuccessful();

        $this->assertSame('accepted', $this->notice($despues)->status);
        $this->assertSame('held', $this->notice($antes)->status);
        $this->assertSame(0, $this->notice($antes)->attempts);
        Http::assertSentCount(1);
    }

    public function test_restarting_scheduler_and_worker_never_releases_held_notices(): void
    {
        $matricula = $this->enrollment();
        $this->activate();
        $id = $this->notice($matricula)->id;

        foreach (range(1, 3) as $restart) {
            $this->assertSame(0, $this->service()->dispatchDue());
            $this->artisan('horus:matriculas-notificar')->expectsOutput('Notificaciones encoladas: 0')->assertSuccessful();
            $this->travel(10)->minutes();
        }
        // Un job huérfano o repetido para la fila retenida tampoco envía.
        (new SendMatriculaWelcome($id))->handle($this->service());
        (new SendMatriculaWelcome($id))->handle($this->service());

        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame('held', $this->notice($matricula)->status);
        $this->assertSame(0, $this->notice($matricula)->attempts);
        Http::assertNothingSent();
    }

    public function test_incomplete_configuration_at_capture_holds_even_if_completed_later(): void
    {
        config(['matricula-mail.enabled' => true, 'services.resend.key' => '']);
        $matricula = $this->enrollment();
        $this->assertSame(MatriculaNotificationService::HELD_DISABLED, $this->notice($matricula)->last_error);

        config(['services.resend.key' => 'fake-key-not-real']);
        $this->assertSame(0, $this->service()->dispatchDue());
        $this->service()->send($this->notice($matricula)->id);

        $this->assertSame('held', $this->notice($matricula)->status);
        Http::assertNothingSent();
    }

    public function test_backlog_migration_holds_only_unattempted_pending_and_keeps_traceability(): void
    {
        $this->activate();
        $previa = $this->enrollment();          // `pending` como la habría dejado la política anterior.
        $reservada = $this->enrollment();
        $this->assertSame(2, $this->service()->dispatchDue()); // ambas reservadas y encoladas
        $reintento = $this->enrollment();
        $incierta = $this->enrollment();
        $aceptada = $this->enrollment();
        $this->notice($reservada)->update(['dispatched_until' => now()->addMinutes(5)]);
        $this->notice($reintento)->update(['status' => 'retry', 'attempts' => 1, 'first_attempt_at' => now(), 'available_at' => now()->subMinute()]);
        $this->notice($incierta)->update(['status' => 'uncertain', 'attempts' => 2, 'uncertain' => true]);
        $this->notice($aceptada)->update(['status' => 'accepted', 'attempts' => 1, 'provider_id' => 'prov-1']);
        $antes = MatriculaNotification::count();

        $this->runBacklogMigration();
        $this->runBacklogMigration(); // idempotente

        $this->assertSame($antes, MatriculaNotification::count());
        foreach ([$previa, $reservada] as $matricula) {
            $row = $this->notice($matricula);
            $this->assertSame('held', $row->status);
            $this->assertSame(MatriculaNotificationService::HELD_BEFORE_POLICY, $row->last_error);
            $this->assertNull($row->dispatched_until);
            $this->assertNull($row->provider_id);
            $this->assertSame(0, $row->attempts);
        }
        $this->assertSame('retry', $this->notice($reintento)->status);
        $this->assertSame('uncertain', $this->notice($incierta)->status);
        $this->assertSame('accepted', $this->notice($aceptada)->status);

        // Los jobs ya encolados de las filas retenidas no envían; el reintento sí continúa.
        $this->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true])->assertSuccessful();
        $this->service()->send($this->notice($reintento)->id);
        $this->service()->send($this->notice($incierta)->id);
        Http::assertSentCount(1);
        $this->assertSame('accepted', $this->notice($reintento)->status);
        $this->assertSame('held', $this->notice($previa)->status);
        $this->assertSame('uncertain', $this->notice($incierta)->status);

        (require database_path('migrations/'.self::MIGRATION))->down();
        $this->assertSame('held', $this->notice($previa)->status);
    }

    public function test_unattempted_notice_older_than_limit_is_held_at_dispatch(): void
    {
        $this->activate();
        $matricula = $this->enrollment();
        Log::spy();
        $this->travel(MatriculaNotificationService::MAX_FIRST_ATTEMPT_AGE_HOURS)->hours();

        $this->assertSame(0, $this->service()->dispatchDue());

        $row = $this->notice($matricula);
        $this->assertSame('held', $row->status);
        $this->assertSame(MatriculaNotificationService::HELD_STALE, $row->last_error);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->with('matricula.mail', ['outcome' => 'held_stale', 'count' => 1])->once();
    }

    public function test_worker_restarted_after_long_outage_does_not_send_reserved_stale_notice(): void
    {
        $this->activate();
        $matricula = $this->enrollment();
        $this->assertSame(1, $this->service()->dispatchDue());
        $this->travel(MatriculaNotificationService::MAX_FIRST_ATTEMPT_AGE_HOURS + 1)->hours();

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true])->assertSuccessful();
        (new SendMatriculaWelcome($this->notice($matricula)->id))->handle($this->service());

        $this->assertSame('held', $this->notice($matricula)->status);
        $this->assertSame(MatriculaNotificationService::HELD_STALE, $this->notice($matricula)->last_error);
        $this->assertSame(0, $this->notice($matricula)->attempts);
        Http::assertNothingSent();
    }

    public function test_queue_down_beyond_limit_ends_held_instead_of_sending_late(): void
    {
        $this->activate();
        $matricula = $this->enrollment();
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('PRIVATE-QUEUE-CREDENTIAL'));
        $this->assertSame(0, $this->service()->dispatchDue());
        $this->assertSame('queue_unavailable', $this->notice($matricula)->last_error);
        $this->assertSame('pending', $this->notice($matricula)->status);

        $this->travel(MatriculaNotificationService::MAX_FIRST_ATTEMPT_AGE_HOURS + 1)->hours();
        $this->assertSame(0, $this->service()->dispatchDue());

        $this->assertSame('held', $this->notice($matricula)->status);
        Http::assertNothingSent();
    }

    public function test_cancelled_or_deleted_enrollment_keeps_held_notice_without_sending(): void
    {
        $anulada = $this->enrollment();
        $eliminada = $this->enrollment();
        $this->enrollment('anulada');
        $this->assertSame(2, MatriculaNotification::count()); // una anulada al crearse no genera aviso

        $anulada->update(['estado' => 'anulada']);
        $eliminada->delete();
        $this->activate();
        $this->assertSame(0, $this->service()->dispatchDue());
        foreach (MatriculaNotification::pluck('id') as $id) {
            $this->service()->send($id);
        }

        $this->assertSame(2, MatriculaNotification::where('status', 'held')->count());
        Http::assertNothingSent();
    }

    public function test_status_reports_held_reasons_without_writes(): void
    {
        $this->enrollment();
        $this->enrollment();
        $this->activate();
        $this->enrollment();
        $before = DB::table('matricula_notifications')->orderBy('id')->get()->toJson();

        $this->withoutMockingConsoleOutput();
        $this->assertSame(0, $this->artisan('horus:matriculas-notificar', ['--status' => true]));
        $output = Artisan::output();

        $this->assertStringContainsString('held: 2', $output);
        $this->assertStringContainsString('held/'.MatriculaNotificationService::HELD_DISABLED.': 2', $output);
        $this->assertStringContainsString('pending: 1', $output);
        $this->assertStringNotContainsString('@', $output);
        $this->assertSame($before, DB::table('matricula_notifications')->orderBy('id')->get()->toJson());
        Http::assertNothingSent();
    }

    private function activate(): void
    {
        config(['matricula-mail.enabled' => true]);
        $this->assertTrue($this->service()->configured());
    }

    private function runBacklogMigration(): void
    {
        (require database_path('migrations/'.self::MIGRATION))->up();
    }

    private function notice(Matricula $matricula): MatriculaNotification
    {
        return MatriculaNotification::where('matricula_id', $matricula->id)->sole();
    }

    private function service(): MatriculaNotificationService
    {
        return app(MatriculaNotificationService::class);
    }

    private function enrollment(string $estado = 'pendiente'): Matricula
    {
        $n = $this->dni++;
        $student = Estudiante::create(['dni' => (string) $n, 'nombres' => 'Ana', 'apellidos' => 'Prueba',
            'email' => "student{$n}@example.invalid", 'estado' => 'activo']);
        $course = Curso::create(['codigo' => "MAIL-{$n}", 'nombre' => 'Curso de prueba', 'descripcion' => 'Prueba',
            'categoria' => 'tecnologia', 'duracion_horas' => 20, 'precio' => 100, 'modalidad' => 'virtual',
            'horario' => 'Lunes', 'cupos_maximos' => 5, 'cupos_disponibles' => 5, 'estado' => 'activo']);

        return DB::transaction(fn () => Matricula::create(['estudiante_id' => $student->id, 'curso_id' => $course->id,
            'fecha_matricula' => today(), 'estado' => $estado]));
    }
}
