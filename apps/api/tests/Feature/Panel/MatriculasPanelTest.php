<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Support\ErroresApiPanel;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PDOException;

/**
 * Matrículas en /api/panel/v1/academico/matriculas. Las reglas son las de GestionMatriculas (la suite de
 * MatriculaOperationsTest las cubre a fondo); aquí se comprueba que la API las conserva sin atajos, con sus
 * permisos, su auditoría y una respuesta estable ante carreras.
 *
 * SQLite ignora lockForUpdate(): estas pruebas NO miden concurrencia real. Las carreras se simulan cambiando la
 * base entre la validación HTTP y la relectura bajo bloqueo, o justo antes del INSERT.
 */
final class MatriculasPanelTest extends PanelApiTestCase
{
    use DatosAcademicos;

    private Estudiante $estudiante;

    private Curso $curso;

    protected function setUp(): void
    {
        parent::setUp();
        $this->estudiante = $this->estudiante('11111111', ['nombres' => 'Ana', 'apellidos' => 'Torres']);
        $this->curso = $this->curso('CUR-1', ['cupos_maximos' => 2, 'cupos_disponibles' => 2]);
    }

    /** @return array<string, mixed> */
    private function datos(array $cambios = []): array
    {
        return [
            'estudiante_id' => $this->estudiante->id, 'curso_id' => $this->curso->id, 'fecha_matricula' => '2026-11-10',
            'estado' => 'pendiente', 'monto_pagado' => null, 'observaciones' => null, ...$cambios,
        ];
    }

    private function matricula(array $atributos = []): Matricula
    {
        $matricula = Matricula::create([...$this->datos(), ...$atributos]);
        if (in_array($matricula->estado, ['pendiente', 'pagada'], true)) {
            Curso::query()->whereKey($matricula->curso_id)->decrement('cupos_disponibles');
        }

        return $matricula;
    }

    private function cupos(?Curso $curso = null): int
    {
        return (int) ($curso ?? $this->curso)->fresh()->cupos_disponibles;
    }

    public function test_listado_con_filtros_busqueda_y_orden(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $otroCurso = $this->curso('WEB-9', ['nombre' => 'Diseño web']);
        $otro = $this->estudiante('22222222', ['apellidos' => 'Vega']);
        $this->matricula(['fecha_matricula' => '2026-10-01', 'estado' => 'pagada', 'monto_pagado' => 150]);
        $this->matricula(['estudiante_id' => $otro->id, 'curso_id' => $otroCurso->id, 'fecha_matricula' => '2026-11-05']);
        $this->matricula(['estudiante_id' => $otro->id, 'fecha_matricula' => '2026-09-01', 'estado' => 'anulada'])->delete();

        $this->como($admin, 'GET', 'matriculas')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.orden', '-fecha')
            ->assertJsonPath('data.0.curso.codigo', 'WEB-9')
            ->assertJsonPath('data.1.estudiante.dni', '11111111')
            ->assertJsonPath('data.1.monto_pagado', '150.00');

        $this->como($admin, 'GET', 'matriculas?busqueda=WEB')->assertJsonPath('meta.total', 1);
        $this->como($admin, 'GET', 'matriculas?busqueda=1111')->assertJsonPath('meta.total', 1);
        $this->como($admin, 'GET', 'matriculas?estado=pagada')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.estado', 'pagada');
        $this->como($admin, 'GET', "matriculas?curso_id={$otroCurso->id}")->assertJsonPath('meta.total', 1)->assertJsonPath('meta.filtros.curso_id', $otroCurso->id);
        $this->como($admin, 'GET', 'matriculas?eliminados=1')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.eliminado', true);
        $this->como($admin, 'GET', 'matriculas?sort=fecha')->assertJsonPath('data.0.fecha', '2026-10-01');
    }

    public function test_registrar_ocupa_cupo_audita_y_devuelve_201(): void
    {
        $secretaria = $this->usuarioConRol('secretaria', 'secretaria@horus.test');

        $id = $this->como($secretaria, 'POST', 'matriculas', $this->datos(['monto_pagado' => '80.50']))
            ->assertCreated()
            ->assertJsonPath('message', 'La matrícula se registró correctamente.')
            ->assertJsonPath('data.estado', 'pendiente')
            ->assertJsonPath('data.monto_pagado', '80.50')
            ->assertJsonPath('data.curso_cupos_disponibles', 1)
            ->json('data.id');

        $this->assertSame(1, $this->cupos());
        $auditoria = AuditLog::query()->where('action', 'matricula_creada')->sole();
        $this->assertSame($id, (int) $auditoria->model_id);
        $this->assertSame('ocupa', $auditoria->changes['efecto_cupo']);
        $this->assertSame($secretaria->id, $auditoria->user_id);
    }

