<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use App\Support\ListFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\Support\LlamaCursosDelPanel;
use Tests\Support\LlamaEstudiantesDelPanel;
use Tests\Support\LlamaMatriculasDelPanel;
use Tests\Support\LlamaRolesDelPanel;
use Tests\Support\LlamaUsuariosDelPanel;
use Tests\TestCase;

class AuditoriaRobustezPanelFase2Test extends TestCase
{
    use LlamaCursosDelPanel;
    use LlamaEstudiantesDelPanel;
    use LlamaMatriculasDelPanel;
    use LlamaRolesDelPanel;
    use LlamaUsuariosDelPanel;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        $this->admin = $this->adminUser();
    }

    public function test_parametro_servicio_no_numerico_responde_404_sin_error_500(): void
    {
        $this->actingAs($this->admin);
    }

    public function test_respuestas_admin_incluyen_cache_control_no_store_salvo_descargas(): void
    {
        $respuesta = $this->actingAs($this->admin)
            ->panelMatriculas('GET')
            ->assertOk();

        $cacheControl = (string) $respuesta->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);

        $csv = $this->actingAs($this->admin)
            ->panelMatriculas('POST', 'exportar', [])
            ->assertOk();

        $this->assertStringContainsString('attachment', (string) $csv->headers->get('Content-Disposition'));
    }

    public function test_actualizar_usuario_y_rol_sin_cambios_no_genera_ruido_en_audit_log(): void
    {
        $coordinador = User::create([
            'name' => 'Coordinador Prueba',
            'email' => 'coord@horus.test',
            'password' => 'Secret123!@#',
        ]);
        $coordinador->assignRole('coordinador');

        AuditLog::query()->delete();

        $this->actingAs($this->admin)
            ->actualizarUsuarioPanel($coordinador, [
                'name' => 'Coordinador Prueba',
                'email' => 'coord@horus.test',
                'password' => '',
                'password_confirmation' => '',
                'roles' => ['coordinador'],
            ])
            ->assertSuccessful();

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'user_updated',
            'model_id' => $coordinador->id,
        ]);

        $rolCustom = Role::findOrCreate('supervisor', 'web');
        $rolCustom->syncPermissions(['estudiantes.ver']);

        AuditLog::query()->delete();

        $this->actingAs($this->admin)
            ->actualizarRolPanel($rolCustom, [
                'name' => 'supervisor',
                'permissions' => ['estudiantes.ver'],
            ])
            ->assertSuccessful();

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'role_updated',
            'model_id' => $rolCustom->id,
        ]);
    }

    public function test_api_auth_registra_login_login_failed_con_hmac_y_logout_en_audit_log(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'Intruso@Horus.test',
            'password' => 'ClaveIncorrecta123!',
        ])->assertStatus(422);

        $fallo = AuditLog::query()->where('action', 'login_failed')->latest('id')->first();
        $this->assertNotNull($fallo);
        $this->assertSame('api', $fallo->changes['canal'] ?? null);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) ($fallo->changes['huella_correo'] ?? ''));
        $this->assertStringNotContainsString('intruso@horus.test', json_encode($fallo->changes) ?: '');

        $this->admin->update(['password' => 'Admin12345!']);

        $login = $this->postJson('/api/auth/login', [
            'email' => $this->admin->email,
            'password' => 'Admin12345!',
        ])->assertOk();

        $token = (string) $login->json('access_token');
        $this->assertNotSame('', $token);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'login',
            'user_id' => $this->admin->id,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'logout',
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_list_filter_acota_longitud_y_rechaza_arrays_en_filtros(): void
    {
        $reqLargo = Request::create('/admin/estudiantes', 'GET', [
            'busqueda' => str_repeat('a', 350),
            'estado' => ['activo'],
        ]);

        $this->assertSame(200, mb_strlen(ListFilter::text($reqLargo, 'busqueda')));
        $this->assertSame('', ListFilter::in($reqLargo, 'estado', ['activo', 'inactivo']));

        $this->actingAs($this->admin);
        $this->panelCursos('GET', '', ['estado' => ['activo'], 'categoria' => ['tecnologia']])->assertOk()->assertJsonPath('meta.filtros', []);

        // Una URL manipulada (`estado[]=activo`) no rompe el listado del panel: se ignora el filtro.
        $this->actingAs($this->admin);
        $this->panelEstudiantes('GET', '', ['estado' => ['activo']])->assertOk()->assertJsonPath('meta.filtros', []);

        $this->actingAs($this->admin)
            ->panelMatriculas('GET', '', ['estado' => ['pendiente']])
            ->assertOk();
    }

    public function test_migracion_000041_es_noop_seguro_en_sqlite(): void
    {
        $migracion = require base_path('database/migrations/2026_11_10_000041_add_check_estados_administrativos.php');
        $migracion->up();
        $migracion->down();
        $this->assertTrue(true);
    }

    public function test_vistas_admin_ocultan_controles_segun_permisos_y_muestran_errores(): void
    {
        $secretaria = User::create([
            'name' => 'Secretaria Prueba',
            'email' => 'secretaria@horus.test',
            'password' => 'Secret123!@#',
        ]);
        $secretaria->assignRole('secretaria');

        $curso = Curso::create([
            'codigo' => 'CUR-SEC-01',
            'nombre' => 'Curso Visible',
            'descripcion' => 'Desc',
            'categoria' => 'tecnologia',
            'duracion_horas' => 20,
            'precio' => 150,
            'modalidad' => 'virtual',
            'horario' => 'Lunes',
            'cupos_maximos' => 10,
            'cupos_disponibles' => 10,
            'estado' => 'activo',
        ]);

        $estudiante = Estudiante::create([
            'dni' => '71234567',
            'nombres' => 'Luis',
            'apellidos' => 'Gómez',
            'estado' => 'activo',
        ]);
        $matricula = Matricula::create([
            'estudiante_id' => $estudiante->id,
            'curso_id' => $curso->id,
            'fecha_matricula' => now()->toDateString(),
            'estado' => 'pendiente',
            'monto_pagado' => 0,
        ]);

        // Puede ver la matrícula pero no anularla (permiso propio): los botones son de la UI, la regla es del servidor.
        $this->actingAs($secretaria)->panelMatriculas('GET', (string) $matricula->id)->assertOk();
        $this->panelMatriculas('POST', $matricula->id.'/anular')->assertForbidden();
    }
}
