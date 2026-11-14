<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\Estudiante;
use App\Models\Matricula;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Estudiantes en /api/panel/v1/academico/estudiantes: mismas reglas, permisos y auditoría que el Blade.
 */
final class EstudiantesPanelTest extends PanelApiTestCase
{
    use DatosAcademicos;

    /** @return array<string, string> */
    private function datosValidos(array $cambios = []): array
    {
        return [
            'dni' => '45678912', 'nombres' => 'Lucía', 'apellidos' => 'Quispe Ramos', 'email' => 'lucia@correo.test',
            'telefono' => '987654321', 'fecha_nacimiento' => '2000-05-10', 'direccion' => 'Jr. Ficticio 123',
            'estado' => 'activo', ...$cambios,
        ];
    }

    public function test_listado_paginado_con_busqueda_filtros_y_orden(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $this->estudiante('11111111', ['apellidos' => 'Zapata', 'nombres' => 'Ana']);
        $this->estudiante('22222222', ['apellidos' => 'Alvarado', 'nombres' => 'Beto', 'estado' => 'inactivo']);
        $this->estudiante('33333333', ['apellidos' => 'Morales', 'nombres' => 'Carla'])->delete();

        $this->como($admin, 'GET', 'estudiantes')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.orden', 'apellidos')
            ->assertJsonPath('data.0.apellidos', 'Alvarado')
            ->assertJsonPath('data.1.apellidos', 'Zapata');

        $this->como($admin, 'GET', 'estudiantes?busqueda=1111')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.dni', '11111111');
        $this->como($admin, 'GET', 'estudiantes?estado=inactivo')->assertJsonPath('meta.total', 1)->assertJsonPath('meta.filtros.estado', 'inactivo');
        $this->como($admin, 'GET', 'estudiantes?estado=desconocido')->assertJsonPath('meta.total', 2);
        $this->como($admin, 'GET', 'estudiantes?eliminados=1')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.eliminado', true);
        $this->como($admin, 'GET', 'estudiantes?sort=-apellidos')->assertJsonPath('data.0.apellidos', 'Zapata');
        $this->como($admin, 'GET', 'estudiantes?per_page=1&page=2')->assertJsonPath('meta.paginas', 2)->assertJsonPath('data.0.apellidos', 'Zapata');
    }

