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
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
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
            'matricula-mail.queue' => 'default',
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

    #[DataProvider('invalidOperationalConfiguration')]
    public function test_invalid_operational_configuration_blocks_dispatch_and_send(string $key, mixed $value): void
    {
        $this->enrollment();
        config([$key => $value]);

        $this->assertFalse($this->service()->configured());
        $this->assertSame(0, $this->service()->dispatchDue());
        $this->service()->send(MatriculaNotification::sole()->id);
        $this->artisan('horus:matriculas-notificar')->assertFailed();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame(0, MatriculaNotification::sole()->attempts);
        $this->assertNull(MatriculaNotification::sole()->dispatched_until);
        Http::assertNothingSent();
    }

    public static function invalidOperationalConfiguration(): array
    {
        return [
            'empty queue' => ['matricula-mail.queue', ''],
            'whitespace queue' => ['matricula-mail.queue', '   '],
            'null queue' => ['matricula-mail.queue', null],
            'uppercase example com' => ['mail.from.address', 'hello@EXAMPLE.COM'],
            'mixed case example org' => ['mail.from.address', 'hello@Example.Org'],
            'uppercase example net' => ['mail.from.address', 'hello@EXAMPLE.NET'],
            'header injection' => ['mail.from.name', "Horus\r\nBcc: other@example.invalid"],
            'missing connection' => ['matricula-mail.connection', 'missing-connection'],
            'deferred connection' => ['matricula-mail.connection', 'deferred'],
            'background connection' => ['matricula-mail.connection', 'background'],
            'failover connection' => ['matricula-mail.connection', 'failover'],
        ];
    }

    #[DataProvider('terminalStatuses')]
    public function test_terminal_status_cannot_be_dispatched_or_sent_again(string $status): void
    {
        $this->enrollment();
        $row = MatriculaNotification::sole();
        $row->update(['status' => $status]);
        $this->assertSame(0, $this->service()->dispatchDue());
        (new SendMatriculaWelcome($row->id))->handle($this->service());
        $this->assertSame($status, $row->fresh()->status);
        $this->assertSame(0, $row->fresh()->attempts);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    public static function terminalStatuses(): array
    {
        return array_map(fn ($status) => [$status], ['accepted', 'failed', 'uncertain', 'skipped']);
    }

    public function test_uncertain_attempt_followed_by_rejection_requires_review(): void
    {
        $this->enrollment();
        $this->fakeResend(['api.resend.com/*' => Http::sequence()
            ->push(['message' => 'PRIVATE-PROVIDER-DETAIL'], 503)
            ->push(['message' => 'PRIVATE-PROVIDER-DETAIL'], 403)]);
        $id = MatriculaNotification::sole()->id;
        Log::spy();

        $this->service()->send($id);
        $this->travel(60)->seconds();
        $this->service()->send($id);
        $this->service()->send($id);

        $row = MatriculaNotification::sole();
        $this->assertSame('uncertain', $row->status);
        $this->assertTrue($row->uncertain);
        $this->assertSame(2, $row->attempts);
        $this->assertSame('provider_http_403', $row->last_error);
        $this->assertNull($row->payload);
        $this->assertNull($row->lease);
        Http::assertSentCount(2);
        Log::shouldHaveReceived('info')->with('matricula.mail', ['outcome' => 'retry'])->once();
        Log::shouldHaveReceived('info')->with('matricula.mail', ['outcome' => 'uncertain'])->once();
    }

    public function test_backoff_and_attempt_limit_survive_repeated_jobs(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->enrollment();
        $this->fakeResend(['api.resend.com/*' => Http::response([], 503)]);
        $id = MatriculaNotification::sole()->id;
        foreach ([60, 300, 900] as $attempt => $delay) {
            $this->service()->send($id);
            $this->assertSame($attempt + 1, MatriculaNotification::sole()->attempts);
            $this->assertTrue(now()->addSeconds($delay)->equalTo(MatriculaNotification::sole()->available_at));
            $this->travel($delay - 1)->seconds();
            $this->service()->send($id);
            Http::assertSentCount($attempt + 1);
            $this->travel(1)->seconds();
        }
        $this->service()->send($id);
        $this->travel(24)->hours();
        $this->service()->send($id);
        $this->assertSame(4, MatriculaNotification::sole()->attempts);
        $this->assertSame('uncertain', MatriculaNotification::sole()->status);
        $this->assertNull(MatriculaNotification::sole()->payload);
        Http::assertSentCount(4);
    }

    public function test_diagnostic_counts_all_states_without_writes_even_when_disabled(): void
    {
        $this->enrollment();
        $seed = MatriculaNotification::sole();
        $original = Matricula::findOrFail($seed->matricula_id);
        $original->update(['estado' => 'anulada']);
        foreach (['sending', 'retry', 'accepted', 'failed', 'uncertain', 'skipped'] as $status) {
            $next = Matricula::create(['estudiante_id' => $original->estudiante_id, 'curso_id' => $original->curso_id,
                'fecha_matricula' => today(), 'estado' => 'pendiente']);
            MatriculaNotification::where('matricula_id', $next->id)->update(['status' => $status]);
            $next->update(['estado' => 'anulada']);
        }
        config(['matricula-mail.enabled' => false]);
        $before = DB::table('matricula_notifications')->orderBy('id')->get()->toJson();
        $this->withoutMockingConsoleOutput();
        $this->assertSame(0, $this->artisan('horus:matriculas-notificar', ['--status' => true]));
        $output = Artisan::output();
        foreach (['pending', 'sending', 'retry', 'accepted', 'failed', 'uncertain', 'skipped'] as $status) {
            $this->assertStringContainsString($status.': 1', $output);
        }
        $this->assertStringNotContainsString('student@example.invalid', $output);
        $this->assertStringNotContainsString('fake-key-not-real', $output);
        $this->assertSame($before, DB::table('matricula_notifications')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    public function test_scheduler_registers_dispatch_every_minute(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'horus:matriculas-notificar'));
        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events->first()->expression);
        Http::assertNothingSent();
    }

    public function test_conflict_retries_same_request_and_rate_limit_delay_is_capped(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->enrollment();
        $this->fakeResend(['api.resend.com/*' => Http::sequence()
            ->push(['name' => 'concurrent_idempotent_requests'], 409)
            ->push([], 429, ['Retry-After' => '999999'])
            ->push(['id' => 'accepted-same-request'])]);
        $id = MatriculaNotification::sole()->id;
        $this->service()->send($id);
        $this->assertTrue(MatriculaNotification::sole()->uncertain);
        $this->travel(60)->seconds();
        $this->service()->send($id);
        $this->assertTrue(now()->addHour()->equalTo(MatriculaNotification::sole()->available_at));
        $this->travel(3600)->seconds();
        $this->service()->send($id);
        $requests = Http::recorded();
        $this->assertCount(3, $requests);
        foreach ($requests as [$request]) {
            $this->assertSame($requests[0][0]->data(), $request->data());
            $this->assertSame($requests[0][0]->header('Idempotency-Key'), $request->header('Idempotency-Key'));
        }
        $this->assertSame('accepted', MatriculaNotification::sole()->status);
    }

    public function test_dispatch_command_uses_selected_queue_and_job_contains_no_personal_payload(): void
    {
        $this->enrollment();
        config(['matricula-mail.queue' => 'welcome-test']);
        $this->artisan('horus:matriculas-notificar', ['--limit' => '0'])->assertFailed();
        $this->artisan('horus:matriculas-notificar', ['--limit' => '1001'])->assertFailed();
        $this->assertDatabaseCount('jobs', 0);
        $this->artisan('horus:matriculas-notificar', ['--limit' => '1'])
            ->expectsOutput('Notificaciones encoladas: 1')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 1);
        $job = DB::table('jobs')->sole();
        $this->assertSame('welcome-test', $job->queue);
        foreach (['student@example.invalid', 'Ana', 'fake-key-not-real', 'PRIVATE-NOTE', 'Curso de prueba'] as $private) {
            $this->assertStringNotContainsString($private, $job->payload);
        }
        Http::assertNothingSent();
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'welcome-test', '--once' => true,
            '--stop-when-empty' => true])->assertSuccessful();
        $this->assertSame('accepted', MatriculaNotification::sole()->status);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertSentCount(1);
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
