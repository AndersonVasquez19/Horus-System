<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AprobacionInterna;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Support\LlamaRolesDelPanel;
use Tests\Support\LlamaUsuariosDelPanel;
use Tests\TestCase;

/**
 * Un campo escalar enviado como array (campo[]=x) lo rechaza la validación; al volver al formulario,
 * old() devuelve ese array y la vista no debe imprimirlo (error 500) ni reinterpretarlo como un valor
 * válido. Cada formulario se somete con cada campo malformado y se exige: redirección con el error de
 * validación de ese campo y formulario renderizado con 200. Datos ficticios.
 */
class OldInputRenderingTest extends TestCase
{
    use LlamaRolesDelPanel;
    use LlamaUsuariosDelPanel;
    use RefreshDatabase;

    private const MALFORMED = ['x'];

    /** @return iterable<string, array{string}> */
    public static function userFields(): iterable
    {
        foreach (['name', 'email'] as $f) {
            yield $f => [$f];
        }
    }

    #[DataProvider('userFields')]
    public function test_usuario_forms_survive_array_input(string $campo): void
    {
        $admin = $this->adminUser();
        $otro = User::factory()->create();
        $this->actingAs($admin);

        // Los usuarios los sirve el panel: un campo con forma de arreglo es un 422 limpio, no un 500, y no cambia nada.
        $datos = ['name' => 'Usuario Ficticio', 'email' => 'ficticio@example.invalid', 'password' => 'ClaveSegura123', 'password_confirmation' => 'ClaveSegura123'];
        $datos[$campo] = self::MALFORMED;
        $this->crearUsuarioPanel($datos)->assertUnprocessable()->assertJsonValidationErrors($campo);
        $this->actualizarUsuarioPanel($otro, $datos)->assertUnprocessable()->assertJsonValidationErrors($campo);
        $this->assertDatabaseMissing('users', ['email' => 'ficticio@example.invalid']);
    }

    public function test_role_forms_survive_array_name(): void
    {
        $this->actingAs($this->adminUser());
        $rol = Role::create(['name' => 'rol-ficticio', 'guard_name' => 'web']);

        // Los roles los sirve el panel: un nombre con forma de arreglo es un 422 limpio, no un 500, y no cambia nada.
        $this->crearRolPanel(['name' => self::MALFORMED])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actualizarRolPanel($rol, ['name' => self::MALFORMED])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame('rol-ficticio', $rol->fresh()->name);
    }

    /** @return iterable<string, array{string}> */
    public static function settingFields(): iterable
    {
        foreach (['nombre_empresa', 'telefono', 'correo_contacto', 'moneda', 'direccion'] as $f) {
            yield $f => [$f];
        }
    }

    #[DataProvider('settingFields')]
    public function test_configuracion_form_survives_array_input(string $campo): void
    {
        $this->actingAs($this->adminUser());
        $datos = ['settings' => ['nombre_empresa' => 'Empresa', 'telefono' => '999', 'correo_contacto' => 'a@example.invalid', 'moneda' => 'PEN', 'direccion' => 'Calle']];
        $datos['settings'][$campo] = self::MALFORMED;

        $this->from(route('admin.configuracion.index'))
            ->put(route('admin.configuracion.update'), $datos)
            ->assertRedirect(route('admin.configuracion.index'))
            ->assertSessionHasErrors("settings.$campo");

        $this->get(route('admin.configuracion.index'))->assertOk();
    }

    public function test_register_form_survives_array_input(): void
    {
        foreach (['name', 'email'] as $campo) {
            $this->assertSurvives('post', route('register.post'), route('register'), [
                'name' => 'Cuenta Ficticia', 'email' => 'cuenta@example.invalid',
                'password' => 'ClaveSegura123', 'password_confirmation' => 'ClaveSegura123', 'terms' => '1',
            ], $campo);
        }
        $this->assertSame(0, User::count());
    }

    public function test_login_form_survives_array_email(): void
    {
        $this->assertSurvives('post', route('login.post'), route('login'), ['email' => 'a@example.invalid', 'password' => 'x'], 'email');
        $this->assertGuest();
    }

    public function test_forgot_password_form_survives_array_email(): void
    {
        $this->assertSurvives('post', route('password.email'), route('password.request'), ['email' => 'a@example.invalid'], 'email');
    }

    public function test_reset_password_form_survives_array_input(): void
    {
        $url = route('password.reset', ['token' => 'token-ficticio']);
        $this->assertSurvives('post', route('password.store'), $url, [
            'token' => 'token-ficticio', 'email' => 'a@example.invalid',
            'password' => 'ClaveSegura123', 'password_confirmation' => 'ClaveSegura123',
        ], 'email');

        // El correo también puede llegar como array en la propia URL del enlace.
        $this->get($url.'?email[]=x')->assertOk();
    }

    public function test_valid_old_email_is_redisplayed_on_login_and_registration(): void
    {
        $this->from(route('login'))
            ->post(route('login.post'), ['email' => 'No-Es-Correo', 'password' => 'x'])
            ->assertSessionHasErrors('email');
        $this->get(route('login'))->assertSee('value="no-es-correo"', false);

        $this->from(route('register'))
            ->post(route('register.post'), ['name' => 'Ana <b>', 'email' => 'ana@example.invalid', 'password' => 'corta'])
            ->assertSessionHasErrors('password');
        $this->get(route('register'))
            ->assertSee('value="Ana &lt;b&gt;"', false)
            ->assertSee('value="ana@example.invalid"', false);
    }

    /**
     * `exists` acepta un array de ids existentes: sin la regla `integer`, solicitante_id[]=<id> pasaba la
     * validación y el intento de guardar el array terminaba en error 500.
     */
    #[DataProvider('approvalUserFields')]
    public function test_approval_user_ids_reject_arrays_of_existing_ids(string $campo): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);
        $datos = ['titulo' => 'Solicitud', 'tipo' => 'compra', 'estado' => 'pendiente', $campo => [(string) $admin->id]];

        $this->post(route('admin.aprobaciones.store'), $datos)->assertSessionHasErrors($campo);
        $this->assertSame(0, AprobacionInterna::count());

        $datos[$campo] = (string) $admin->id;
        $this->post(route('admin.aprobaciones.store'), $datos)->assertSessionHasNoErrors();
        $this->assertSame(1, AprobacionInterna::count());

        $this->put(route('admin.aprobaciones.update', AprobacionInterna::firstOrFail()), [$campo => [(string) $admin->id]] + $datos)
            ->assertSessionHasErrors($campo);
    }

    /** @return iterable<string, array{string}> */
    public static function approvalUserFields(): iterable
    {
        yield 'solicitante_id' => ['solicitante_id'];
        yield 'aprobador_id' => ['aprobador_id'];
    }

    private function assertSurvives(string $metodo, string $enviar, string $formulario, array $datos, string $campo): void
    {
        $datos[$campo] = self::MALFORMED;

        $this->from($formulario)
            ->{$metodo}($enviar, $datos)
            ->assertRedirect($formulario)
            ->assertSessionHasErrors($campo);

        $this->get($formulario)->assertOk();
    }
}
