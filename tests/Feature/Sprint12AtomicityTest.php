<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AprobacionInterna;
use App\Models\AuditLog;
use App\Models\NotificacionInterna;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Resend\Laravel\Events\EmailDelivered;
use RuntimeException;
use Tests\TestCase;

class Sprint12AtomicityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        config(['matricula-mail.enabled' => false, 'services.openai.api_key' => null]);
        Http::preventStrayRequests();
    }

    public function test_user_creation_rolls_back_account_and_grants_when_audit_fails(): void
    {
        $this->actingAs($this->adminUser());
        $before = DB::table('model_has_roles')->count();
        $this->failAudit();
        $this->postJson(route('admin.usuarios.store'), ['name' => 'Prueba', 'email' => 'nueva@example.invalid',
            'password' => 'NuevaPrueba123', 'password_confirmation' => 'NuevaPrueba123', 'roles' => ['secretaria']])
            ->assertStatus(500);
        $this->assertDatabaseMissing('users', ['email' => 'nueva@example.invalid']);
        $this->assertSame($before, DB::table('model_has_roles')->count());
    }

    public function test_role_creation_rolls_back_role_and_permissions_when_audit_fails(): void
    {
        $this->actingAs($this->adminUser());
        $before = DB::table('role_has_permissions')->count();
        $this->failAudit();
        $this->postJson(route('admin.roles.store'), ['name' => 'rol-prueba', 'permissions' => ['estudiantes.ver']])
            ->assertStatus(500);
        $this->assertDatabaseMissing('roles', ['name' => 'rol-prueba']);
        $this->assertSame($before, DB::table('role_has_permissions')->count());
    }

    public function test_settings_roll_back_all_changes_when_audit_fails(): void
    {
        $this->actingAs($this->adminUser());
        SystemSetting::create(['key' => 'moneda', 'value' => 'S/']);
        $this->failAudit();
        $this->putJson(route('admin.configuracion.update'), ['settings' => ['moneda' => 'USD', 'telefono' => '000000000']])
            ->assertStatus(500);
        $this->assertSame('S/', SystemSetting::value('moneda'));
        $this->assertDatabaseMissing('system_settings', ['key' => 'telefono']);
    }

    public function test_approval_creation_rolls_back_when_notification_cannot_be_recorded(): void
    {
        $actor = $this->adminUser();
        $this->actingAs($actor);
        $this->failNotification();
        $this->postJson(route('admin.aprobaciones.store'), ['titulo' => 'Ficticia', 'tipo' => 'compra',
            'aprobador_id' => $actor->id, 'estado' => 'pendiente'])->assertStatus(500);
        $this->assertDatabaseCount('aprobaciones_internas', 0);
        $this->assertDatabaseCount('notificaciones_internas', 0);
    }

    public function test_approval_state_rolls_back_when_notification_cannot_be_recorded(): void
    {
        $actor = $this->adminUser();
        $this->actingAs($actor);
        $approval = AprobacionInterna::create(['titulo' => 'Ficticia', 'tipo' => 'compra',
            'solicitante_id' => $actor->id, 'estado' => 'pendiente']);
        $this->failNotification();
        $this->putJson(route('admin.aprobaciones.update', $approval), ['titulo' => 'Ficticia', 'tipo' => 'compra',
            'estado' => 'aprobado'])->assertStatus(500);
        $this->assertSame('pendiente', $approval->fresh()->estado);
        $this->assertDatabaseCount('notificaciones_internas', 0);
    }

    public function test_unused_resend_webhook_is_not_exposed_without_verification(): void
    {
        config(['resend.webhook.secret' => null]);
        Event::fake([EmailDelivered::class]);
        $this->postJson('/'.trim(config('resend.path', 'resend'), '/').'/webhook',
            ['type' => 'email.delivered', 'data' => ['email_id' => 'fake-provider-id']])->assertNotFound();
        Event::assertNotDispatched(EmailDelivered::class);
        Http::assertNothingSent();
    }

    public function test_api_registration_rolls_back_account_if_token_issuance_fails(): void
    {
        config(['jwt.secret' => 'too-short']);
        $this->postJson(route('api.auth.register'), ['name' => 'Prueba', 'email' => 'nueva@example.invalid',
            'password' => 'NuevaPrueba123', 'password_confirmation' => 'NuevaPrueba123'])->assertStatus(500);
        $this->assertDatabaseMissing('users', ['email' => 'nueva@example.invalid']);
    }

    public function test_console_provisioning_rolls_back_account_if_role_assignment_fails(): void
    {
        $this->adminUser();
        DB::connection()->beforeExecuting(function (string $query): void {
            if (str_contains($query, 'insert into "model_has_roles"')) {
                throw new RuntimeException('Fallo de asignación simulado');
            }
        });
        try {
            $this->artisan('horus:crear-usuario', ['--name' => 'Prueba', '--email' => 'nueva@example.invalid', '--rol' => 'secretaria'])
                ->expectsQuestion('Contraseña (no se mostrará)', 'NuevaPrueba123!')
                ->expectsQuestion('Repite la contraseña', 'NuevaPrueba123!')->run();
            $this->fail('Se esperaba un fallo al asignar el rol.');
        } catch (RuntimeException $error) {
            $this->assertSame('Fallo de asignación simulado', $error->getMessage());
        }
        $this->assertDatabaseMissing('users', ['email' => 'nueva@example.invalid']);
    }

    private function failAudit(): void
    {
        AuditLog::creating(function (): void {
            throw new RuntimeException('Fallo de auditoría simulado');
        });
    }

    private function failNotification(): void
    {
        NotificacionInterna::creating(function (): void {
            throw new RuntimeException('Fallo de notificación interna simulado');
        });
    }
}
