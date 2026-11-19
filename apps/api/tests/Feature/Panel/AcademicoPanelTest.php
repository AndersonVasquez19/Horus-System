<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

/**
 * Primer corte vertical del panel académico: dashboard y cursos (lectura).
 */
final class AcademicoPanelTest extends PanelApiTestCase
{
    private function como(User $user, string $ruta): TestResponse
    {
        return $this->actingAs($user)
            ->withHeaders(['X-Horus-Web-Token' => self::TOKEN])
            ->getJson('/api/panel/v1/'.$ruta);
    }

    private function curso(string $codigo, array $atributos = []): Curso
    {
        return Curso::create([
            'codigo' => $codigo, 'nombre' => "Curso {$codigo}", 'descripcion' => 'Descripción', 'categoria' => 'tecnologia',
            'duracion_horas' => 20, 'precio' => 150, 'modalidad' => 'virtual', 'horario' => 'Lun 18:00',
            'cupos_maximos' => 10, 'cupos_disponibles' => 10, 'estado' => 'activo', ...$atributos,
        ]);
    }

    private function estudiante(string $dni, string $estado = 'activo'): Estudiante
    {
        return Estudiante::create([
            'dni' => $dni, 'nombres' => 'Nombre'.substr(md5($dni), 0, 6), 'apellidos' => 'Apellido', 'email' => "{$dni}@correo.test",
            'telefono' => '999999999', 'estado' => $estado,
        ]);
    }

    /** Usuario con un rol ad hoc y exactamente los permisos indicados. */
    private function usuarioConPermisos(array $permisos): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $rol = Role::create(['name' => 'rol-prueba-'.count($permisos).'-'.uniqid(), 'guard_name' => 'web']);
        $rol->givePermissionTo($permisos);
        $user = User::factory()->create();
        $user->assignRole($rol);