    public function test_el_detalle_incluye_matriculas_solo_con_permiso_de_matriculas(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $estudiante = $this->estudiante('11111111');
        $curso = $this->curso('CUR-1');
        Matricula::create(['estudiante_id' => $estudiante->id, 'curso_id' => $curso->id, 'fecha_matricula' => '2026-11-01', 'estado' => 'pagada', 'monto_pagado' => 100]);

        $this->como($admin, 'GET', "estudiantes/{$estudiante->id}")
            ->assertOk()
            ->assertJsonPath('data.dni', '11111111')
            ->assertJsonPath('data.matriculas.0.curso.codigo', 'CUR-1')
            ->assertJsonPath('data.matriculas.0.estado', 'pagada')
            ->assertJsonMissingPath('data.matriculas.0.monto_pagado');

        $soloEstudiantes = $this->usuarioConRol('admin', 'otro@horus.test');
        $soloEstudiantes->syncRoles([]);
        $soloEstudiantes->givePermissionTo(['panel.academico.acceder', 'estudiantes.ver']);
        $this->como($soloEstudiantes->fresh(), 'GET', "estudiantes/{$estudiante->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.matriculas');

        $this->como($admin, 'GET', 'estudiantes/999999')->assertNotFound()->assertJsonPath('code', 'no_encontrado');
    }

    public function test_el_detalle_incluye_un_historial_minimizado(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $this->como($admin, 'POST', 'estudiantes', $this->datosValidos())->assertCreated();
        $id = Estudiante::query()->sole()->id;
        $this->como($admin, 'PUT', "estudiantes/{$id}", $this->datosValidos(['telefono' => '911222333', 'estado' => 'inactivo']))->assertOk();

        $respuesta = $this->como($admin, 'GET', "estudiantes/{$id}")->assertOk();
        $respuesta->assertJsonPath('data.historial.0.accion', 'estudiante_actualizado')
            ->assertJsonPath('data.historial.0.usuario', $admin->name)
            ->assertJsonPath('data.historial.0.estado_anterior', 'activo')
            ->assertJsonPath('data.historial.0.estado_nuevo', 'inactivo')
            ->assertJsonPath('data.historial.1.accion', 'estudiante_creado');
        $this->assertContains('telefono', $respuesta->json('data.historial.0.campos'));
        // Solo nombres de campo: ni valores personales ni IP.
        $contenido = (string) json_encode($respuesta->json('data.historial'));
        $this->assertStringNotContainsString('911222333', $contenido);
        $this->assertStringNotContainsString('987654321', $contenido);
        $this->assertStringNotContainsString('ip_address', $contenido);
    }

    public function test_registrar_estudiante_audita_y_devuelve_201(): void
    {
        $secretaria = $this->usuarioConRol('secretaria', 'secretaria@horus.test');

        $respuesta = $this->como($secretaria, 'POST', 'estudiantes', $this->datosValidos())
            ->assertCreated()
            ->assertJsonPath('message', 'El estudiante se registró correctamente.')
            ->assertJsonPath('data.dni', '45678912')
            ->assertJsonPath('data.fecha_nacimiento', '2000-05-10');

        $id = $respuesta->json('data.id');
        $this->assertDatabaseHas('estudiantes', ['id' => $id, 'dni' => '45678912', 'estado' => 'activo']);
        $auditoria = AuditLog::query()->where('action', 'estudiante_creado')->sole();
        $this->assertSame($secretaria->id, $auditoria->user_id);
        $this->assertSame($id, (int) $auditoria->model_id);
    }

    public function test_validaciones_del_form_request_con_codigo_estable(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $this->estudiante('45678912');

        $this->como($admin, 'POST', 'estudiantes', $this->datosValidos(['email' => 'nuevo@correo.test']))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validacion')
            ->assertJsonPath('errors.dni.0', 'Ya existe un estudiante registrado con este DNI.');

        $this->como($admin, 'POST', 'estudiantes', $this->datosValidos(['dni' => '123', 'estado' => 'otro', 'fecha_nacimiento' => '2999-01-01', 'nombres' => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['dni', 'estado', 'fecha_nacimiento', 'nombres']);

        $this->assertSame(1, Estudiante::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'estudiante_creado')->count());
    }

    /** @return iterable<string, array{string}> */
    public static function camposDelFormulario(): iterable
    {
        foreach (['dni', 'estado', 'nombres', 'apellidos', 'email', 'telefono', 'fecha_nacimiento', 'direccion'] as $campo) {
            yield $campo => [$campo];
        }
    }

    /**
     * Sustituye a las pruebas de «old input» del Blade retirado: un campo escalar enviado como array (`campo[]=x`) lo
     * rechaza la validación con 422 y el campo señalado, nunca con un error 500, tanto al crear como al editar.
     */
    #[DataProvider('camposDelFormulario')]
    public function test_un_campo_con_forma_de_array_se_rechaza_con_422_y_nunca_con_500(string $campo): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $existente = $this->estudiante('70000001');

        $this->como($admin, 'POST', 'estudiantes', $this->datosValidos([$campo => ['x']]))
            ->assertUnprocessable()->assertJsonValidationErrors([$campo]);
        $this->como($admin, 'PUT', "estudiantes/{$existente->id}", $this->datosValidos(['dni' => '70000001', $campo => ['x']]))
            ->assertUnprocessable()->assertJsonValidationErrors([$campo]);
        $this->assertSame(1, Estudiante::query()->count());
    }

    public function test_filtros_con_forma_de_array_o_demasiado_largos_se_ignoran_sin_error(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $this->estudiante('11111111');

        $this->como($admin, 'GET', 'estudiantes?estado[]=activo&busqueda[]=x&eliminados[]=1')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('meta.filtros', []);
        $buscado = $this->como($admin, 'GET', 'estudiantes?busqueda='.str_repeat('a', 350))->assertOk();
        $this->assertSame(200, mb_strlen((string) $buscado->json('meta.filtros.busqueda')));
    }

    public function test_permisos_por_accion(): void
    {
        $secretaria = $this->usuarioConRol('secretaria', 'secretaria@horus.test');
        $coordinador = $this->usuarioConRol('coordinador', 'coordinador@horus.test');
        $estudiante = $this->estudiante('11111111');

        // Secretaria: ver y crear, pero no editar ni desactivar.
        $this->como($secretaria, 'PUT', "estudiantes/{$estudiante->id}", $this->datosValidos())->assertForbidden()->assertJsonPath('code', 'prohibido');
        $this->como($secretaria, 'DELETE', "estudiantes/{$estudiante->id}")->assertForbidden();
        // Coordinador: edita, pero no desactiva ni restaura.
        $this->como($coordinador, 'DELETE', "estudiantes/{$estudiante->id}")->assertForbidden();
        $this->como($coordinador, 'POST', "estudiantes/{$estudiante->id}/restaurar")->assertForbidden();

        // Un usuario de servicios no entra al panel académico.
        $sinPanel = $this->usuarioConRol('admin', 'servicios@horus.test');
        $sinPanel->syncRoles([]);
        $sinPanel->givePermissionTo(['panel.servicios.acceder', 'estudiantes.ver']);
        $this->como($sinPanel->fresh(), 'GET', 'estudiantes')->assertForbidden();

        $this->assertDatabaseHas('estudiantes', ['id' => $estudiante->id, 'deleted_at' => null]);
    }

    public function test_editar_relee_bajo_bloqueo_y_audita_las_diferencias(): void
    {
        $coordinador = $this->usuarioConRol('coordinador', 'coordinador@horus.test');
        $estudiante = $this->estudiante('11111111', ['email' => 'a@correo.test']);

        $this->como($coordinador, 'PUT', "estudiantes/{$estudiante->id}", $this->datosValidos(['dni' => '11111111', 'email' => 'a@correo.test', 'estado' => 'inactivo']))
            ->assertOk()
            ->assertJsonPath('data.estado', 'inactivo')
            ->assertJsonPath('data.apellidos', 'Quispe Ramos');

        $auditoria = AuditLog::query()->where('action', 'estudiante_actualizado')->sole();
        $this->assertSame(['activo'], array_values($auditoria->changes['antes']));
        $this->assertSame(['inactivo'], array_values($auditoria->changes['despues']));

        // El DNI propio no choca consigo mismo, pero sí con el de otro estudiante.
        $this->estudiante('22222222');
        $this->como($coordinador, 'PUT', "estudiantes/{$estudiante->id}", $this->datosValidos(['dni' => '22222222']))
            ->assertUnprocessable()->assertJsonValidationErrors(['dni']);

        $estudiante->delete();
        $this->como($coordinador, 'PUT', "estudiantes/{$estudiante->id}", $this->datosValidos(['dni' => '11111111']))->assertNotFound();
    }

    public function test_desactivar_con_matriculas_vigentes_devuelve_conflicto_y_no_escribe(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $estudiante = $this->estudiante('11111111');
        $curso = $this->curso('CUR-1');
        Matricula::create(['estudiante_id' => $estudiante->id, 'curso_id' => $curso->id, 'fecha_matricula' => '2026-11-01', 'estado' => 'pendiente']);

        $this->como($admin, 'DELETE', "estudiantes/{$estudiante->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'conflicto')
            ->assertJsonPath('message', 'No se puede desactivar al estudiante: tiene 1 matrícula(s) vigente(s). Anúlalas primero para liberar sus cupos.');

        $this->assertNotSoftDeleted($estudiante);
        $this->assertSame(0, AuditLog::query()->where('action', 'estudiante_eliminado')->count());
    }

    public function test_desactivar_y_restaurar_auditan_una_sola_vez(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $estudiante = $this->estudiante('11111111');
        $curso = $this->curso('CUR-1');
        // Una matrícula anulada no impide desactivar.
        Matricula::create(['estudiante_id' => $estudiante->id, 'curso_id' => $curso->id, 'fecha_matricula' => '2026-11-01', 'estado' => 'anulada']);

        $this->como($admin, 'DELETE', "estudiantes/{$estudiante->id}")->assertOk()->assertJsonPath('message', 'El estudiante se desactivó correctamente.');
        $this->assertSoftDeleted($estudiante);
        // Ya eliminado: la ruta de escritura no lo encuentra (como en el Blade).
        $this->como($admin, 'DELETE', "estudiantes/{$estudiante->id}")->assertNotFound();

        $this->como($admin, 'POST', "estudiantes/{$estudiante->id}/restaurar")->assertOk()->assertJsonPath('data.eliminado', false);
        $this->como($admin, 'POST', "estudiantes/{$estudiante->id}/restaurar")->assertOk();
        $this->assertNotSoftDeleted($estudiante);

        $this->assertSame(1, AuditLog::query()->where('action', 'estudiante_eliminado')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'estudiante_restaurado')->count());
        $this->como($admin, 'POST', 'estudiantes/999999/restaurar')->assertNotFound();
    }

    public function test_flujo_completo_por_el_bff_exige_csrf_de_la_sesion(): void
    {
        $this->usuarioConRol('secretaria', 'secretaria@horus.test');
        $this->exigirCsrf();
        $sesion = $this->iniciarSesion('secretaria@horus.test')->assertOk();
        $csrf = (string) $sesion->json('data.csrf_token');

        $this->panel('POST', 'academico/estudiantes', $this->datosValidos())
            ->assertStatus(419)->assertJsonPath('code', 'csrf');
        $this->panel('POST', 'academico/estudiantes', $this->datosValidos(), ['X-CSRF-TOKEN' => 'token-falso'])
            ->assertStatus(419);
        $this->assertSame(0, Estudiante::query()->count());

        $this->panel('POST', 'academico/estudiantes', $this->datosValidos(), ['X-CSRF-TOKEN' => $csrf])->assertCreated();
        $this->panel('GET', 'academico/estudiantes')->assertOk()->assertJsonPath('meta.total', 1);

        // Sin el token del servicio BFF, nada.
        $this->withHeaders(['X-Horus-Web-Token' => 'incorrecto-incorrecto-incorrecto-00'])
            ->postJson('/api/panel/v1/academico/estudiantes', $this->datosValidos(['dni' => '99999999']))
            ->assertUnauthorized();
    }
}
