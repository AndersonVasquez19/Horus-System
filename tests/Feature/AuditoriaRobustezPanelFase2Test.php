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
use Tests\TestCase;

class AuditoriaRobustezPanelFase2Test extends TestCase
{
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
        $this->actingAs($this->admin)
            ->getJson('/admin/servicios/no-numerico')
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->putJson('/admin/servicios/no-numerico/publicacion', [])
            ->assertNotFound();
    }

    public function test_respuestas_admin_incluyen_cache_control_no_store_salvo_descargas(): void
    {
        $respuesta = $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk();

        $cacheControl = (string) $respuesta->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);

        $csv = $this->actingAs($this->admin)
            ->post(route('admin.matriculas.csv'))
            ->assertOk();

        $this->assertStringContainsString('attachment', (string) $csv->headers->get('Content-Disposition'));
    }

    public function test_configuracion_index_usa_una_sola_consulta_de_ajustes_y_update_audita(): void
    {
        DB::enableQueryLog();
        $this->actingAs($this->admin)
            ->get(route('admin.configuracion.index'))
            ->assertOk();

        $consultasSettings = collect(DB::getQueryLog())
            ->filter(fn (array $q): bool => str_contains($q['query'], 'system_settings'))
            ->count();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(1, $consultasSettings);

        $this->actingAs($this->admin)
            ->put(route('admin.configuracion.update'), [
                'settings' => [
                    'nombre_empresa' => 'Horus Group Test',
                    'telefono' => '+51 999888777',
                    'correo_contacto' => 'contacto@horus.test',
                    'moneda' => 'PEN',
                    'direccion' => 'Cajamarca',
                ],
            ])
            ->assertRedirect(route('admin.configuracion.index'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'settings_updated',
            'user_id' => $this->admin->id,
        ]);
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
            ->put(route('admin.usuarios.update', $coordinador), [
                'name' => 'Coordinador Prueba',
                'email' => 'coord@horus.test',
                'password' => '',
                'password_confirmation' => '',
                'roles' => ['coordinador'],
            ])
            ->assertRedirect(route('admin.usuarios.index'));

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'user_updated',
            'model_id' => $coordinador->id,
        ]);

        $rolCustom = Role::findOrCreate('supervisor', 'web');
        $rolCustom->syncPermissions(['estudiantes.ver']);

        AuditLog::query()->delete();

        $this->actingAs($this->admin)
            ->put(route('admin.roles.update', $rolCustom), [
                'name' => 'supervisor',
                'permissions' => ['estudiantes.ver'],
            ])
            ->assertRedirect(route('admin.roles.index'));

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

        $this->actingAs($this->admin)
            ->get(route('admin.cursos.index', ['estado' => ['activo'], 'categoria' => ['tecnologia']]))
            ->assertOk();

        $this->actingAs($this->admin)
            ->get(route('admin.estudiantes.index', ['estado' => ['activo']]))
            ->assertOk();

        $this->actingAs($this->admin)
            ->get(route('admin.matriculas.index', ['estado' => ['pendiente']]))
            ->assertOk();

        $this->actingAs($this->admin)
            ->get(route('admin.asistente.index', ['intent' => ['saludo']]))
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

        $htmlCursos = (string) $this->actingAs($secretaria)
            ->get(route('admin.cursos.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Nuevo curso', $htmlCursos);
        $this->assertStringNotContainsString('Editar curso CUR-SEC-01', $htmlCursos);
        $this->assertStringNotContainsString('Desactivar curso CUR-SEC-01', $htmlCursos);

        $htmlCrearMatricula = (string) $this->actingAs($secretaria)
            ->get(route('admin.matriculas.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('<option value="anulada"', $htmlCrearMatricula);

        $htmlDashboard = (string) $this->actingAs($secretaria)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('href="/admin/usuarios"', $htmlDashboard);
        $this->assertStringNotContainsString('href="/admin/roles"', $htmlDashboard);
        $this->assertStringNotContainsString('href="/admin/configuracion"', $htmlDashboard);

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

        $htmlMatriculaShow = (string) $this->actingAs($secretaria)
            ->get(route('admin.matriculas.show', $matricula))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('admin.matriculas.anular', $htmlMatriculaShow);

        $htmlAsistente = (string) $this->actingAs($this->admin)
            ->get(route('admin.asistente.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString("<th scope=\"col\">Intención</th>\n                                <th scope=\"col\">Fuente</th>", $htmlAsistente);
    }
}
