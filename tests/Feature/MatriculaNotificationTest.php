<?php

namespace Tests\Feature;

use App\Jobs\SendMatriculaWelcome;
use App\Mail\MatriculaWelcome;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\MatriculaNotification;
use App\Models\User;
use App\Services\MatriculaNotificationService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class MatriculaNotificationTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        config([
            'matricula-mail.enabled' => true,
            'matricula-mail.connection' => 'database',
            'services.resend.key' => 'fake-key-not-real',
            'mail.from.address' => 'academia@horus.example',
            'mail.from.name' => 'Horus Group',
        ]);
        Http::preventStrayRequests();
        $this->fakeResend(['api.resend.com/*' => Http::response(['id' => 'provider-test-id'])]);
    }

    public function test_creation_records_intent_and_commit_precedes_queue_and_http(): void
    {
        DB::beginTransaction();
        $matricula = $this->enrollment();
        $this->assertDatabaseHas('matricula_notifications', ['matricula_id' => $matricula->id, 'status' => 'pending']);
        $this->assertSame(0, $this->service()->dispatchDue());
        $this->service()->send(MatriculaNotification::sole()->id);
        Http::assertNothingSent();
        DB::commit();

        $this->assertSame(1, $this->service()->dispatchDue());
        $this->assertDatabaseCount('jobs', 1);
        Http::assertNothingSent();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true])->assertSuccessful();
        $this->assertSame('accepted', MatriculaNotification::sole()->status);
        Http::assertSentCount(1);
    }

    public function test_rollback_removes_enrollment_and_notification(): void
    {
        try {
            DB::transaction(function () {
                $this->enrollment();
                throw new RuntimeException('rollback ficticio');
            });
        } catch (RuntimeException) {
        }
        $this->assertDatabaseCount('matriculas', 0);
        $this->assertDatabaseCount('matricula_notifications', 0);
        $this->assertSame(0, $this->service()->dispatchDue());
        Http::assertNothingSent();
    }

    public function test_panel_duplicate_request_and_edit_do_not_duplicate_welcome(): void
    {
        $seed = $this->enrollment();
        $seed->delete();
        MatriculaNotification::query()->delete();
        $data = ['estudiante_id' => $seed->estudiante_id, 'curso_id' => $seed->curso_id,
            'estado' => 'pendiente', 'fecha_matricula' => today()->toDateString(), 'monto_pagado' => null];
        $this->actingAs($this->adminUser())->post(route('admin.matriculas.store'), $data)->assertSessionHasNoErrors();
        $this->post(route('admin.matriculas.store'), $data)->assertSessionHasErrors('estudiante_id');
        $matricula = Matricula::sole();
        $this->put(route('admin.matriculas.update', $matricula), [...$data, 'estado' => 'pagada'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('matricula_notifications', 1);
        $this->service()->send(MatriculaNotification::sole()->id);
        $this->service()->send(MatriculaNotification::sole()->id);
        Http::assertSentCount(1);
    }

    public function test_permissions_reject_creation_without_notification(): void
    {
        $seed = $this->enrollment();
        $seed->forceDelete();
        $data = ['estudiante_id' => $seed->estudiante_id, 'curso_id' => $seed->curso_id, 'estado' => 'pendiente', 'fecha_matricula' => today()->toDateString()];
        $this->post(route('admin.matriculas.store'), $data)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->post(route('admin.matriculas.store'), $data)->assertForbidden();
        $this->assertDatabaseCount('matricula_notifications', 0);
        Http::assertNothingSent();
    }

    public function test_cancelled_creation_edits_and_restoration_never_generate_welcome(): void
    {
        $matricula = $this->enrollment('anulada');
        $matricula->update(['estado' => 'pendiente']);
        $matricula->delete();
        $matricula->restore();
        $this->assertDatabaseCount('matricula_notifications', 0);
    }

    public function test_paid_creation_is_eligible_without_claiming_payment(): void
    {
        $this->enrollment('pagada');
        $this->service()->send(MatriculaNotification::sole()->id);
        Http::assertSent(fn ($request) => str_contains($request['text'], 'No constituye una confirmación de pago.'));
    }

    public function test_invalid_or_missing_email_skips_without_http(): void
    {
        $matricula = $this->enrollment();
        $matricula->estudiante->update(['email' => 'invalid-address']);
        $this->service()->send(MatriculaNotification::sole()->id);
        $this->assertDatabaseHas('matricula_notifications', ['status' => 'skipped', 'last_error' => 'invalid_recipient', 'attempts' => 0]);
        Http::assertNothingSent();
    }

    public function test_cancellation_before_worker_suppresses_send(): void
    {
        $this->enrollment()->update(['estado' => 'anulada']);
        $this->service()->send(MatriculaNotification::sole()->id);
        $this->assertSame('skipped', MatriculaNotification::sole()->status);
        Http::assertNothingSent();
    }

    public function test_provider_rejection_does_not_change_enrollment(): void
    {
        $matricula = $this->enrollment();
        $this->fakeResend(['api.resend.com/*' => Http::response(['message' => 'PRIVATE-DETAIL'], 403)]);
        Log::spy();
        $this->service()->send(MatriculaNotification::sole()->id);
        $this->assertSame('pendiente', $matricula->fresh()->estado);
        $this->assertSame('failed', MatriculaNotification::sole()->status);
        $this->assertNull(MatriculaNotification::sole()->payload);
        Log::shouldHaveReceived('info')->with('matricula.mail', ['outcome' => 'failed'])->once();
    }

    public function test_retry_preserves_encrypted_payload_and_idempotency_key_despite_edits(): void
    {
        $matricula = $this->enrollment();
        $this->fakeResend(['api.resend.com/*' => Http::sequence()->push(['message' => 'unavailable'], 503)->push(['id' => 'accepted-after-retry'])]);
        $id = MatriculaNotification::sole()->id;
        $this->service()->send($id);
        $row = MatriculaNotification::sole();
        $this->assertSame('retry', $row->status);
        $this->assertTrue($row->uncertain);
        $raw = DB::table('matricula_notifications')->value('payload');
        $this->assertStringNotContainsString('student@example.invalid', $raw);
        $this->assertStringNotContainsString('Ana', $raw);
        $this->assertArrayNotHasKey('payload', $row->toArray());
        $matricula->estudiante->update(['nombres' => 'Nombre modificado', 'email' => 'changed@example.invalid']);
        config(['mail.from.name' => 'Changed']);
        $this->service()->send($id); // Antes del backoff no se llama.
        Http::assertSentCount(1);
        $this->travel(61)->seconds();
        $this->service()->send($id);
        $requests = Http::recorded();
        $this->assertSame($requests[0][0]->data(), $requests[1][0]->data());
        $this->assertSame($requests[0][0]->header('Idempotency-Key'), $requests[1][0]->header('Idempotency-Key'));
        $this->assertSame('accepted', MatriculaNotification::sole()->status);
        $this->assertNull(MatriculaNotification::sole()->payload);
    }

    public function test_timeout_is_uncertain_and_retries_are_bounded(): void
    {
        $this->enrollment();
        $this->fakeResend(['api.resend.com/*' => Http::failedConnection()]);
        $id = MatriculaNotification::sole()->id;
        for ($i = 0; $i < 5; $i++) {
            $this->service()->send($id);
            $this->travel(2)->hours();
        }
        $row = MatriculaNotification::sole();
        $this->assertSame(4, $row->attempts);
        $this->assertSame('uncertain', $row->status);
        $this->assertSame('transport_uncertain', $row->last_error);
        $this->assertNull($row->payload);
    }

    public function test_rate_limit_respects_retry_after_and_terminal_failure(): void
    {
        $this->enrollment();
        $this->fakeResend(['api.resend.com/*' => Http::response([], 429, ['Retry-After' => '600'])]);
        $id = MatriculaNotification::sole()->id;
        $this->service()->send($id);
        $this->travel(100)->seconds();
        $this->service()->send($id);
        Http::assertSentCount(1);
        for ($i = 0; $i < 3; $i++) {
            $this->travel(2)->hours();
            $this->service()->send($id);
        }
        $this->assertSame('failed', MatriculaNotification::sole()->status);
        Http::assertSentCount(4);
    }

    public function test_idempotency_window_expiry_prevents_new_http(): void
    {
        $this->enrollment();
        $this->fakeResend(['api.resend.com/*' => Http::response([], 500)]);
        $id = MatriculaNotification::sole()->id;
        $this->service()->send($id);
        $this->travel(23)->hours();
        $this->service()->send($id);
        $this->assertSame('uncertain', MatriculaNotification::sole()->status);
        Http::assertSentCount(1);
    }

    public function test_lease_blocks_duplicate_worker_and_recovers_after_crash(): void
    {
        $this->enrollment();
        $row = MatriculaNotification::sole();
        $row->update(['status' => 'sending', 'attempts' => 1, 'first_attempt_at' => now(), 'lease' => 'old-lease']);
        $this->service()->send($row->id);
        Http::assertNothingSent();
        $this->travel(121)->seconds();
        $this->service()->send($row->id);
        Http::assertSentCount(1);
        $this->assertSame('accepted', $row->fresh()->status);
    }

    public function test_stale_worker_cannot_overwrite_new_owner(): void
    {
        $this->enrollment();
        $this->fakeResend(function () {
            MatriculaNotification::query()->update(['lease' => 'new-owner', 'status' => 'sending']);

            return Http::response(['id' => 'old-result']);
        });
        $this->service()->send(MatriculaNotification::sole()->id);
        $this->assertSame('new-owner', MatriculaNotification::sole()->lease);
        $this->assertSame('sending', MatriculaNotification::sole()->status);
    }

    public function test_http_runs_without_sql_transaction_and_payload_has_both_parts(): void
    {
        $this->enrollment();
        $this->fakeResend(function ($request) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('https://api.resend.com/emails', $request->url());
            $this->assertStringContainsString('<html', $request['html']);
            $this->assertStringNotContainsString('<html', $request['text']);
            $this->assertCount(1, $request['to']);

            return Http::response(['id' => 'verified-parts']);
        });
        $this->service()->send(MatriculaNotification::sole()->id);
        $this->assertSame('accepted', MatriculaNotification::sole()->status);
    }

    public function test_missing_email_and_soft_deleted_enrollment_are_skipped(): void
    {
        $matricula = $this->enrollment();
        $matricula->estudiante->update(['email' => null]);
        $this->service()->send(MatriculaNotification::sole()->id);
        $this->assertSame('skipped', MatriculaNotification::sole()->status);
        // Una bienvenida nueva para una rematrícula es independiente, pero borrarla impide enviar.
        $matricula->update(['estado' => 'anulada']);
        $next = Matricula::create(['estudiante_id' => $matricula->estudiante_id, 'curso_id' => $matricula->curso_id,
            'fecha_matricula' => today(), 'estado' => 'pendiente']);
        $next->delete();
        $this->service()->send(MatriculaNotification::where('matricula_id', $next->id)->sole()->id);
        $this->assertSame(2, MatriculaNotification::where('status', 'skipped')->count());
        Http::assertNothingSent();
    }

    public function test_incomplete_configuration_never_sends(): void
    {
        $this->enrollment();
        $id = MatriculaNotification::sole()->id;
        config(['services.resend.key' => '']);
        $this->service()->send($id);
        config(['services.resend.key' => 'fake', 'mail.from.address' => 'hello@example.com']);
        $this->service()->send($id);
        config(['mail.from.address' => 'bad-address']);
        $this->service()->send($id);
        $this->assertSame('pending', MatriculaNotification::sole()->status);
        Http::assertNothingSent();
    }

    public function test_unavailable_queue_keeps_intent_and_is_recoverable(): void
    {
        $matricula = $this->enrollment();
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('PRIVATE-QUEUE-CREDENTIAL'));
        $this->assertSame(0, $this->service()->dispatchDue());
        $this->assertSame('pending', MatriculaNotification::sole()->status);
        $this->assertSame('queue_unavailable', MatriculaNotification::sole()->last_error);
        $this->assertNotNull($matricula->fresh());
        Http::assertNothingSent();
    }

    public function test_dispatch_is_reserved_and_recoverable_if_job_disappears(): void
    {
        $this->enrollment();
        Queue::fake();
        $this->assertSame(1, $this->service()->dispatchDue());
        $this->assertSame(0, $this->service()->dispatchDue());
        $this->travel(301)->seconds();
        $this->assertSame(1, $this->service()->dispatchDue());
        Queue::assertPushed(SendMatriculaWelcome::class, 2);
        Http::assertNothingSent();
    }

    public function test_sync_queue_and_disabled_delivery_fail_closed(): void
    {
        $this->enrollment();
        config(['matricula-mail.connection' => 'sync']);
        $this->assertSame(0, $this->service()->dispatchDue());
        $this->service()->send(MatriculaNotification::sole()->id);
        config(['matricula-mail.connection' => 'database', 'matricula-mail.enabled' => false]);
        $this->service()->send(MatriculaNotification::sole()->id);
        Http::assertNothingSent();
        $this->assertSame('pending', MatriculaNotification::sole()->status);
    }

    public function test_invalid_success_is_never_reported_as_accepted(): void
    {
        $this->enrollment();
        $this->fakeResend(['api.resend.com/*' => Http::response(['id' => '<script>unsafe</script>'])]);
        $this->service()->send(MatriculaNotification::sole()->id);
        $this->assertSame('retry', MatriculaNotification::sole()->status);
        $this->assertTrue(MatriculaNotification::sole()->uncertain);
    }

    public function test_html_and_plain_text_are_minimal_and_escape_personal_content(): void
    {
        $mail = new MatriculaWelcome('Ana <script>alert(1)</script>', 'Curso & Sistemas');
        $html = $mail->render();
        $text = $mail->plainText();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('Curso & Sistemas', $text);
        $this->assertStringContainsString('viewport', $html);
        $mail->assertSeeInText('No constituye una confirmación de pago.');
        $this->enrollment();
        $this->service()->send(MatriculaNotification::sole()->id);
        Http::assertSent(function ($request) {
            $body = $request->body();
            foreach (['87654321', 'PRIVATE-NOTE', 'PRIVATE-SCHEDULE', '999.99', 'estudiante_id', 'matricula_id'] as $private) {
                $this->assertStringNotContainsString($private, $body);
            }

            return str_contains($request['html'], 'Ana') && str_contains($request['text'], 'Curso de prueba');
        });
    }

    public function test_status_command_only_reports_aggregate_counts(): void
    {
        $this->enrollment();
        $this->artisan('horus:matriculas-notificar', ['--status' => true])->expectsOutput('pending: 1')->assertSuccessful();
        Http::assertNothingSent();
    }

    private function service(): MatriculaNotificationService
    {
        return app(MatriculaNotificationService::class);
    }

    private function fakeResend(array|callable $callback): void
    {
        // Http::fake acumula callbacks; una Factory nueva evita que gane el fake anterior.
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($callback);
    }

    private function enrollment(string $estado = 'pendiente'): Matricula
    {
        $student = Estudiante::create(['dni' => '87654321', 'nombres' => 'Ana', 'apellidos' => 'Prueba', 'email' => 'student@example.invalid', 'estado' => 'activo']);
        $course = Curso::create(['codigo' => 'MAIL-TEST', 'nombre' => 'Curso de prueba', 'descripcion' => 'Prueba', 'categoria' => 'tecnologia',
            'duracion_horas' => 20, 'precio' => 999.99, 'modalidad' => 'virtual', 'horario' => 'PRIVATE-SCHEDULE', 'cupos_maximos' => 5,
            'cupos_disponibles' => 5, 'estado' => 'activo']);

        return DB::transaction(fn () => Matricula::create(['estudiante_id' => $student->id, 'curso_id' => $course->id,
            'fecha_matricula' => today(), 'estado' => $estado, 'observaciones' => 'PRIVATE-NOTE']));
    }
}
