<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\CursoPublicacion;
use App\Models\Matricula;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Cursos en /api/panel/v1/academico/cursos: alta, edición, archivado, restauración, historial y ficha pública con
 * las mismas reglas, permisos y auditoría que el Blade (GestionCursos y PublicacionCursos).
 */
final class CursosPanelTest extends PanelApiTestCase
{
    use DatosAcademicos;

    /**
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function datosValidos(array $cambios = []): array
    {
        return [
            'codigo' => 'CUR-NUEVO', 'nombre' => 'Excel Intermedio', 'descripcion' => 'Contenido del curso', 'categoria' => 'tecnologia',
            'duracion_horas' => 30, 'precio' => '250.50', 'modalidad' => 'virtual', 'horario' => 'Sáb 09:00', 'cupos_maximos' => 9,
            'estado' => 'activo', ...$cambios,
        ];
    }

    /**
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function ficha(array $cambios = []): array
    {
        return [
            'titulo' => 'Excel Intermedio', 'resumen' => 'Aprende tablas dinámicas.', 'modalidad' => 'virtual',
            'vigente_desde' => now('America/Lima')->toDateString(), 'vigente_hasta' => now('America/Lima')->addMonth()->toDateString(),
            'confirmacion_publica' => true, ...$cambios,
        ];
    }

    private function admin(): User
    {
        return $this->usuarioConRol('admin', 'admin@horus.test');
    }

    public function test_listado_filtra_por_categoria_estado_modalidad_y_eliminados_y_los_restaura(): void
    {
        $admin = $this->admin();
        $this->curso('CUR-TEC', ['nombre' => 'Tecnología aplicada', 'categoria' => 'tecnologia']);
        $this->curso('CUR-SAL', ['nombre' => 'Primeros auxilios', 'categoria' => 'salud', 'modalidad' => 'presencial']);
        $eliminado = $this->curso('CUR-DEL', ['nombre' => 'Curso eliminado']);
        $eliminado->delete();

        $this->como($admin, 'GET', 'cursos?categoria=tecnologia&estado=activo')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.codigo', 'CUR-TEC')
            ->assertJsonPath('meta.filtros.categoria', 'tecnologia');
        $this->como($admin, 'GET', 'cursos?modalidad=presencial')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.codigo', 'CUR-SAL');
        $this->como($admin, 'GET', 'cursos?eliminados=1')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.codigo', 'CUR-DEL')
            ->assertJsonPath('data.0.eliminado', true);
        $this->como($admin, 'GET', 'cursos?categoria=inventada&estado=otro')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.filtros', []);
        $this->como($admin, 'GET', 'cursos?sort=-nombre')->assertJsonPath('data.0.codigo', 'CUR-TEC');
        $this->como($admin, 'GET', 'cursos?per_page=1&page=2')->assertJsonPath('meta.paginas', 2);

        $this->como($admin, 'POST', "cursos/{$eliminado->id}/restaurar")->assertOk();
        $this->assertNotSoftDeleted('cursos', ['id' => $eliminado->id]);
    }

    public function test_el_detalle_lista_los_estudiantes_matriculados_sin_datos_personales_ni_montos(): void
    {
        $admin = $this->admin();
        $curso = $this->curso('CUR-DET', ['cupos_maximos' => 10, 'cupos_disponibles' => 9]);
        $estudiante = $this->estudiante('70123456');
        Matricula::create(['estudiante_id' => $estudiante->id, 'curso_id' => $curso->id, 'fecha_matricula' => '2026-11-01', 'estado' => 'pagada', 'monto_pagado' => 150]);

        $respuesta = $this->como($admin, 'GET', "cursos/{$curso->id}")->assertOk()
            ->assertJsonPath('data.matriculas_activas', 1)->assertJsonPath('data.cupos_disponibles', 9)
            ->assertJsonPath('data.estudiantes.0.estado_matricula', 'pagada');
        $this->assertStringContainsString($estudiante->nombres, (string) $respuesta->json('data.estudiantes.0.nombre'));
        $cuerpo = (string) json_encode($respuesta->json('data.estudiantes'));
        foreach (['70123456', 'monto', 'email', 'telefono'] as $sensible) {
            $this->assertStringNotContainsString($sensible, $cuerpo);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function camposDelFormulario(): iterable
    {
        foreach (['codigo', 'nombre', 'categoria', 'modalidad', 'descripcion', 'duracion_horas', 'precio', 'horario', 'cupos_maximos', 'estado'] as $campo) {
            yield $campo => [$campo];
        }
    }

    /**
     * Sustituye a las pruebas de «old input» del Blade retirado: un campo enviado como array (`campo[]=x`) lo rechaza la
     * validación con 422 y el campo señalado, nunca con un 500, al crear y al editar.
     */
    #[DataProvider('camposDelFormulario')]
    public function test_un_campo_con_forma_de_array_se_rechaza_con_422_y_nunca_con_500(string $campo): void
    {
        $admin = $this->admin();
        $existente = $this->curso('CUR-EXI');

        $this->como($admin, 'POST', 'cursos', $this->datosValidos([$campo => ['x']]))
            ->assertUnprocessable()->assertJsonValidationErrors([$campo]);
        $this->como($admin, 'PUT', "cursos/{$existente->id}", $this->datosValidos(['codigo' => 'CUR-EXI', $campo => ['x']]))
            ->assertUnprocessable()->assertJsonValidationErrors([$campo]);
        $this->assertSame(1, Curso::query()->count());
    }

