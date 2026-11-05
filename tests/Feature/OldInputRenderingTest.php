<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AprobacionInterna;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\ProcesoInterno;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Un campo escalar enviado como array (campo[]=x) lo rechaza la validación; al volver al formulario,
 * old() devuelve ese array y la vista no debe imprimirlo (error 500) ni reinterpretarlo como un valor
 * válido. Cada formulario se somete con cada campo malformado y se exige: redirección con el error de
 * validación de ese campo y formulario renderizado con 200. Datos ficticios.
 */
class OldInputRenderingTest extends TestCase
{
    use RefreshDatabase;

    private const MALFORMED = ['x'];

    /** @return iterable<string, array{string}> */
    public static function estudianteFields(): iterable
    {
        foreach (['dni', 'estado', 'nombres', 'apellidos', 'email', 'telefono', 'fecha_nacimiento', 'direccion'] as $f) {
            yield $f => [$f];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function cursoFields(): iterable
    {
        foreach (['codigo', 'nombre', 'categoria', 'modalidad', 'descripcion', 'duracion_horas', 'precio', 'horario', 'cupos_maximos', 'estado'] as $f) {
            yield $f => [$f];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function procesoFields(): iterable
    {
        foreach (['titulo', 'descripcion', 'responsable_id', 'estado', 'prioridad', 'fecha_vencimiento', 'observaciones'] as $f) {
            yield $f => [$f];
        }
    }

    #[DataProvider('estudianteFields')]
    public function test_estudiante_forms_survive_array_input(string $campo): void
    {
        $existente = $this->estudiante('70000001');
        $this->actingAs($this->adminUser());

        $this->assertSurvives('post', route('admin.estudiantes.store'), route('admin.estudiantes.create'), $this->estudianteData(), $campo);
        $this->assertSurvives('put', route('admin.estudiantes.update', $existente), route('admin.estudiantes.edit', $existente), $this->estudianteData(), $campo);
    }

    #[DataProvider('cursoFields')]
    public function test_curso_forms_survive_array_input(string $campo): void
    {
        $existente = $this->curso('CUR-EXI');
        $this->actingAs($this->adminUser());

        $this->assertSurvives('post', route('admin.cursos.store'), route('admin.cursos.create'), $this->cursoData(), $campo);
        $this->assertSurvives('put', route('admin.cursos.update', $existente), route('admin.cursos.edit', $existente), $this->cursoData(), $campo);
    }

    #[DataProvider('procesoFields')]
    public function test_proceso_forms_survive_array_input(string $campo): void
    {
        $admin = $this->adminUser();
        $existente = ProcesoInterno::create(['titulo' => 'Proceso ficticio', 'estado' => 'pendiente', 'prioridad' => 'media']);
        $this->actingAs($admin);

        $datos = $this->procesoData($admin);
        $this->assertSurvives('post', route('admin.procesos.store'), route('admin.procesos.create'), $datos, $campo);
        $this->assertSurvives('put', route('admin.procesos.update', $existente), route('admin.procesos.edit', $existente), $datos, $campo);

        // Ningún array se convirtió en un valor válido: el proceso existente sigue intacto.
        $this->assertSame(1, ProcesoInterno::count());
        $this->assertNull($existente->fresh()->responsable_id);
    }

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

        $datos = ['name' => 'Usuario Ficticio', 'email' => 'ficticio@example.invalid', 'password' => 'ClaveSegura123', 'password_confirmation' => 'ClaveSegura123'];
        $this->assertSurvives('post', route('admin.usuarios.store'), route('admin.usuarios.create'), $datos, $campo);
        $this->assertSurvives('put', route('admin.usuarios.update', $otro), route('admin.usuarios.edit', $otro), $datos, $campo);
    }

    public function test_role_forms_survive_array_name(): void
    {
        $this->actingAs($this->adminUser());
        $rol = Role::create(['name' => 'rol-ficticio', 'guard_name' => 'web']);

        $this->assertSurvives('post', route('admin.roles.store'), route('admin.roles.index'), ['name' => 'otro-rol'], 'name');
        $this->assertSurvives('put', route('admin.roles.update', $rol), route('admin.roles.index'), ['name' => 'otro-rol'], 'name');
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

    /**
     * Valores normales, ceros y cadenas vacías se vuelven a mostrar tal cual tras un error de validación.
     */
    public function test_normal_zero_and_empty_values_are_redisplayed(): void
    {
        $this->actingAs($this->adminUser());

        $this->from(route('admin.cursos.create'))
            ->post(route('admin.cursos.store'), $this->cursoData([
                'codigo' => 'ZERO-0', 'precio' => '0', 'horario' => '', 'descripcion' => 'Línea <b>1</b>', 'estado' => 'inexistente',
            ]))
            ->assertSessionHasErrors('estado');

        $this->get(route('admin.cursos.create'))
            ->assertOk()
            ->assertSee('value="ZERO-0"', false)
            ->assertSee('name="precio" type="number" min="0" step="0.01" value="0"', false)
            ->assertSee('name="horario" type="text" maxlength="100" value=""', false)
            ->assertSee('Línea &lt;b&gt;1&lt;/b&gt;', false); // el escape de Blade se conserva

        $this->from(route('admin.estudiantes.create'))
            ->post(route('admin.estudiantes.store'), $this->estudianteData(['nombres' => 'Ana', 'direccion' => '0', 'estado' => 'x']))
            ->assertSessionHasErrors('estado');
        $this->get(route('admin.estudiantes.create'))
            ->assertSee('value="Ana"', false)
            ->assertSee('value="0"', false);

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

    /** Un id válido sigue seleccionado; un array con ids existentes no. */
    public function test_proceso_responsable_is_reselected_only_for_a_scalar_id(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);

        $this->from(route('admin.procesos.create'))
            ->post(route('admin.procesos.store'), $this->procesoData($admin, ['responsable_id' => (string) $admin->id, 'estado' => 'x']))
            ->assertSessionHasErrors('estado');
        $this->get(route('admin.procesos.create'))
            ->assertSee('<option value="'.$admin->id.'" selected>', false);

        $this->from(route('admin.procesos.create'))
            ->post(route('admin.procesos.store'), $this->procesoData($admin, ['responsable_id' => [(string) $admin->id]]))
            ->assertSessionHasErrors('responsable_id');
        $this->get(route('admin.procesos.create'))
            ->assertOk()
            ->assertDontSee('<option value="'.$admin->id.'" selected>', false);
        $this->assertSame(0, ProcesoInterno::count());
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

    private function estudiante(string $dni): Estudiante
    {
        return Estudiante::create(['dni' => $dni, 'nombres' => 'Estudiante', 'apellidos' => 'Ficticio', 'estado' => 'activo']);
    }

    private function curso(string $codigo): Curso
    {
        return Curso::create($this->cursoData(['codigo' => $codigo, 'cupos_disponibles' => 5]));
    }

    private function estudianteData(array $extra = []): array
    {
        return array_replace([
            'dni' => '71234567', 'nombres' => 'Ana', 'apellidos' => 'Ficticia', 'email' => 'ana@example.invalid',
            'telefono' => '999111222', 'fecha_nacimiento' => '2000-01-15', 'direccion' => 'Calle 1', 'estado' => 'activo',
        ], $extra);
    }

    private function cursoData(array $extra = []): array
    {
        return array_replace([
            'codigo' => 'CUR-NEW', 'nombre' => 'Curso ficticio', 'descripcion' => 'Descripción.', 'categoria' => 'tecnologia',
            'duracion_horas' => 20, 'precio' => 150, 'modalidad' => 'virtual', 'horario' => 'Lunes', 'cupos_maximos' => 5,
            'estado' => 'activo',
        ], $extra);
    }

    private function procesoData(User $responsable, array $extra = []): array
    {
        return array_replace([
            'titulo' => 'Proceso nuevo', 'descripcion' => 'Detalle', 'responsable_id' => (string) $responsable->id,
            'estado' => 'pendiente', 'prioridad' => 'media', 'fecha_vencimiento' => '2026-12-31', 'observaciones' => 'Notas',
        ], $extra);
    }
}
