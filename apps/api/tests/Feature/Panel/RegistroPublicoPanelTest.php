<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/** Registro público desde apps/web: crea la cuenta sin roles ni acceso al panel. Datos ficticios. */
final class RegistroPublicoPanelTest extends PanelApiTestCase
{
    /** @return array<string, mixed> */
    private function datos(array $cambios = []): array
    {
        return $cambios + ['name' => 'Persona Ficticia', 'email' => 'Nueva@Example.test ', 'password' => 'ClaveSegura123', 'password_confirmation' => 'ClaveSegura123', 'terms' => true];
    }

    private function registrar(array $cambios = [], array $cabeceras = []): \Illuminate\Testing\TestResponse
    {
        $this->exigirCsrf();

        return $this->panel('POST', 'auth/registro', $this->datos($cambios), ['X-CSRF-TOKEN' => $this->tokenCsrf()] + $cabeceras);
    }

    public function test_crea_la_cuenta_sin_roles_ni_permisos_y_sin_devolver_datos(): void
    {
        $respuesta = $this->registrar()->assertCreated();

        $this->assertSame(['message'], array_keys($respuesta->json()), 'La respuesta no lleva token, usuario ni identificadores.');
        $usuario = User::where('email', 'nueva@example.test')->sole();
        $this->assertTrue(Hash::check('ClaveSegura123', $usuario->password));
        $this->assertCount(0, $usuario->getRoleNames());
        $this->assertCount(0, $usuario->getAllPermissions());
    }

    public function test_la_cuenta_registrada_no_puede_entrar_al_panel(): void
    {
        $this->registrar()->assertCreated();

        // Misma respuesta que una credencial inválida: no revela que la cuenta existe pero no tiene acceso.
        $this->panel('POST', 'auth/login', ['email' => 'nueva@example.test', 'password' => 'ClaveSegura123'], ['X-CSRF-TOKEN' => $this->tokenCsrf()])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'Las credenciales ingresadas no son válidas.');
        $this->panel('GET', 'auth/sesion')->assertUnauthorized();
    }

    public function test_exige_csrf_terminos_y_contrasena_valida(): void
    {
        $this->exigirCsrf();
        $this->panel('POST', 'auth/registro', $this->datos())->assertStatus(419);

        $this->registrar(['terms' => false])->assertUnprocessable()->assertJsonValidationErrors('terms');
        $this->registrar(['password' => 'corta', 'password_confirmation' => 'corta'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->registrar(['password_confirmation' => 'otra'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->registrar(['email' => 'no-es-correo'])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_un_correo_ya_registrado_recibe_la_misma_respuesta_y_no_se_toca_la_cuenta(): void
    {
        $existente = User::factory()->create(['email' => 'ya@example.test', 'name' => 'Titular', 'password' => Hash::make('ClaveOriginal123')]);
        $nueva = $this->registrar(['email' => 'otra@example.test'])->assertCreated();
        $repetida = $this->registrar(['email' => 'YA@example.test', 'name' => 'Intruso', 'password' => 'OtraClave12345', 'password_confirmation' => 'OtraClave12345'])->assertCreated();

        $this->assertSame($nueva->json(), $repetida->json(), 'Misma respuesta exista o no la cuenta.');
        $this->assertSame(1, User::where('email', 'ya@example.test')->count());
        $existente->refresh();
        $this->assertSame('Titular', $existente->name);
        $this->assertTrue(Hash::check('ClaveOriginal123', $existente->password), 'El registro repetido no cambia la contraseña.');
    }

    public function test_el_limite_de_registro_se_aplica_por_visitante(): void
    {
        $this->exigirCsrf();
        for ($i = 0; $i < 5; $i++) {
            $this->registrar(['email' => "persona{$i}@example.test"])->assertCreated();
        }
        $this->registrar(['email' => 'persona9@example.test'])->assertStatus(429);
    }
}