    public function test_duplicado_vigente_y_cupos_agotados_se_rechazan_sin_escribir(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $this->matricula();

        $this->como($admin, 'POST', 'matriculas', $this->datos())
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validacion')
            ->assertJsonPath('errors.estudiante_id.0', 'El estudiante ya está matriculado en este curso.');

        $lleno = $this->curso('LLENO', ['cupos_maximos' => 1, 'cupos_disponibles' => 0]);
        $this->como($admin, 'POST', 'matriculas', $this->datos(['curso_id' => $lleno->id]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.curso_id.0', 'El curso no tiene cupos disponibles.');

        $inactivo = $this->estudiante('33333333', ['estado' => 'inactivo']);
        $this->como($admin, 'POST', 'matriculas', $this->datos(['estudiante_id' => $inactivo->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['estudiante_id']);

        $this->assertSame(1, Matricula::query()->count());
        $this->assertSame(1, $this->cupos());
        $this->assertSame(0, $this->cupos($lleno));
        $this->assertSame(0, AuditLog::query()->where('action', 'matricula_creada')->count());
    }

    public function test_una_matricula_anulada_no_ocupa_cupo_ni_cuenta_como_duplicado(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $this->matricula();

        $this->como($admin, 'POST', 'matriculas', $this->datos(['estado' => 'anulada']))->assertCreated();
        $this->assertSame(1, $this->cupos());
    }

    public function test_cupo_agotado_entre_la_validacion_y_el_bloqueo_se_rechaza(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        // Otra transacción ocupa el último cupo justo después de que el Form Request leyera el curso.
        $this->alPrimerCursoLeido(fn () => DB::table('cursos')->where('id', $this->curso->id)->update(['cupos_disponibles' => 0]));

        $this->como($admin, 'POST', 'matriculas', $this->datos())
            ->assertUnprocessable()
            ->assertJsonPath('errors.curso_id.0', 'El curso no tiene cupos disponibles.');

        $this->assertSame(0, Matricula::query()->count());
        $this->assertSame(0, $this->cupos());
    }

    public function test_duplicado_creado_entre_la_validacion_y_el_bloqueo_se_rechaza(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $this->alPrimerCursoLeido(fn () => DB::table('matriculas')->insert([
            'estudiante_id' => $this->estudiante->id, 'curso_id' => $this->curso->id, 'fecha_matricula' => '2026-11-10',
            'estado' => 'pagada', 'created_at' => now(), 'updated_at' => now(),
        ]));

        $this->como($admin, 'POST', 'matriculas', $this->datos())
            ->assertUnprocessable()
            ->assertJsonPath('errors.estudiante_id.0', 'El estudiante ya está matriculado en este curso.');

        $this->assertSame(1, Matricula::query()->count());
        $this->assertSame(2, $this->cupos());
    }

    public function test_violacion_del_indice_unico_revierte_todo_y_responde_409(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        // Una carrera que el bloqueo no cubre llega a la base: el índice parcial matriculas_vigente_unica la frena.
        Matricula::creating(function (Matricula $matricula): void {
            if (DB::table('matriculas')->where('estudiante_id', $matricula->estudiante_id)->doesntExist()) {
                DB::table('matriculas')->insert([
                    'estudiante_id' => $matricula->estudiante_id, 'curso_id' => $matricula->curso_id, 'fecha_matricula' => '2026-11-10',
                    'estado' => 'pagada', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $this->como($admin, 'POST', 'matriculas', $this->datos())
            ->assertStatus(409)
            ->assertJsonPath('code', 'conflicto_concurrencia')
            ->assertJsonMissingPath('exception');

        // La transacción entera se revirtió: ni matrícula, ni cupo, ni auditoría.
        $this->assertSame(0, Matricula::query()->count());
        $this->assertSame(2, $this->cupos());
        $this->assertSame(0, AuditLog::query()->where('action', 'matricula_creada')->count());
    }

    public function test_errores_de_concurrencia_de_postgresql_se_traducen_a_409(): void
    {
        foreach (['40P01', '40001', '55P03'] as $sqlstate) {
            $previa = new PDOException('simulado');
            $previa->errorInfo = [$sqlstate, 7, 'simulado'];
            $respuesta = ErroresApiPanel::responder(new QueryException('pgsql', 'select 1', [], $previa));

            $this->assertSame(409, $respuesta?->getStatusCode(), $sqlstate);
            $this->assertSame('conflicto_concurrencia', $respuesta->getData(true)['code']);
            $this->assertStringNotContainsString('select', (string) $respuesta->getContent());
        }

        $previa = new PDOException('duplicado');
        $previa->errorInfo = ['23505', 7, 'duplicado'];
        $this->assertSame(409, ErroresApiPanel::responder(new UniqueConstraintViolationException('pgsql', 'insert', [], $previa))?->getStatusCode());

        // Cualquier otro error de SQL sigue siendo un error interno sin detalles.
        config(['app.debug' => false]);
        $previa = new PDOException('otro');
        $previa->errorInfo = ['42P01', 7, 'tabla'];
        $interno = ErroresApiPanel::responder(new QueryException('pgsql', 'select * from secreta', [], $previa));
        $this->assertSame(500, $interno?->getStatusCode());
        $this->assertStringNotContainsString('secreta', (string) $interno->getContent());
    }

    public function test_anular_devuelve_el_cupo_una_sola_vez_y_exige_su_permiso(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $coordinador = $this->usuarioConRol('coordinador', 'coordinador@horus.test');
        $matricula = $this->matricula();
        $this->assertSame(1, $this->cupos());

        $this->como($coordinador, 'POST', "matriculas/{$matricula->id}/anular")->assertForbidden();
        // Tampoco por PUT: pasar a anulada exige `matriculas.anular`, evaluado bajo bloqueo.
        $this->como($coordinador, 'PUT', "matriculas/{$matricula->id}", $this->datos(['estado' => 'anulada']))->assertForbidden();
        $this->assertSame('pendiente', $matricula->fresh()->estado);
        $this->assertSame(1, $this->cupos());

        $this->como($admin, 'POST', "matriculas/{$matricula->id}/anular")->assertOk()->assertJsonPath('message', 'La matrícula se anuló correctamente.');
        $this->como($admin, 'POST', "matriculas/{$matricula->id}/anular")->assertOk();

        $this->assertSame('anulada', $matricula->fresh()->estado);
        $this->assertSame(2, $this->cupos());
        $this->assertSame(1, AuditLog::query()->where('action', 'matricula_anulada')->count());
    }

    public function test_editar_traslada_el_cupo_entre_cursos(): void
    {
        $coordinador = $this->usuarioConRol('coordinador', 'coordinador@horus.test');
        $destino = $this->curso('CUR-2', ['cupos_maximos' => 5, 'cupos_disponibles' => 5]);
        $matricula = $this->matricula();

        $this->como($coordinador, 'PUT', "matriculas/{$matricula->id}", $this->datos(['curso_id' => $destino->id, 'estado' => 'pagada', 'monto_pagado' => 150]))
            ->assertOk()
            ->assertJsonPath('data.curso.codigo', 'CUR-2')
            ->assertJsonPath('data.estado', 'pagada');

        $this->assertSame(2, $this->cupos());
        $this->assertSame(4, $this->cupos($destino));
        $this->assertSame('traslada', AuditLog::query()->where('action', 'matricula_actualizada')->sole()->changes['efecto_cupo']);
    }

    public function test_restaurar_vuelve_a_ocupar_cupo_y_respeta_la_disponibilidad(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $matricula = $this->matricula();

        $this->como($admin, 'DELETE', "matriculas/{$matricula->id}")->assertOk();
        $this->assertSoftDeleted($matricula);
        $this->assertSame(2, $this->cupos());

        // Sin cupo, la restauración se rechaza y no escribe.
        DB::table('cursos')->where('id', $this->curso->id)->update(['cupos_disponibles' => 0]);
        $this->como($admin, 'POST', "matriculas/{$matricula->id}/restaurar")
            ->assertUnprocessable()
            ->assertJsonPath('errors.curso_id.0', 'El curso no tiene cupos disponibles para restaurar esta matrícula.');
        $this->assertSoftDeleted($matricula);

        DB::table('cursos')->where('id', $this->curso->id)->update(['cupos_disponibles' => 2]);
        $this->como($admin, 'POST', "matriculas/{$matricula->id}/restaurar")->assertOk();
        $this->assertNotSoftDeleted($matricula);
        $this->assertSame(1, $this->cupos());

        // Restaurar una matrícula no eliminada no existe como operación.
        $this->como($admin, 'POST', "matriculas/{$matricula->id}/restaurar")->assertNotFound();
        $this->assertSame(1, AuditLog::query()->where('action', 'matricula_restaurada')->count());
    }

    public function test_eliminar_es_exclusivo_del_admin(): void
    {
        $coordinador = $this->usuarioConRol('coordinador', 'coordinador@horus.test');
        $matricula = $this->matricula();

        $this->como($coordinador, 'DELETE', "matriculas/{$matricula->id}")->assertForbidden();
        $this->assertNotSoftDeleted($matricula);
    }

    public function test_el_detalle_incluye_un_historial_minimizado(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $id = $this->como($admin, 'POST', 'matriculas', $this->datos(['observaciones' => 'Nota interna privada']))->json('data.id');
        $this->como($admin, 'POST', "matriculas/{$id}/anular")->assertOk();

        $detalle = $this->como($admin, 'GET', "matriculas/{$id}")
            ->assertOk()
            ->assertJsonPath('data.observaciones', 'Nota interna privada')
            ->assertJsonPath('data.historial.0.accion', 'matricula_anulada')
            ->assertJsonPath('data.historial.0.estado_anterior', 'pendiente')
            ->assertJsonPath('data.historial.0.estado_nuevo', 'anulada')
            ->assertJsonPath('data.historial.0.efecto_cupo', 'libera')
            ->assertJsonPath('data.historial.0.usuario', $admin->name)
            ->assertJsonPath('data.historial.1.accion', 'matricula_creada')
            ->assertJsonPath('data.historial.1.estado_nuevo', 'pendiente');

        // El historial nunca expone la IP ni los valores libres.
        $historial = json_encode($detalle->json('data.historial'));
        $this->assertStringNotContainsString('Nota interna', (string) $historial);
        $this->assertStringNotContainsString('ip_address', (string) $historial);
        $this->como($admin, 'GET', 'matriculas/999999')->assertNotFound();
    }

    public function test_opciones_de_los_selectores_respetan_contexto_y_permiso(): void
    {
        $secretaria = $this->usuarioConRol('secretaria', 'secretaria@horus.test');
        $this->curso('LLENO', ['cupos_maximos' => 1, 'cupos_disponibles' => 0]);
        $this->estudiante('44444444', ['estado' => 'inactivo']);

        $this->como($secretaria, 'GET', 'matriculas/opciones/cursos?contexto=crear')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.texto', 'CUR-1 · Curso CUR-1 (2 cupos)');
        $this->como($secretaria, 'GET', 'matriculas/opciones/estudiantes?contexto=crear&q=1111')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->como($secretaria, 'GET', 'matriculas/opciones/estudiantes?contexto=crear&q=4444')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->como($secretaria, 'GET', 'matriculas/opciones/cursos?contexto=editar')->assertForbidden();
        $this->como($secretaria, 'GET', 'matriculas/opciones/cursos')->assertUnprocessable();
    }

    public function test_exportacion_csv_filtrada_segura_y_auditada(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $secretaria = $this->usuarioConRol('secretaria', 'secretaria@horus.test');
        $peligroso = $this->estudiante('55555555', ['nombres' => '=HYPERLINK("http://malo")', 'apellidos' => 'X']);
        $this->matricula(['estado' => 'pagada', 'monto_pagado' => 99]);
        $this->matricula(['estudiante_id' => $peligroso->id]);

        $this->como($secretaria, 'POST', 'matriculas/exportar')->assertForbidden();

        $respuesta = $this->como($admin, 'POST', 'matriculas/exportar?estado=pendiente&busqueda=5555')->assertOk();
        $this->assertStringContainsString('text/csv', (string) $respuesta->headers->get('Content-Type'));
        $csv = $respuesta->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('11111111', $csv);

        $auditoria = AuditLog::query()->where('action', 'matriculas_csv_exported')->sole();
        $this->assertSame('pendiente', $auditoria->changes['estado']);
        $this->assertTrue($auditoria->changes['busqueda_aplicada']);
        $this->assertStringNotContainsString('5555', json_encode($auditoria->changes));
    }

    /** Ejecuta `$accion` una sola vez, la primera vez que se lee un curso (es decir, durante la validación HTTP). */
    private function alPrimerCursoLeido(callable $accion): void
    {
        $hecho = false;
        Event::listen('eloquent.retrieved: '.Curso::class, function () use (&$hecho, $accion): void {
            if (! $hecho) {
                $hecho = true;
                $accion();
            }
        });
    }
}