        return $user;
    }

    public function test_el_panel_academico_exige_sesion(): void
    {
        $this->withHeaders(['X-Horus-Web-Token' => self::TOKEN])
            ->getJson('/api/panel/v1/academico/resumen')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'no_autenticado');
    }

    public function test_sin_permiso_de_entrada_academico_responde_403(): void
    {
        $soloServicios = $this->usuarioConPermisos(['panel.servicios.acceder', 'cursos.ver']);

        $this->como($soloServicios, 'academico/resumen')->assertForbidden()->assertJsonPath('code', 'prohibido');
        $this->como($soloServicios, 'academico/cursos')->assertForbidden();
    }

    public function test_resumen_del_coordinador_sin_ingresos(): void
    {
        $coordinador = $this->usuarioConRol('coordinador', 'coordinador@horus.test');
        $curso = $this->curso('C-1', ['cupos_disponibles' => 7]);
        $this->curso('C-2', ['estado' => 'inactivo']);
        $estudiante = $this->estudiante('11111111');
        $this->estudiante('22222222', 'inactivo');
        Matricula::create(['estudiante_id' => $estudiante->id, 'curso_id' => $curso->id, 'fecha_matricula' => now()->toDateString(), 'estado' => 'pagada', 'monto_pagado' => 150]);

        $respuesta = $this->como($coordinador, 'academico/resumen')->assertOk();

        $respuesta->assertJsonPath('data.indicadores.estudiantes_activos', 1)
            ->assertJsonPath('data.indicadores.cursos_activos', 1)
            ->assertJsonPath('data.indicadores.cupos_disponibles', 7)
            ->assertJsonPath('data.indicadores.matriculas_mes', 1)
            ->assertJsonMissingPath('data.indicadores.ingresos_mes')
            ->assertJsonCount(6, 'data.bloques.matriculas_por_mes');
        $ultima = $respuesta->json('data.bloques.ultimas_matriculas.0');
        $this->assertSame(['id', 'estudiante', 'curso', 'estado', 'fecha'], array_keys($ultima));
    }

    public function test_solo_admin_recibe_los_ingresos_del_mes(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $curso = $this->curso('C-1');
        $estudiante = $this->estudiante('11111111');
        Matricula::create(['estudiante_id' => $estudiante->id, 'curso_id' => $curso->id, 'fecha_matricula' => now()->toDateString(), 'estado' => 'pagada', 'monto_pagado' => 150]);

        $this->como($admin, 'academico/resumen')->assertOk()->assertJsonPath('data.indicadores.ingresos_mes', '150.00');
    }

    public function test_el_resumen_omite_los_bloques_sin_permiso_de_modulo(): void
    {
        $soloEntrada = $this->usuarioConPermisos(['panel.academico.acceder']);
        $this->curso('C-1');

        $this->como($soloEntrada, 'academico/resumen')->assertOk()
            ->assertExactJson(['data' => ['indicadores' => [], 'bloques' => [], 'acciones' => [], 'negocio' => [], 'academico' => [], 'seguimiento' => ['notificaciones_sin_leer' => 0]]]);
    }

    public function test_cursos_exige_cursos_ver(): void
    {
        $soloEntrada = $this->usuarioConPermisos(['panel.academico.acceder']);

        $this->como($soloEntrada, 'academico/cursos')->assertForbidden()->assertJsonPath('code', 'prohibido');
    }

    public function test_listado_de_cursos_con_filtros_orden_y_paginacion(): void
    {
        $secretaria = $this->usuarioConRol('secretaria', 'secretaria@horus.test');
        $this->curso('PY-01', ['nombre' => 'Python', 'modalidad' => 'presencial']);
        $this->curso('JS-01', ['nombre' => 'JavaScript']);
        $this->curso('EX-01', ['nombre' => 'Excel', 'estado' => 'inactivo', 'categoria' => 'gestion']);

        $todos = $this->como($secretaria, 'academico/cursos?per_page=2&sort=-nombre')->assertOk();
        $todos->assertJsonPath('meta.total', 3)->assertJsonPath('meta.paginas', 2)->assertJsonPath('meta.por_pagina', 2)
            ->assertJsonPath('meta.orden', '-nombre')
            ->assertJsonPath('data.0.nombre', 'Python')
            ->assertJsonPath('data.1.nombre', 'JavaScript');

        $this->como($secretaria, 'academico/cursos?estado=activo&modalidad=virtual')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.codigo', 'JS-01')
            ->assertJsonPath('meta.filtros', ['estado' => 'activo', 'modalidad' => 'virtual']);

        $this->como($secretaria, 'academico/cursos?busqueda=exc')->assertOk()->assertJsonPath('data.0.codigo', 'EX-01');

        $this->assertArrayNotHasKey('precio', $todos->json('data.0'));
    }

    public function test_parametros_invalidos_no_rompen_el_listado(): void
    {
        $secretaria = $this->usuarioConRol('secretaria', 'secretaria@horus.test');
        $this->curso('PY-01');

        $this->como($secretaria, 'academico/cursos?per_page=5000&page=-3&sort=password&estado=borrado&busqueda[]=x')
            ->assertOk()
            ->assertJsonPath('meta.por_pagina', 20)
            ->assertJsonPath('meta.pagina', 1)
            ->assertJsonPath('meta.orden', 'nombre')
            ->assertJsonPath('meta.filtros', [])
            ->assertJsonPath('meta.total', 1);
    }

    public function test_cursos_eliminados_solo_con_el_filtro(): void
    {
        $secretaria = $this->usuarioConRol('secretaria', 'secretaria@horus.test');
        $this->curso('PY-01');
        $this->curso('OLD-01')->delete();

        $this->como($secretaria, 'academico/cursos')->assertJsonPath('meta.total', 1);
        $this->como($secretaria, 'academico/cursos?eliminados=1')->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.codigo', 'OLD-01')
            ->assertJsonPath('data.0.eliminado', true);
    }

    public function test_detalle_del_curso_con_estudiantes_minimizados(): void
    {
        $coordinador = $this->usuarioConRol('coordinador', 'coordinador@horus.test');
        $curso = $this->curso('PY-01');
        $activo = $this->estudiante('11111111');
        $anulado = $this->estudiante('22222222');
        Matricula::create(['estudiante_id' => $activo->id, 'curso_id' => $curso->id, 'fecha_matricula' => '2026-11-01', 'estado' => 'pendiente', 'observaciones' => 'privado']);
        Matricula::create(['estudiante_id' => $anulado->id, 'curso_id' => $curso->id, 'fecha_matricula' => '2026-11-01', 'estado' => 'anulada']);

        $respuesta = $this->como($coordinador, "academico/cursos/{$curso->id}")->assertOk();

        $respuesta->assertJsonPath('data.codigo', 'PY-01')
            ->assertJsonPath('data.matriculas_activas', 1)
            ->assertJsonCount(1, 'data.estudiantes')
            ->assertJsonPath('data.estudiantes.0.estado_matricula', 'pendiente')
            ->assertJsonPath('data.estudiantes.0.fecha_matricula', '2026-11-01');
        $cuerpo = json_encode($respuesta->json(), JSON_THROW_ON_ERROR);
        foreach (['11111111', '@correo.test', '999999999', 'privado'] as $sensible) {
            $this->assertStringNotContainsString($sensible, $cuerpo);
        }
    }

    public function test_detalle_inexistente_responde_404_generico(): void
    {
        $coordinador = $this->usuarioConRol('coordinador', 'coordinador@horus.test');

        $this->como($coordinador, 'academico/cursos/999999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'El recurso solicitado no existe.', 'code' => 'no_encontrado']);
    }
}
