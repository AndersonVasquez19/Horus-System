<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\JwtTokenService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Perfil propio del panel: cambiar correo o contraseña exige la contraseña actual. */
class PerfilPropioTest extends TestCase
{
    use RefreshDatabase;

    private const CLAVE = 'ClaveActual12345';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function staff(string $rol = 'secretaria'): User
    {
        $user = User::factory()->create(['password' => Hash::make(self::CLAVE)]);
        $user->assignRole($rol);

        return $user;
    }

    public function test_guests_cannot_see_or_update_the_profile(): void
    {
        $this->get(route('admin.perfil.edit'))->assertRedirect(route('login'));
        $this->put(route('admin.perfil.update'), ['name' => 'X', 'email' => 'x@example.invalid'])
            ->assertRedirect(route('login'));
    }

    public function test_a_user_without_a_panel_role_cannot_use_the_profile(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.perfil.edit'))->assertForbidden();
    }

    public function test_any_staff_role_can_open_the_profile_without_extra_permissions(): void
    {
        foreach (['admin', 'coordinador', 'secretaria'] as $rol) {
            $this->actingAs($this->staff($rol))->get(route('admin.perfil.edit'))->assertOk()->assertSee('Mi perfil');
        }
    }

    public function test_the_name_can_change_without_the_current_password(): void
    {
        $user = $this->staff();
        $antes = $user->password;

        $this->actingAs($user)->put(route('admin.perfil.update'), ['name' => 'Nombre nuevo', 'email' => $user->email])
            ->assertRedirect(route('admin.perfil.edit'))->assertSessionHasNoErrors();

        $this->assertSame('Nombre nuevo', $user->fresh()->name);
        $this->assertSame($antes, $user->fresh()->password);
    }

    public function test_changing_the_email_requires_the_current_password(): void
    {
        $user = $this->staff();

        $this->actingAs($user)->put(route('admin.perfil.update'), ['name' => $user->name, 'email' => 'nuevo@example.invalid'])
            ->assertSessionHasErrors('current_password');
        $this->actingAs($user)->put(route('admin.perfil.update'), [
            'name' => $user->name, 'email' => 'nuevo@example.invalid', 'current_password' => 'incorrecta',
        ])->assertSessionHasErrors('current_password');

        $this->assertNotSame('nuevo@example.invalid', $user->fresh()->email);
    }

    public function test_changing_the_email_with_the_right_password_revokes_existing_jwt(): void
    {
        $user = $this->staff();
        $token = app(JwtTokenService::class)->issue($user)['access_token'];

        $this->actingAs($user)->put(route('admin.perfil.update'), [
            'name' => $user->name, 'email' => '  Nuevo@Example.INVALID ', 'current_password' => self::CLAVE,
        ])->assertSessionHasNoErrors();

        $this->assertSame('nuevo@example.invalid', $user->fresh()->email);
        $this->getJson(route('api.auth.me'), ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
    }

    public function test_changing_the_password_requires_the_current_one_and_the_policy(): void
    {
        $user = $this->staff();
        $hash = $user->password;
        $base = ['name' => $user->name, 'email' => $user->email];

        $this->actingAs($user)->put(route('admin.perfil.update'), $base + [
            'password' => 'NuevaClave12345', 'password_confirmation' => 'NuevaClave12345',
        ])->assertSessionHasErrors('current_password');

        $this->actingAs($user)->put(route('admin.perfil.update'), $base + [
            'password' => 'corta', 'password_confirmation' => 'corta', 'current_password' => self::CLAVE,
        ])->assertSessionHasErrors('password');

        $this->actingAs($user)->put(route('admin.perfil.update'), $base + [
            'password' => 'NuevaClave12345', 'password_confirmation' => 'Distinta12345', 'current_password' => self::CLAVE,
        ])->assertSessionHasErrors('password');

        $this->assertSame($hash, $user->fresh()->password);
    }

    public function test_changing_the_password_revokes_jwt_and_keeps_the_current_session(): void
    {
        $user = $this->staff();
        $version = (int) $user->jwt_version;
        $token = app(JwtTokenService::class)->issue($user)['access_token'];

        $this->actingAs($user)->put(route('admin.perfil.update'), [
            'name' => $user->name, 'email' => $user->email, 'current_password' => self::CLAVE,
            'password' => 'NuevaClave12345', 'password_confirmation' => 'NuevaClave12345',
        ])->assertRedirect(route('admin.perfil.edit'))->assertSessionHasNoErrors();

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('NuevaClave12345', $fresh->password));
        $this->assertSame($version + 1, (int) $fresh->jwt_version);
        $this->getJson(route('api.auth.me'), ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
        $this->assertAuthenticatedAs($fresh);
    }

    public function test_the_target_is_always_the_authenticated_user_and_roles_cannot_be_changed(): void
    {
        $actor = $this->staff('secretaria');
        $otro = $this->staff('coordinador');
        $emailOtro = $otro->email;

        $this->actingAs($actor)->put(route('admin.perfil.update'), [
            'name' => 'Propio', 'email' => $actor->email,
            'id' => $otro->id, 'user' => $otro->id, 'roles' => ['admin'], 'jwt_version' => 99,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Propio', $actor->fresh()->name);
        $this->assertSame($emailOtro, $otro->fresh()->email);
        $this->assertNotSame('Propio', $otro->fresh()->name);
        $this->assertTrue($actor->fresh()->hasRole('secretaria'));
        $this->assertFalse($actor->fresh()->hasRole('admin'));
        $this->assertNotSame(99, (int) $actor->fresh()->jwt_version);
    }

    public function test_an_email_already_in_use_is_rejected(): void
    {
        $user = $this->staff();
        $otro = $this->staff('coordinador');

        $this->actingAs($user)->put(route('admin.perfil.update'), [
            'name' => $user->name, 'email' => $otro->email, 'current_password' => self::CLAVE,
        ])->assertSessionHasErrors('email');
    }

    public function test_the_audit_log_never_stores_passwords(): void
    {
        $user = $this->staff();

        $this->actingAs($user)->put(route('admin.perfil.update'), [
            'name' => $user->name, 'email' => $user->email, 'current_password' => self::CLAVE,
            'password' => 'NuevaClave12345', 'password_confirmation' => 'NuevaClave12345',
        ])->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'perfil_actualizado')->firstOrFail();
        $this->assertSame($user->id, $log->user_id);
        $this->assertTrue($log->changes['clave_modificada']);
        $serializado = json_encode($log->changes);
        $this->assertStringNotContainsString('NuevaClave12345', $serializado);
        $this->assertStringNotContainsString(self::CLAVE, $serializado);
        $this->assertStringNotContainsString($user->fresh()->password, $serializado);
    }

    public function test_wrong_current_password_attempts_are_rate_limited(): void
    {
        $user = $this->staff();
        $payload = ['name' => $user->name, 'email' => 'otro@example.invalid', 'current_password' => 'incorrecta'];

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->put(route('admin.perfil.update'), $payload)->assertSessionHasErrors('current_password');
        }

        $this->actingAs($user)->put(route('admin.perfil.update'), $payload)->assertTooManyRequests();
    }
}
