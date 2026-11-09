<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\CursoPublicacion;
use App\Models\SolicitudMatricula;
use App\Models\User;
use App\Services\GestionSolicitudesMatricula;
use App\Support\FormularioSolicitudMatricula;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SolicitudMatriculaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Curso $curso;

    private CursoPublicacion $publicacion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        Http::preventStrayRequests();
        Mail::fake();
        config(['catalogo-publico.enabled' => true]);
        $this->admin = $this->adminUser();
        $this->curso = Curso::create([
            'codigo' => 'SOL-TEST', 'nombre' => 'Nombre administrativo privado', 'descripcion' => 'Descripción interna',
            'categoria' => 'tecnologia', 'duracion_horas' => 20, 'precio' => 9876.54, 'modalidad' => 'virtual',
            'horario' => 'Interno', 'cupos_maximos' => 10, 'cupos_disponibles' => 10, 'estado' => 'activo',
        ]);
        $this->publicacion = CursoPublicacion::create([
            'curso_id' => $this->curso->id, 'titulo' => 'Curso público de redes', 'resumen' => 'Contenido autorizado.',
            'modalidad' => 'virtual', 'vigente_desde' => now('America/Lima')->toDateString(),
            'vigente_hasta' => now('America/Lima')->addMonth()->toDateString(), 'publicada' => true,
            'autorizado_por' => $this->admin->id, 'autorizado_at' => now(),
        ]);
    }

    public function test_form_and_catalog_offer_only_editorial_data_and_preselect_course(): void
    {
        $referencia = $this->referencia();
        $this->get(route('educacion.cursos'))->assertOk()
            ->assertSee(route('matricula-publica.create', ['curso' => $referencia]), false);
        $this->get(route('matricula-publica.create', ['curso' => $referencia]))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Curso público de redes')->assertSee('name="marca"', false)
            ->assertSee('value="'.$referencia.'" selected', false)
            ->assertDontSee('Nombre administrativo privado')->assertDontSee('9876.54');
        $this->get(route('matricula-publica.create', ['curso' => ['manipulado']]))->assertOk();
    }

    public function test_public_request_is_encrypted_and_does_not_enroll_or_send_mail(): void
    {
        $datos = $this->datos();
        $this->post(route('matricula-publica.store'), $datos)->assertRedirect(route('matricula-publica.create'))
            ->assertSessionHas('solicitud_matricula_recibida');
        $solicitud = SolicitudMatricula::sole();
        $this->assertSame('pendiente', $solicitud->estado);
        $this->assertSame('persona@example.test', $solicitud->email);
        $this->assertSame('Curso público de redes', $solicitud->curso_titulo);
        foreach (['nombres', 'apellidos', 'email', 'telefono', 'mensaje'] as $campo) {
            $cifrado = DB::table('solicitudes_matricula')->value($campo);
            $this->assertNotSame($datos[$campo], $cifrado);
            $this->assertSame($solicitud->$campo, Crypt::decryptString($cifrado));
            $this->assertArrayNotHasKey($campo, $solicitud->toArray());
        }
        $this->assertDatabaseCount('matriculas', 0);
        $this->assertDatabaseCount('estudiantes', 0);
        $this->assertSame(10, $this->curso->fresh()->cupos_disponibles);
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_repeated_submission_is_idempotent_and_changed_payload_is_rejected(): void
    {
        $datos = $this->datos();
        $this->post(route('matricula-publica.store'), $datos)->assertRedirect();
        $this->post(route('matricula-publica.store'), array_reverse($datos, true))->assertRedirect();
        $this->assertDatabaseCount('solicitudes_matricula', 1);
        $this->postJson(route('matricula-publica.store'), [...$datos, 'nombres' => 'Otra persona'])->assertUnprocessable()->assertJsonValidationErrors('marca');
        $this->assertDatabaseCount('solicitudes_matricula', 1);
    }

    public static function invalido(): iterable
    {
        yield 'nombre array' => ['nombres', ['texto']];
        yield 'correo incorrecto' => ['email', 'sin-correo'];
        yield 'teléfono array' => ['telefono', ['999999999']];
        yield 'curso inventado' => ['curso', str_repeat('a', 64)];
        yield 'curso administrativo' => ['curso_id', 1];
        yield 'estado manipulado' => ['estado', 'pagada'];
        yield 'sin autorización' => ['consentimiento', '0'];
        yield 'honeypot' => ['sitio_web', 'spam'];
        yield 'marca array' => ['marca', ['token']];
        yield 'marca manipulada' => ['marca', 'no-firmada'];
        yield 'mensaje largo' => ['mensaje', str_repeat('x', 1001)];
    }

    #[DataProvider('invalido')]
    public function test_invalid_or_forged_submissions_never_write(string $campo, mixed $valor): void
    {
        $datos = [...$this->datos(), $campo => $valor];
        $this->postJson(route('matricula-publica.store'), $datos)->assertUnprocessable();
        $this->assertDatabaseCount('solicitudes_matricula', 0);
        Http::assertNothingSent();
    }

    public function test_too_fast_and_expired_forms_are_rejected(): void
    {
        $datos = $this->datos();
        $datos['marca'] = FormularioSolicitudMatricula::emitir();
        $this->postJson(route('matricula-publica.store'), $datos)->assertUnprocessable()->assertJsonValidationErrors('marca');
        $this->travel(7201)->seconds();
        $this->postJson(route('matricula-publica.store'), $datos)->assertUnprocessable()->assertJsonValidationErrors('marca');
        $this->assertDatabaseCount('solicitudes_matricula', 0);
    }

    public function test_withdrawn_or_changed_course_is_rechecked_on_submission(): void
    {
        $datos = $this->datos();
        $this->publicacion->update(['publicada' => false]);
        $this->postJson(route('matricula-publica.store'), $datos)->assertUnprocessable()->assertJsonValidationErrors('curso');
        $this->get(route('matricula-publica.create'))->assertOk()->assertDontSee('name="curso"', false);
        $this->publicacion->update(['publicada' => true, 'titulo' => 'Ficha modificada']);
        $this->postJson(route('matricula-publica.store'), $datos)->assertUnprocessable()->assertJsonValidationErrors('curso');
        $this->assertDatabaseCount('solicitudes_matricula', 0);
    }

    public static function noElegible(): iterable
    {
        yield 'sin publicación' => ['publicada', false];
        yield 'fecha futura' => ['vigente_desde', '+1 day'];
        yield 'fecha vencida' => ['vigente_hasta', '-1 day'];
        yield 'sin autor' => ['autorizado_por', null];
    }

    #[DataProvider('noElegible')]
    public function test_non_public_courses_are_not_options(string $campo, mixed $valor): void
    {
        if (str_starts_with($campo, 'vigente_')) {
            $valor = now('America/Lima')->modify($valor)->toDateString();
        }
        $this->publicacion->update([$campo => $valor]);
        $this->assertSame([], app(GestionSolicitudesMatricula::class)->cursos());
    }

    public function test_inactive_course_and_revoked_author_do_not_offer_requests(): void
    {
        $this->curso->update(['estado' => 'inactivo']);
        $this->assertSame([], app(GestionSolicitudesMatricula::class)->cursos());
        $this->curso->update(['estado' => 'activo']);
        $this->admin->removeRole('admin');
        $this->assertSame([], app(GestionSolicitudesMatricula::class)->cursos());
    }

    public function test_missing_schema_and_disabled_catalog_have_safe_unavailable_mode(): void
    {
        $datos = $this->datos();
        config(['catalogo-publico.enabled' => false]);
        $this->get(route('matricula-publica.create'))->assertOk()->assertDontSee('name="curso"', false);
        $this->postJson(route('matricula-publica.store'), $datos)->assertStatus(503);
        config(['catalogo-publico.enabled' => true]);
        Schema::drop('solicitudes_matricula');
        $this->get(route('matricula-publica.create'))->assertOk()->assertSee('no está disponible');
        $this->postJson(route('matricula-publica.store'), $datos)->assertStatus(503);
    }

    public function test_throttle_limits_public_posts_even_when_they_repeat(): void
    {
        $datos = $this->datos();
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('matricula-publica.store'), $datos)->assertRedirect();
        }
        $this->post(route('matricula-publica.store'), $datos)->assertStatus(429);
        $this->assertDatabaseCount('solicitudes_matricula', 1);
    }

    public function test_only_authorized_staff_can_read_and_review(): void
    {
        $solicitud = $this->registrar();
        $url = route('admin.solicitudes-matricula.index');
        $actualizar = route('admin.solicitudes-matricula.update', $solicitud->identificador);
        $this->get($url)->assertRedirect(route('login'));
        foreach ([null, 'secretaria', 'coordinador'] as $rol) {
            $usuario = User::factory()->create();
            if ($rol !== null) {
                $usuario->assignRole($rol);
            }
            $this->actingAs($usuario)->get($url)->assertForbidden();
            $this->patchJson($actualizar, ['estado' => 'en_revision', 'revision' => 1])->assertForbidden();
        }
        $lector = User::factory()->create();
        $lector->assignRole('coordinador');
        $lector->givePermissionTo('solicitudes-matricula.ver');
        $this->actingAs($lector)->get($url)->assertOk()->assertSee($solicitud->email);
        $this->patchJson($actualizar, ['estado' => 'en_revision', 'revision' => 1])->assertForbidden();
        $this->assertSame('pendiente', $solicitud->fresh()->estado);
    }

    public function test_review_has_controlled_states_revision_and_redacted_audit(): void
    {
        $solicitud = $this->registrar();
        $url = route('admin.solicitudes-matricula.update', $solicitud->identificador);
        $this->actingAs($this->admin)->patch($url, ['estado' => 'en_revision', 'revision' => 1])->assertRedirect();
        $this->patchJson($url, ['estado' => 'rechazada', 'revision' => 1, 'nota_revision' => 'Revisión obsoleta'])->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->patch($url, ['estado' => 'atendida', 'revision' => 2, 'nota_revision' => 'Nota privada de revisión'])->assertRedirect();
        $this->assertSame('atendida', $solicitud->fresh()->estado);
        $this->assertSame(3, $solicitud->fresh()->revision);
        $this->assertSame($this->admin->id, $solicitud->fresh()->revisado_por);
        $this->assertDatabaseCount('audit_logs', 2);
        $auditoria = AuditLog::all()->toJson();
        foreach ([$solicitud->email, $solicitud->nombres, $solicitud->telefono, 'Nota privada de revisión'] as $privado) {
            $this->assertStringNotContainsString($privado, $auditoria);
        }
        $this->patchJson($url, ['estado' => 'en_revision', 'revision' => 3])->assertUnprocessable();
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertDatabaseCount('matriculas', 0);
        $this->assertSame(10, $this->curso->fresh()->cupos_disponibles);
        Mail::assertNothingSent();
    }

    public function test_rejection_needs_reason_and_input_is_escaped_in_the_modal(): void
    {
        $solicitud = $this->registrar(['mensaje' => '<script>alert(1)</script>']);
        $url = route('admin.solicitudes-matricula.update', $solicitud->identificador);
        $this->actingAs($this->admin)->patchJson($url, ['estado' => 'rechazada', 'revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('nota_revision');
        $this->patch($url, ['estado' => 'rechazada', 'revision' => 1, 'nota_revision' => 'Solicitud fuera de alcance'])->assertRedirect();
        $this->get(route('admin.solicitudes-matricula.index'))->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('Solicitud fuera de alcance');
        $this->assertSame('rechazada', $solicitud->fresh()->estado);
    }

    public function test_admin_filter_and_validation_return_the_target_modal(): void
    {
        $solicitud = $this->registrar();
        $url = route('admin.solicitudes-matricula.index');
        $this->actingAs($this->admin)->get($url.'?estado=atendida')->assertOk()->assertDontSee($solicitud->identificador);
        $this->from($url)->patch(route('admin.solicitudes-matricula.update', $solicitud->identificador), [
            '_solicitud' => $solicitud->identificador, 'estado' => 'rechazada', 'revision' => 1,
        ])->assertSessionHasErrors('nota_revision');
        $this->get($url)->assertOk()->assertSee('data-auto-open', false);
        $this->getJson($url.'?estado[]=pendiente')->assertUnprocessable();
    }

    public function test_permission_migration_is_additive_idempotent_and_preserves_manual_grants(): void
    {
        Permission::whereIn('name', RolesAndPermissionsSeeder::SOLICITUDES_MATRICULA_PERMISSIONS)->get()->each(fn (Permission $p) => $p->delete());
        $coordinador = Role::findByName('coordinador');
        $coordinador->givePermissionTo('cursos.publicar');
        $antes = $coordinador->permissions()->pluck('name')->sort()->values()->all();
        $migracion = require database_path('migrations/2026_11_09_000036_add_solicitudes_matricula_permissions.php');
        $migracion->up();
        $migracion->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertSame($antes, $coordinador->fresh()->permissions()->pluck('name')->sort()->values()->all());
        foreach (RolesAndPermissionsSeeder::SOLICITUDES_MATRICULA_PERMISSIONS as $permiso) {
            $this->assertTrue(Role::findByName('admin')->hasPermissionTo($permiso));
            $this->assertFalse(Role::findByName('secretaria')->hasPermissionTo($permiso));
            $this->assertSame(1, Permission::where('name', $permiso)->count());
        }
    }

    private function referencia(): string
    {
        return app(GestionSolicitudesMatricula::class)->cursos()[0]['valor'];
    }

    /** @return array<string, mixed> */
    private function datos(): array
    {
        $marca = FormularioSolicitudMatricula::emitir();
        $this->travel(3)->seconds();

        return ['nombres' => 'Persona', 'apellidos' => 'Ficticia', 'email' => 'Persona@Example.test', 'telefono' => '+51 999 111 222',
            'curso' => $this->referencia(), 'mensaje' => 'Me interesa el curso', 'consentimiento' => '1', 'marca' => $marca];
    }

    /** @param array<string, mixed> $cambios */
    private function registrar(array $cambios = []): SolicitudMatricula
    {
        $this->post(route('matricula-publica.store'), [...$this->datos(), ...$cambios])->assertRedirect();

        return SolicitudMatricula::sole();
    }
}