    public function test_registrar_curso_audita_y_deja_los_cupos_disponibles_iguales_al_maximo(): void
    {
        $admin = $this->admin();

        $this->como($admin, 'POST', 'cursos', $this->datosValidos())
            ->assertCreated()
            ->assertJsonPath('data.codigo', 'CUR-NUEVO')
            ->assertJsonPath('data.cupos_disponibles', 9)
            ->assertJsonPath('data.historial.0.accion', 'curso_creado');

        $this->assertSame(9, Curso::query()->where('codigo', 'CUR-NUEVO')->sole()->cupos_disponibles);
        $this->assertSame(1, AuditLog::query()->where('action', 'curso_creado')->count());
    }

    public function test_registrar_valida_con_los_form_requests_existentes_y_no_escribe(): void
    {
        $admin = $this->admin();
        $this->curso('CUR-1');

        $this->como($admin, 'POST', 'cursos', $this->datosValidos(['codigo' => 'CUR-1']))
            ->assertUnprocessable()->assertJsonValidationErrors(['codigo']);
        $this->como($admin, 'POST', 'cursos', $this->datosValidos(['precio' => '-1', 'cupos_maximos' => 0, 'categoria' => 'x']))
            ->assertUnprocessable()->assertJsonValidationErrors(['precio', 'cupos_maximos', 'categoria']);

        $this->assertSame(1, Curso::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'curso_creado')->count());
    }

    public function test_editar_recalcula_cupos_y_no_permite_bajar_del_numero_de_matriculas_activas(): void
    {
        $admin = $this->admin();
        $curso = $this->curso('CUR-1', ['cupos_maximos' => 5, 'cupos_disponibles' => 4]);
        $estudiante = $this->estudiante('11111111');
        Matricula::create(['estudiante_id' => $estudiante->id, 'curso_id' => $curso->id, 'fecha_matricula' => '2026-11-01', 'estado' => 'pagada', 'monto_pagado' => 10]);

        $this->como($admin, 'PUT', "cursos/{$curso->id}", $this->datosValidos(['codigo' => 'CUR-1', 'cupos_maximos' => 8]))
            ->assertOk()->assertJsonPath('data.cupos_disponibles', 7);

        $this->como($admin, 'PUT', "cursos/{$curso->id}", $this->datosValidos(['codigo' => 'CUR-1', 'cupos_maximos' => 0]))
            ->assertUnprocessable();
        $this->assertSame(7, $curso->fresh()->cupos_disponibles);
        // Tope de cupos: más de 10 se rechaza con un mensaje claro, al editar y al crear.
        $this->como($admin, 'PUT', "cursos/{$curso->id}", $this->datosValidos(['codigo' => 'CUR-1', 'cupos_maximos' => 11]))
            ->assertUnprocessable()->assertJsonValidationErrors(['cupos_maximos'])
            ->assertJsonPath('errors.cupos_maximos.0', 'Un curso admite como máximo 10 cupos.');
        $this->como($admin, 'POST', 'cursos', $this->datosValidos(['cupos_maximos' => 11]))->assertUnprocessable()->assertJsonValidationErrors(['cupos_maximos']);
        $this->assertSame(1, AuditLog::query()->where('action', 'curso_actualizado')->count());
    }

    public function test_archivar_exige_anular_matriculas_vigentes_y_restaurar_es_idempotente(): void
    {
        $admin = $this->admin();
        $curso = $this->curso('CUR-1');
        $estudiante = $this->estudiante('11111111');
        $matricula = Matricula::create(['estudiante_id' => $estudiante->id, 'curso_id' => $curso->id, 'fecha_matricula' => '2026-11-01', 'estado' => 'pendiente']);

        $this->como($admin, 'DELETE', "cursos/{$curso->id}")->assertStatus(409);
        $this->assertNull($curso->fresh()->deleted_at);
        $this->assertSame(0, AuditLog::query()->where('action', 'curso_archivado')->count());

        $matricula->update(['estado' => 'anulada']);
        $this->como($admin, 'DELETE', "cursos/{$curso->id}")->assertOk();
        $this->assertTrue(Curso::withTrashed()->findOrFail($curso->id)->trashed());

        $this->como($admin, 'POST', "cursos/{$curso->id}/restaurar")->assertOk()->assertJsonPath('data.eliminado', false);
        $this->como($admin, 'POST', "cursos/{$curso->id}/restaurar")->assertOk();
        $this->assertSame(1, AuditLog::query()->where('action', 'curso_restaurado')->count());
    }

    public function test_el_historial_del_detalle_no_expone_ip_ni_valores_de_precio(): void
    {
        $admin = $this->admin();
        $this->como($admin, 'POST', 'cursos', $this->datosValidos())->assertCreated();
        $id = Curso::query()->sole()->id;
        $this->como($admin, 'PUT', "cursos/{$id}", $this->datosValidos(['precio' => '300', 'nombre' => 'Otro nombre']))->assertOk();

        $respuesta = $this->como($admin, 'GET', "cursos/{$id}")->assertOk();
        $respuesta->assertJsonPath('data.historial.0.accion', 'curso_actualizado');
        $respuesta->assertJsonPath('data.historial.0.usuario', $admin->name);
        $this->assertContains('precio', $respuesta->json('data.historial.0.campos'));
        $this->assertStringNotContainsString('ip_address', (string) $respuesta->getContent());
        $this->assertStringNotContainsString('250.5', (string) $respuesta->getContent());
    }

    public function test_cada_accion_exige_su_permiso(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $curso = $this->curso('CUR-1');

        $lector = User::factory()->create();
        $lector->givePermissionTo(['panel.academico.acceder', 'cursos.ver']);
        $this->como($lector, 'GET', "cursos/{$curso->id}")->assertOk();
        $this->como($lector, 'POST', 'cursos', $this->datosValidos())->assertForbidden();
        $this->como($lector, 'PUT', "cursos/{$curso->id}", $this->datosValidos())->assertForbidden();
        $this->como($lector, 'DELETE', "cursos/{$curso->id}")->assertForbidden();
        $this->como($lector, 'POST', "cursos/{$curso->id}/restaurar")->assertForbidden();
        $this->como($lector, 'GET', "cursos/{$curso->id}/publicacion")->assertForbidden();
        $this->como($lector, 'PUT', "cursos/{$curso->id}/publicacion", $this->ficha())->assertForbidden();

        $this->assertSame(1, Curso::query()->count());
    }

    public function test_publicar_y_retirar_la_ficha_con_revision_obligatoria(): void
    {
        $admin = $this->admin();
        $curso = $this->curso('CUR-1');

        $vacia = $this->como($admin, 'GET', "cursos/{$curso->id}/publicacion")->assertOk()->assertJsonPath('data.ficha', null)->assertJsonPath('data.publicable', true);
        $revision = $vacia->json('data.revision');
        $this->assertSame($revision, $vacia->headers->get('ETag'));

        // Sin revisión no se escribe.
        $this->como($admin, 'PUT', "cursos/{$curso->id}/publicacion", $this->ficha())->assertUnprocessable()->assertJsonValidationErrors(['revision']);
        $this->assertSame(0, CursoPublicacion::query()->count());

        $publicada = $this->como($admin, 'PUT', "cursos/{$curso->id}/publicacion", $this->ficha(['_revision' => $revision]))
            ->assertOk()->assertJsonPath('data.ficha.publicada', true);
        $nueva = $publicada->json('data.revision');
        $this->assertNotSame($revision, $nueva);
        $this->assertSame(1, AuditLog::query()->where('action', 'curso_publicado')->count());

        // Una revisión vieja se rechaza con 412 y no cambia nada.
        $this->como($admin, 'POST', "cursos/{$curso->id}/publicacion/retirar", ['_revision' => $revision])->assertStatus(412);
        $this->assertTrue(CursoPublicacion::query()->sole()->publicada);

        $this->como($admin, 'POST', "cursos/{$curso->id}/publicacion/retirar", ['_revision' => $nueva])
            ->assertOk()->assertJsonPath('data.ficha.publicada', false);
        $this->assertSame(1, AuditLog::query()->where('action', 'curso_despublicado')->count());
    }

    public function test_solo_se_publica_un_curso_activo_y_con_datos_validos(): void
    {
        $admin = $this->admin();
        $inactivo = $this->curso('CUR-2', ['estado' => 'inactivo']);
        $revision = $this->como($admin, 'GET', "cursos/{$inactivo->id}/publicacion")->assertJsonPath('data.publicable', false)->json('data.revision');

        $this->como($admin, 'PUT', "cursos/{$inactivo->id}/publicacion", $this->ficha(['_revision' => $revision]))
            ->assertUnprocessable()->assertJsonValidationErrors(['titulo']);

        $activo = $this->curso('CUR-3');
        $revision = $this->como($admin, 'GET', "cursos/{$activo->id}/publicacion")->json('data.revision');
        $this->como($admin, 'PUT', "cursos/{$activo->id}/publicacion", $this->ficha(['_revision' => $revision, 'confirmacion_publica' => false]))
            ->assertUnprocessable()->assertJsonValidationErrors(['confirmacion_publica']);
        $this->como($admin, 'PUT', "cursos/{$activo->id}/publicacion", $this->ficha(['_revision' => $revision, 'vigente_hasta' => '2020-01-01']))
            ->assertUnprocessable()->assertJsonValidationErrors(['vigente_hasta']);
        $this->assertSame(0, CursoPublicacion::query()->count());
    }
}
