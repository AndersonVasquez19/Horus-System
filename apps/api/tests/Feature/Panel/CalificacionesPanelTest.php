<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\Calificacion;
use App\Models\Convocatoria;
use App\Models\Curso;
use App\Models\Evaluacion;
use App\Models\Matricula;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Evaluaciones, notas y cierre de acta (`/api/panel/v1/academico/convocatorias/{id}/…`, Ola 3, módulo 8): permisos por rol,
 * pesos, rango de notas, corrección con motivo, cierre idempotente y sin cambios posteriores, y la exportación del acta.
 * SQLite ignora `lockForUpdate()`: la carrera real se prueba en PostgreSQL, sin ejecutar hasta nueva autorización.
 */
final class CalificacionesPanelTest extends PanelApiTestCase
{
    use DatosAcademicos;

    private Curso $curso;

    private int $contador = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->curso = $this->curso('CUR-1');
    }

    private function coordinador(): User
    {
        return $this->usuarioConRol('coordinador', 'coordinador@horus.test');
    }

    private function convocatoria(string $estado = 'en_curso'): Convocatoria
    {
        $c = new Convocatoria(['nombre' => null, 'modalidad' => 'virtual', 'fecha_inicio' => '2026-12-01', 'fecha_fin' => '2026-12-31', 'cupos_maximos' => 10]);
        $c->identificador = (string) Str::uuid();
        $c->codigo = 'CNV-2026-'.str_pad((string) ++$this->contador, 4, '0', STR_PAD_LEFT);
        $c->curso_id = $this->curso->id;
        $c->estado = $estado;
        $c->cupos_disponibles = 10;
        $c->revision = 1;
        $c->save();

        return $c;
    }

    private function matricula(Convocatoria $c, string $dni, string $estado = 'pagada'): Matricula
    {
        return Matricula::create([
            'estudiante_id' => $this->estudiante($dni)->id, 'curso_id' => $this->curso->id, 'convocatoria_id' => $c->id,
            'fecha_matricula' => '2026-11-10', 'estado' => $estado,
        ]);
    }

    private function evaluacion(Convocatoria $c, string $nombre, string $peso): Evaluacion
    {
        $e = new Evaluacion(['nombre' => $nombre, 'peso' => $peso]);
        $e->identificador = (string) Str::uuid();
        $e->convocatoria_id = $c->id;
        $e->orden = $c->evaluaciones()->count() + 1;
        $e->save();

        return $e;
    }

    private function ruta(Convocatoria $c, string $sufijo): string
    {
        return "convocatorias/{$c->identificador}/{$sufijo}";
    }

    private function nota(User $u, Convocatoria $c, Evaluacion $e, Matricula $m, string|int|float $nota, array $extra = [])
    {
        return $this->como($u, 'PUT', $this->ruta($c, 'calificaciones'), [
            'evaluacion' => $e->identificador, 'matricula_id' => $m->id, 'nota' => $nota, ...$extra,
        ]);
    }

    // ---------------------------------------------------------------- permisos

    public function test_sin_sesion_es_401_y_cada_rol_hace_solo_lo_suyo(): void
    {
        $c = $this->convocatoria();
        $e = $this->evaluacion($c, 'Examen', '100');
        $m = $this->matricula($c, '11111111');
        $secretaria = $this->usuarioConRol('secretaria', 'secretaria@horus.test');
        $coordinador = $this->coordinador();

        $this->withHeaders(['X-Horus-Web-Token' => self::TOKEN])->getJson('/api/panel/v1/academico/'.$this->ruta($c, 'calificaciones'))->assertUnauthorized();

        $this->como($secretaria, 'GET', $this->ruta($c, 'calificaciones'))->assertOk();
        $this->nota($secretaria, $c, $e, $m, 15)->assertOk();
        // La secretaria no corrige, no gestiona evaluaciones, no cierra ni exporta.
        $this->nota($secretaria, $c, $e, $m, 16, ['nota_actual' => 15, 'motivo' => 'Error de digitación'])->assertForbidden();
        $this->como($secretaria, 'POST', $this->ruta($c, 'evaluaciones'), ['nombre' => 'Otra', 'peso' => 10, '_revision' => $c->fresh()->etag()])->assertForbidden();
        $this->como($secretaria, 'POST', $this->ruta($c, 'acta/cerrar'), ['_revision' => $c->fresh()->etag()])->assertForbidden();
        $this->como($secretaria, 'POST', $this->ruta($c, 'acta/exportar'))->assertForbidden();

        $this->nota($coordinador, $c, $e, $m, 16, ['nota_actual' => 15, 'motivo' => 'Error de digitación'])->assertOk();
    }

    // ---------------------------------------------------------------- evaluaciones

    public function test_los_pesos_suman_como_maximo_100_y_la_estructura_exige_la_revision(): void
    {
        $coordinador = $this->coordinador();
        $c = $this->convocatoria('abierta');
        $crear = fn (string $nombre, string|int|float $peso, ?string $revision = null) => $this->como($coordinador, 'POST', $this->ruta($c, 'evaluaciones'), [
            'nombre' => $nombre, 'peso' => $peso, '_revision' => $revision ?? $c->fresh()->etag(),
        ]);

        $crear('Examen parcial', 40)->assertCreated()->assertJsonPath('data.evaluaciones.0.peso', '40.00')->assertJsonPath('data.peso_total', '40.00');
        $this->assertSame(2, $c->fresh()->revision);
        $crear('Examen final', 70)->assertUnprocessable()->assertJsonPath('errors.peso.0', 'La suma de pesos no puede pasar de 100: quedan 60.00 por repartir.');
        $crear('Trabajo', 60)->assertCreated()->assertJsonPath('data.peso_total', '100.00');
        $crear('Extra', 0.01)->assertUnprocessable();

        $this->como($coordinador, 'POST', $this->ruta($c, 'evaluaciones'), ['nombre' => 'Sin revisión', 'peso' => 1])->assertStatus(428);
        $this->como($coordinador, 'POST', $this->ruta($c, 'evaluaciones'), ['nombre' => 'Vieja', 'peso' => 1, '_revision' => '"convocatoria-'.$c->identificador.'-v1"'])->assertStatus(412);
        $this->assertSame(2, Evaluacion::query()->count());
        $this->assertSame(2, AuditLog::query()->where('action', 'evaluacion_creada')->count());
    }

    public function test_validaciones_de_la_evaluacion(): void
    {
        $coordinador = $this->coordinador();
        $c = $this->convocatoria('borrador');
        foreach ([
            ['nombre' => 'X', 'peso' => 10],
            ['nombre' => 'Examen <b>', 'peso' => 10],
            ['nombre' => 'Examen', 'peso' => 0],
            ['nombre' => 'Examen', 'peso' => 100.5],
            ['nombre' => 'Examen', 'peso' => 10.123],
            ['nombre' => 'Examen', 'peso' => 10, 'fecha' => '31/12/2026'],
        ] as $mal) {
            $this->como($coordinador, 'POST', $this->ruta($c, 'evaluaciones'), [...$mal, '_revision' => $c->fresh()->etag()])->assertUnprocessable();
        }
        $this->assertSame(0, Evaluacion::query()->count());
    }

    public function test_editar_y_eliminar_una_evaluacion_y_la_que_tiene_notas_no_se_borra(): void
    {
        $coordinador = $this->coordinador();
        $c = $this->convocatoria();
        $a = $this->evaluacion($c, 'Examen', '50');
        $b = $this->evaluacion($c, 'Trabajo', '30');
        $m = $this->matricula($c, '11111111');
        $this->nota($coordinador, $c, $a, $m, 12)->assertOk();

        $this->como($coordinador, 'PUT', $this->ruta($c, "evaluaciones/{$b->identificador}"), ['nombre' => 'Trabajo final', 'peso' => 50, '_revision' => $c->fresh()->etag()])
            ->assertOk()->assertJsonPath('data.peso_total', '100.00');
        $this->como($coordinador, 'PUT', $this->ruta($c, "evaluaciones/{$b->identificador}"), ['nombre' => 'Trabajo final', 'peso' => 51, '_revision' => $c->fresh()->etag()])
            ->assertUnprocessable()->assertJsonValidationErrors(['peso']);

        $this->como($coordinador, 'POST', $this->ruta($c, "evaluaciones/{$a->identificador}/eliminar"), ['_revision' => $c->fresh()->etag()])->assertStatus(409);
        $this->como($coordinador, 'POST', $this->ruta($c, "evaluaciones/{$b->identificador}/eliminar"), ['_revision' => $c->fresh()->etag()])
            ->assertOk()->assertJsonPath('data.peso_total', '50.00');
        $this->como($coordinador, 'POST', $this->ruta($c, 'evaluaciones/'.Str::uuid().'/eliminar'), ['_revision' => $c->fresh()->etag()])->assertNotFound();
        $this->assertSame(1, Evaluacion::query()->count());
    }

    // ---------------------------------------------------------------- notas

    public function test_las_notas_solo_se_registran_con_la_convocatoria_en_curso_y_a_matriculas_vigentes(): void
    {
        $coordinador = $this->coordinador();
        $c = $this->convocatoria('abierta');
        $e = $this->evaluacion($c, 'Examen', '100');
        $m = $this->matricula($c, '11111111');
        $anulada = $this->matricula($c, '22222222', 'anulada');
        $ajena = $this->matricula($this->convocatoria(), '33333333');

        $this->nota($coordinador, $c, $e, $m, 15)->assertStatus(409);
        $c->forceFill(['estado' => 'en_curso'])->save();

        $this->nota($coordinador, $c, $e, $m, 15)->assertOk()->assertJsonPath('data.matriculas.0.notas.'.$e->identificador, '15.00');
        foreach ([$anulada, $ajena] as $noVigente) {
            $this->nota($coordinador, $c, $e, $noVigente, 15)->assertUnprocessable()->assertJsonValidationErrors(['matricula_id']);
        }
        $this->como($coordinador, 'PUT', $this->ruta($c, 'calificaciones'), ['evaluacion' => (string) Str::uuid(), 'matricula_id' => $m->id, 'nota' => 10])->assertNotFound();
        $this->assertSame(1, Calificacion::query()->count());
        $auditoria = AuditLog::query()->where('action', 'nota_registrada')->sole();
        $this->assertSame('15.00', $auditoria->changes['nota']);
        $this->assertArrayNotHasKey('dni', $auditoria->changes);
    }

    public function test_el_rango_y_los_decimales_de_la_nota(): void
    {
        $coordinador = $this->coordinador();
        $c = $this->convocatoria();
        $e = $this->evaluacion($c, 'Examen', '100');
        $m = $this->matricula($c, '11111111');

        foreach ([-1, 21, 20.01, 10.555, 'abc', ''] as $mala) {
            $this->nota($coordinador, $c, $e, $m, $mala)->assertUnprocessable()->assertJsonValidationErrors(['nota']);
        }
        foreach ([0, 20, 13.5] as $buena) {
            $this->nota($coordinador, $c, $e, $m, $buena, $buena === 0 ? [] : ['nota_actual' => $buena === 20 ? 0 : 20, 'motivo' => 'Ajuste'])->assertOk();
        }
        $this->assertSame(1, Calificacion::query()->count());
    }

    public function test_corregir_exige_motivo_y_la_nota_vigente_y_deja_el_antes_y_el_despues(): void
    {
        $coordinador = $this->coordinador();
        $c = $this->convocatoria();
        $e = $this->evaluacion($c, 'Examen', '100');
        $m = $this->matricula($c, '11111111');
        $this->nota($coordinador, $c, $e, $m, 12)->assertOk();

        $this->nota($coordinador, $c, $e, $m, 14, ['nota_actual' => 12])->assertUnprocessable()->assertJsonValidationErrors(['motivo']);
        $this->nota($coordinador, $c, $e, $m, 14, ['motivo' => 'Se revisó el examen'])->assertStatus(409);
        $this->nota($coordinador, $c, $e, $m, 14, ['nota_actual' => 11, 'motivo' => 'Se revisó el examen'])->assertStatus(409);
        $this->assertSame('12.00', Calificacion::query()->sole()->nota);

        $this->nota($coordinador, $c, $e, $m, 14, ['nota_actual' => 12, 'motivo' => 'Se revisó el examen'])->assertOk();
        $this->assertSame('14.00', Calificacion::query()->sole()->nota);
        $registro = AuditLog::query()->where('action', 'nota_corregida')->sole();
        $this->assertSame('12.00', $registro->changes['antes']);
        $this->assertSame('14.00', $registro->changes['despues']);
        $this->assertSame('Se revisó el examen', $registro->changes['motivo']);

        // Repetir la misma nota no corrige nada.
        $this->nota($coordinador, $c, $e, $m, 14, ['nota_actual' => 14])->assertOk();
        $this->assertSame(1, AuditLog::query()->where('action', 'nota_corregida')->count());
    }

    // ---------------------------------------------------------------- acta

    public function test_el_cierre_calcula_notas_finales_y_resultados_y_es_idempotente(): void
    {
        $coordinador = $this->coordinador();
        $c = $this->convocatoria();
        $parcial = $this->evaluacion($c, 'Parcial', '30');
        $final = $this->evaluacion($c, 'Final', '70');
        $ana = $this->matricula($c, '11111111');
        $beto = $this->matricula($c, '22222222');
        $anulada = $this->matricula($c, '33333333', 'anulada');
        foreach ([[$ana, 15, 10], [$beto, 10, 10]] as [$m, $n1, $n2]) {
            $this->nota($coordinador, $c, $parcial, $m, $n1)->assertOk();
            $this->nota($coordinador, $c, $final, $m, $n2)->assertOk();
        }
        $cerrar = fn () => $this->como($coordinador, 'POST', $this->ruta($c, 'acta/cerrar'), ['_revision' => $c->fresh()->etag()]);

        $cerrar()->assertOk()->assertJsonPath('data.convocatoria.estado', 'cerrada');
        // 15×0.30 + 10×0.70 = 11.50 (aprobada); 10 (desaprobada).
        $this->assertSame('11.50', (string) $ana->fresh()->nota_final);
        $this->assertSame('aprobado', $ana->fresh()->resultado);
        $this->assertSame('10.00', (string) $beto->fresh()->nota_final);
        $this->assertSame('desaprobado', $beto->fresh()->resultado);
        $this->assertNull($anulada->fresh()->nota_final);
        $this->assertNull($anulada->fresh()->resultado);
        $registro = AuditLog::query()->where('action', 'acta_cerrada')->sole();
        $this->assertSame(2, $registro->changes['matriculas']);
        $this->assertSame(1, $registro->changes['aprobados']);

        // Idempotente: otra vez, incluso con una revisión vieja, no cambia nada.
        $revision = $c->fresh()->revision;
        $this->como($coordinador, 'POST', $this->ruta($c, 'acta/cerrar'), ['_revision' => '"convocatoria-'.$c->identificador.'-v1"'])->assertOk();
        $this->assertSame($revision, $c->fresh()->revision);
        $this->assertSame(1, AuditLog::query()->where('action', 'acta_cerrada')->count());

        // Cerrada: ni notas ni evaluaciones ni estado se modifican.
        $this->nota($coordinador, $c, $parcial, $ana, 20, ['nota_actual' => 15, 'motivo' => 'Tarde'])->assertStatus(409);
        $this->como($coordinador, 'POST', $this->ruta($c, 'evaluaciones'), ['nombre' => 'Tarde', 'peso' => 1, '_revision' => $c->fresh()->etag()])->assertStatus(409);
        $this->assertSame('15.00', Calificacion::query()->where('matricula_id', $ana->id)->where('evaluacion_id', $parcial->id)->value('nota'));
    }

    public function test_el_cierre_redondea_sin_errores_de_coma_flotante(): void
    {
        $coordinador = $this->coordinador();
        $c = $this->convocatoria();
        $evaluaciones = [$this->evaluacion($c, 'A', '33.33'), $this->evaluacion($c, 'B', '33.33'), $this->evaluacion($c, 'C', '33.34')];
        $m = $this->matricula($c, '11111111');
        foreach ([11, 11, 12] as $i => $nota) {
            $this->nota($coordinador, $c, $evaluaciones[$i], $m, $nota)->assertOk();
        }

        $this->como($coordinador, 'POST', $this->ruta($c, 'acta/cerrar'), ['_revision' => $c->fresh()->etag()])->assertOk();
        // 11×0.3333 + 11×0.3333 + 12×0.3334 = 11.3334 → 11.33
        $this->assertSame('11.33', (string) $m->fresh()->nota_final);
    }

    /** Fase 3.11: al cerrar el acta se emite una encuesta por matrícula no anulada; el KPI cuenta solo respondidas. */
    public function test_el_cierre_emite_las_encuestas_y_el_resumen_calcula_la_satisfaccion(): void
    {
        $coordinador = $this->coordinador();
        $c = $this->convocatoria();
        $e = $this->evaluacion($c, 'Unica', '100');
        $ana = $this->matricula($c, '11111111');
        $beto = $this->matricula($c, '22222222');
        $this->nota($coordinador, $c, $e, $ana, 15)->assertOk();
        $this->nota($coordinador, $c, $e, $beto, 15)->assertOk();

        $this->como($coordinador, 'POST', $this->ruta($c, 'acta/cerrar'), ['_revision' => $c->fresh()->etag()])->assertOk();
        $this->assertSame(2, \App\Models\EncuestaCurso::query()->where('convocatoria_id', $c->id)->count());

        $servicio = app(\App\Services\SatisfaccionEncuestas::class);
        $this->assertSame(0, $servicio->emitirParaEdicion($c->fresh()), 'emitir de nuevo no duplica');
        $this->assertNull($servicio->resumen((int) $c->id)['promedio']);

        \App\Models\EncuestaCurso::query()->where('matricula_id', $ana->id)->update(['calificacion' => 3, 'respondida_at' => now()]);
        $r = $servicio->resumen((int) $c->id);
        $this->assertSame(['emitidas' => 2, 'respondidas' => 1, 'tasa_respuesta' => 50, 'promedio' => 3.0, 'felices_pct' => 100], $r);
    }

    public function test_el_cierre_exige_pesos_de_100_notas_completas_y_la_convocatoria_en_curso(): void
    {
        $coordinador = $this->coordinador();
        $c = $this->convocatoria();
        $cerrar = fn () => $this->como($coordinador, 'POST', $this->ruta($c, 'acta/cerrar'), ['_revision' => $c->fresh()->etag()]);

        $cerrar()->assertUnprocessable()->assertJsonValidationErrors(['pesos']);
        $a = $this->evaluacion($c, 'Parcial', '60');
        $cerrar()->assertUnprocessable()->assertJsonValidationErrors(['pesos']);
        $b = $this->evaluacion($c, 'Final', '40');
        $m = $this->matricula($c, '11111111');
        $this->nota($coordinador, $c, $a, $m, 15)->assertOk();
        $cerrar()->assertUnprocessable()->assertJsonPath('errors.notas.0', 'Faltan 1 notas por registrar para cerrar el acta.');
        $this->assertSame('en_curso', $c->fresh()->estado);
        $this->assertNull($m->fresh()->nota_final);

        $abierta = $this->convocatoria('abierta');
        $this->como($coordinador, 'POST', $this->ruta($abierta, 'acta/cerrar'), ['_revision' => $abierta->etag()])->assertStatus(409);
        $this->como($coordinador, 'POST', $this->ruta($c, 'acta/cerrar'))->assertStatus(428);
        $this->assertNotNull($b);
    }

    public function test_el_estado_generico_no_cierra_una_convocatoria_con_evaluaciones(): void
    {
        $coordinador = $this->coordinador();
        $c = $this->convocatoria();
        $this->como($coordinador, 'POST', "convocatorias/{$c->identificador}/estado", ['estado' => 'cerrada', '_revision' => $c->etag()])->assertOk();

        $con = $this->convocatoria();
        $this->evaluacion($con, 'Examen', '100');
        $this->como($coordinador, 'POST', "convocatorias/{$con->identificador}/estado", ['estado' => 'cerrada', '_revision' => $con->etag()])
            ->assertStatus(409)->assertJsonPath('message', 'Esta convocatoria tiene evaluaciones: usa el cierre de acta.');
        $this->assertSame('en_curso', $con->fresh()->estado);
    }

    // ---------------------------------------------------------------- exportación y privacidad

    public function test_el_acta_solo_se_exporta_cerrada_deja_constancia_y_no_se_cachea(): void
    {
        $coordinador = $this->coordinador();
        $c = $this->convocatoria();
        $e = $this->evaluacion($c, 'Examen', '100');
        $m = $this->matricula($c, '11111111');
        $this->nota($coordinador, $c, $e, $m, 16)->assertOk();

        $this->como($coordinador, 'POST', $this->ruta($c, 'acta/exportar'))->assertStatus(409);
        $this->como($coordinador, 'POST', $this->ruta($c, 'acta/cerrar'), ['_revision' => $c->fresh()->etag()])->assertOk();

        $respuesta = $this->como($coordinador, 'POST', $this->ruta($c, 'acta/exportar'))->assertOk();
        $this->assertStringContainsString('no-store', (string) $respuesta->headers->get('Cache-Control'));
        $this->assertStringContainsString('spreadsheetml', (string) $respuesta->headers->get('Content-Type'));
        $registro = AuditLog::query()->where('action', 'acta_exportada')->sole();
        $this->assertSame(1, $registro->changes['matriculas']);
        $this->assertSame($coordinador->id, $registro->user_id);
        // La constancia guarda archivo y cantidad, nunca notas ni datos del estudiante.
        $this->assertEqualsCanonicalizing(['filename', 'matriculas'], array_keys($registro->changes));
    }

    public function test_la_respuesta_no_se_cachea_ni_expone_ids_internos_de_evaluaciones(): void
    {
        $coordinador = $this->coordinador();
        $c = $this->convocatoria();
        $e = $this->evaluacion($c, 'Examen', '100');
        $m = $this->matricula($c, '11111111');
        $this->nota($coordinador, $c, $e, $m, 16)->assertOk();

        $respuesta = $this->como($coordinador, 'GET', $this->ruta($c, 'calificaciones'))->assertOk()
            ->assertJsonPath('data.nota_aprobatoria', 11)
            ->assertJsonPath('data.evaluaciones.0.con_notas', true)
            ->assertJsonPath('data.matriculas.0.estudiante.dni', '11111111');
        $this->assertStringContainsString('no-store', (string) $respuesta->headers->get('Cache-Control'));
        $this->assertArrayNotHasKey('id', $respuesta->json('data.evaluaciones.0'));
        $this->assertNotNull($respuesta->headers->get('ETag'));
    }
}
