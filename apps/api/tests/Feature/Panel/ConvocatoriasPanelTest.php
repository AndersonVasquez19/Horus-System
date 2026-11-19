<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\AuditLog;
use App\Models\Convocatoria;
use App\Models\Curso;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

/**
 * Convocatorias y horarios del panel académico (`/api/panel/v1/academico/convocatorias`, Ola 3): permisos por rol,
 * validación, código secuencial, cupos, estados cerrados, revisión (428/412) y horarios sin solapamiento.
 */
final class ConvocatoriasPanelTest extends PanelApiTestCase
{
    /** @param array<string, mixed> $datos */
    private function como(?User $user, string $metodo, string $ruta = '', array $datos = []): TestResponse
    {
        $peticion = $user === null ? $this : $this->actingAs($user);

        return $peticion->withHeaders(['X-Horus-Web-Token' => self::TOKEN])
            ->json($metodo, '/api/panel/v1/academico/convocatorias'.($ruta === '' ? '' : '/'.$ruta), $datos);
    }

    private function coordinador(): User
    {
        return $this->usuarioConRol('coordinador', 'coordinador@horus.test');
    }

    private function curso(string $codigo = 'CUR-CNV', string $estado = 'activo'): Curso
    {
        return Curso::create([
            'codigo' => $codigo, 'nombre' => 'Curso ficticio', 'descripcion' => 'Descripción.', 'categoria' => 'tecnologia',
            'duracion_horas' => 20, 'precio' => 100, 'modalidad' => 'virtual', 'horario' => 'Lunes', 'cupos_maximos' => 10,
            'cupos_disponibles' => 10, 'estado' => $estado,
        ]);
    }

    /** @return array<string, mixed> */
    private function datos(Curso $curso, array $cambios = []): array
    {
        $inicio = CarbonImmutable::now('America/Lima')->addDays(10);

        return [
            'curso_id' => $curso->id, 'nombre' => null, 'modalidad' => 'virtual', 'fecha_inicio' => $inicio->toDateString(),
            'fecha_fin' => $inicio->addDays(30)->toDateString(), 'cupos_maximos' => 10, ...$cambios,
        ];
    }

    private function crear(User $usuario, ?Curso $curso = null, array $cambios = []): Convocatoria
    {
        $respuesta = $this->como($usuario, 'POST', '', $this->datos($curso ?? $this->curso('CUR-'.random_int(1000, 9999)), $cambios))->assertCreated();

        return Convocatoria::query()->where('identificador', $respuesta->json('data.identificador'))->firstOrFail();
    }

    private function estado(User $usuario, Convocatoria $c, string $estado): TestResponse
    {
        return $this->como($usuario, 'POST', $c->identificador.'/estado', ['estado' => $estado, '_revision' => $c->fresh()->etag()]);
    }

    // ---------------------------------------------------------------- permisos

    public function test_sin_sesion_es_401_y_la_secretaria_solo_consulta(): void
    {
        $this->como(null, 'GET')->assertUnauthorized();
        $coordinador = $this->coordinador();
        $c = $this->crear($coordinador);

        $secretaria = $this->usuarioConRol('secretaria', 'secretaria@horus.test');
        $this->como($secretaria, 'GET')->assertOk()->assertJsonCount(1, 'data');
        $this->como($secretaria, 'GET', $c->identificador)->assertOk();
        $this->como($secretaria, 'POST', '', $this->datos($this->curso('CUR-SEC')))->assertForbidden();
        $this->como($secretaria, 'POST', $c->identificador.'/estado', ['estado' => 'abierta', '_revision' => $c->etag()])->assertForbidden();
        $this->como($secretaria, 'PUT', $c->identificador.'/horarios', ['horarios' => [], '_revision' => $c->etag()])->assertForbidden();
        $this->assertSame('borrador', $c->fresh()->estado);
    }

    public function test_el_coordinador_y_el_admin_pueden_todo_y_cerrar_es_un_permiso_aparte(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $coordinador = $this->coordinador();
        $c = $this->crear($admin);
        $this->estado($coordinador, $c, 'abierta')->assertOk();
        $this->estado($coordinador, $c, 'en_curso')->assertOk();

        Role::findByName('coordinador')->revokePermissionTo('convocatorias.cerrar');
        $this->estado($coordinador->fresh(), $c, 'cerrada')->assertForbidden();
        $this->estado($coordinador->fresh(), $c, 'cancelada')->assertForbidden();
        $this->assertSame('en_curso', $c->fresh()->estado);
        $this->estado($admin, $c, 'cerrada')->assertOk();
    }

    public function test_un_coordinador_sin_el_permiso_concreto_no_puede_esa_accion(): void
    {
        $coordinador = $this->coordinador();
        $c = $this->crear($coordinador);
        $rol = Role::findByName('coordinador');

        foreach ([
            ['convocatorias.crear', fn () => $this->como($coordinador->fresh(), 'POST', '', $this->datos($this->curso('CUR-X')))],
            ['convocatorias.editar', fn () => $this->como($coordinador->fresh(), 'PUT', $c->identificador.'/horarios', ['horarios' => [], '_revision' => $c->etag()])],
            ['convocatorias.ver', fn () => $this->como($coordinador->fresh(), 'GET', $c->identificador)],
        ] as [$permiso, $llamada]) {
            $rol->revokePermissionTo($permiso);
            $llamada()->assertForbidden();
            $rol->givePermissionTo($permiso);
        }
        $this->assertSame(1, Convocatoria::query()->count());
    }

    // ---------------------------------------------------------------- alta

    public function test_crear_fija_estado_codigo_y_cupos_y_audita_sin_textos(): void
    {
        $coordinador = $this->coordinador();
        $anio = CarbonImmutable::now('America/Lima')->format('Y');
        $curso = $this->curso();

        $r = $this->como($coordinador, 'POST', '', $this->datos($curso, ['nombre' => 'Edición Reservada', 'cupos_maximos' => 9]))->assertCreated();
        $r->assertJsonPath('data.estado', 'borrador')->assertJsonPath('data.codigo', "CNV-{$anio}-0001")
            ->assertJsonPath('data.cupos', ['maximos' => 9, 'disponibles' => 9, 'ocupados' => 0])
            ->assertJsonPath('data.nombre', 'Edición Reservada')->assertJsonPath('data.curso.codigo', $curso->codigo)
            ->assertJsonPath('data.horarios', [])->assertJsonMissingPath('data.id');

        $this->assertStringNotContainsString('Reservada', (string) json_encode(AuditLog::query()->where('action', 'convocatoria_creada')->sole()->getAttributes()));
        // Sin nombre propio muestra el del curso.
        $this->como($coordinador, 'POST', '', $this->datos($curso))->assertCreated()->assertJsonPath('data.nombre', 'Curso ficticio')->assertJsonPath('data.nombre_propio', null);
    }

    public function test_los_codigos_son_secuenciales_y_no_se_reutilizan(): void
    {
        $coordinador = $this->coordinador();
        $anio = CarbonImmutable::now('America/Lima')->format('Y');

        $uno = $this->crear($coordinador);
        $dos = $this->crear($coordinador);
        $uno->delete();
        $tres = $this->crear($coordinador);

        $this->assertSame(["CNV-{$anio}-0001", "CNV-{$anio}-0002", "CNV-{$anio}-0003"], [$uno->codigo, $dos->codigo, $tres->codigo]);
        $this->assertSame(0, (int) DB::table('secuencias_codigo')->whereIn('tipo', ['PRY', 'SOP'])->count());
    }

    public function test_validaciones_de_alta(): void
    {
        $coordinador = $this->coordinador();
        $curso = $this->curso();

        $this->como($coordinador, 'POST', '', $this->datos($curso, ['modalidad' => 'telepatia']))->assertJsonValidationErrors('modalidad');
        $this->como($coordinador, 'POST', '', $this->datos($curso, ['cupos_maximos' => 0]))->assertJsonValidationErrors('cupos_maximos');
        $this->como($coordinador, 'POST', '', $this->datos($curso, ['cupos_maximos' => 'muchos']))->assertJsonValidationErrors('cupos_maximos');
        $this->como($coordinador, 'POST', '', $this->datos($curso, ['cupos_maximos' => 11]))->assertJsonValidationErrors('cupos_maximos');
        $this->como($coordinador, 'POST', '', $this->datos($curso, ['fecha_inicio' => '10/12/2026']))->assertJsonValidationErrors('fecha_inicio');
        $this->como($coordinador, 'POST', '', $this->datos($curso, ['fecha_inicio' => '2026-12-10', 'fecha_fin' => '2026-12-01']))->assertJsonValidationErrors('fecha_fin');
        $this->como($coordinador, 'POST', '', $this->datos($curso, ['nombre' => '<b>x</b>']))->assertJsonValidationErrors('nombre');
        $this->como($coordinador, 'POST', '', $this->datos($curso, ['estado' => 'abierta']))->assertJsonValidationErrors('estado');
        $this->como($coordinador, 'POST', '', $this->datos($curso, ['cupos_disponibles' => 99]))->assertJsonValidationErrors('cupos_disponibles');
        $this->como($coordinador, 'POST', '', $this->datos($curso, ['codigo' => 'CNV-1']))->assertJsonValidationErrors('codigo');
        $this->como($coordinador, 'POST', '', $this->datos($curso, ['curso_id' => null]))->assertJsonValidationErrors('curso_id');
        $this->como($coordinador, 'POST', '', $this->datos($curso, ['curso_id' => 99999]))->assertJsonValidationErrors('curso_id');
        $this->como($coordinador, 'POST', '', $this->datos($this->curso('CUR-INA', 'inactivo')))->assertJsonValidationErrors('curso_id');
        $this->assertSame(0, Convocatoria::query()->count());
    }

    // ---------------------------------------------------------------- edición y cupos

    public function test_actualizar_exige_la_revision_y_no_edita_una_terminal(): void
    {
        $coordinador = $this->coordinador();
        $c = $this->crear($coordinador);
        $cuerpo = $this->datos($c->curso, ['nombre' => 'Otra edición', 'cupos_maximos' => 10]);
        unset($cuerpo['curso_id']);

        $this->como($coordinador, 'PUT', $c->identificador, $cuerpo)->assertStatus(428);
        $this->como($coordinador, 'PUT', $c->identificador, $cuerpo + ['_revision' => '"convocatoria-'.$c->identificador.'-v9"'])->assertStatus(412);
        $this->como($coordinador, 'PUT', $c->identificador, $cuerpo + ['curso_id' => 1, '_revision' => $c->etag()])->assertJsonValidationErrors('curso_id');
        $this->como($coordinador, 'PUT', $c->identificador, $cuerpo + ['_revision' => $c->etag()])->assertOk()->assertJsonPath('data.nombre', 'Otra edición');
        $this->assertSame(2, $c->fresh()->revision);
        $this->como($coordinador, 'PUT', $c->identificador, $cuerpo + ['_revision' => $c->fresh()->etag()])->assertOk();
        $this->assertSame(2, $c->fresh()->revision);

        $this->estado($coordinador, $c, 'cancelada')->assertOk();
        $this->como($coordinador, 'PUT', $c->identificador, $cuerpo + ['_revision' => $c->fresh()->etag()])->assertStatus(409);
    }

    public function test_los_cupos_se_mueven_con_el_maximo_y_nunca_bajan_de_lo_ocupado(): void
    {
        $coordinador = $this->coordinador();
        $c = $this->crear($coordinador, null, ['cupos_maximos' => 9]);
        // Simula 8 cupos ocupados (1 disponible).
        Convocatoria::query()->whereKey($c->id)->update(['cupos_disponibles' => 1]);
        $base = $this->datos($c->curso);
        unset($base['curso_id']);

        $this->como($coordinador, 'PUT', $c->identificador, [...$base, 'cupos_maximos' => 10, '_revision' => $c->fresh()->etag()])
            ->assertOk()->assertJsonPath('data.cupos', ['maximos' => 10, 'disponibles' => 2, 'ocupados' => 8]);
        $this->como($coordinador, 'PUT', $c->identificador, [...$base, 'cupos_maximos' => 7, '_revision' => $c->fresh()->etag()])->assertJsonValidationErrors('cupos_maximos');
        $this->como($coordinador, 'PUT', $c->identificador, [...$base, 'cupos_maximos' => 8, '_revision' => $c->fresh()->etag()])
            ->assertOk()->assertJsonPath('data.cupos', ['maximos' => 8, 'disponibles' => 0, 'ocupados' => 8]);
    }

    // ---------------------------------------------------------------- estados

    public function test_el_ciclo_de_estados_y_sus_auditorias(): void
    {
        $coordinador = $this->coordinador();
        $c = $this->crear($coordinador);

        $this->estado($coordinador, $c, 'en_curso')->assertStatus(409);
        $this->estado($coordinador, $c, 'cerrada')->assertStatus(409);
        $this->estado($coordinador, $c, 'inventado')->assertJsonValidationErrors('estado');
        $this->estado($coordinador, $c, 'abierta')->assertOk();
        $this->estado($coordinador, $c, 'borrador')->assertStatus(409);
        $this->estado($coordinador, $c, 'en_curso')->assertOk();
        $this->estado($coordinador, $c, 'cerrada')->assertOk();
        $this->estado($coordinador, $c, 'en_curso')->assertStatus(409);
        $this->estado($coordinador, $c, 'cancelada')->assertStatus(409);

        $acciones = AuditLog::query()->where('model_type', Convocatoria::class)->orderBy('id')->pluck('action')->all();
        $this->assertSame(['convocatoria_creada', 'convocatoria_abierta', 'convocatoria_estado_cambiado', 'convocatoria_cerrada'], $acciones);
    }

    public function test_no_se_abre_una_convocatoria_cuyo_periodo_ya_termino(): void
    {
        $coordinador = $this->coordinador();
        $c = $this->crear($coordinador, null, ['fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-01-31']);

        $this->estado($coordinador, $c, 'abierta')->assertJsonValidationErrors('estado');
        $this->assertSame('borrador', $c->fresh()->estado);
        $this->estado($coordinador, $c, 'cancelada')->assertOk();
    }

    public function test_repetir_el_estado_no_cambia_nada_y_la_revision_obsoleta_es_412(): void
    {
        $coordinador = $this->coordinador();
        $c = $this->crear($coordinador);
        $vieja = $c->fresh()->etag();

        $this->estado($coordinador, $c, 'borrador')->assertOk();
        $this->assertSame(1, $c->fresh()->revision);
        $this->estado($coordinador, $c, 'abierta')->assertOk();
        $this->como($coordinador, 'POST', $c->identificador.'/estado', ['estado' => 'en_curso', '_revision' => $vieja])->assertStatus(412);
        $this->assertSame('abierta', $c->fresh()->estado);
    }

    // ---------------------------------------------------------------- horarios

    /** @return array<string, mixed> */
    private function bloque(int $dia, string $inicio, string $fin, array $cambios = []): array
    {
        return ['dia_semana' => $dia, 'hora_inicio' => $inicio, 'hora_fin' => $fin, ...$cambios];
    }

    public function test_los_horarios_se_sustituyen_juntos_y_avanzan_la_revision(): void
    {
        $coordinador = $this->coordinador();
        $c = $this->crear($coordinador);

        $r = $this->como($coordinador, 'PUT', $c->identificador.'/horarios', [
            'horarios' => [$this->bloque(3, '18:00', '20:00'), $this->bloque(1, '18:00', '20:00'), $this->bloque(1, '20:00', '21:00')],
            '_revision' => $c->etag(),
        ])->assertOk();
        // Ordenados por día y hora; extremos que se tocan no se solapan.
        $this->assertSame([[1, '18:00'], [1, '20:00'], [3, '18:00']], array_map(fn (array $h): array => [$h['dia_semana'], $h['hora_inicio']], $r->json('data.horarios')));
        $this->assertSame(2, $c->fresh()->revision);

        $this->como($coordinador, 'PUT', $c->identificador.'/horarios', ['horarios' => [$this->bloque(5, '09:00', '11:00')], '_revision' => $c->fresh()->etag()])
            ->assertOk()->assertJsonCount(1, 'data.horarios');
        $this->como($coordinador, 'PUT', $c->identificador.'/horarios', ['horarios' => [], '_revision' => $c->fresh()->etag()])->assertOk()->assertJsonPath('data.horarios', []);
        $this->assertSame(0, DB::table('convocatoria_horarios')->count());

        $registro = (string) json_encode(AuditLog::query()->where('action', 'horario_modificado')->get()->map->getAttributes());
        $this->assertStringNotContainsString('Docente Ficticio', $registro);
        $this->assertStringNotContainsString('Aula', $registro);
    }

    public function test_un_horario_invalido_o_solapado_no_cambia_nada(): void
    {
        $coordinador = $this->coordinador();
        $c = $this->crear($coordinador);
        $this->como($coordinador, 'PUT', $c->identificador.'/horarios', ['horarios' => [$this->bloque(2, '08:00', '10:00')], '_revision' => $c->etag()])->assertOk();
        $envia = fn (array $horarios, ?string $revision = null) => $this->como($coordinador, 'PUT', $c->identificador.'/horarios', ['horarios' => $horarios, '_revision' => $revision ?? $c->fresh()->etag()]);

        $envia([$this->bloque(2, '08:00', '10:00'), $this->bloque(2, '09:30', '11:00')])->assertJsonValidationErrors('horarios.1.hora_inicio');
        $envia([$this->bloque(2, '08:00', '10:00'), $this->bloque(2, '07:00', '08:30')])->assertJsonValidationErrors('horarios.1.hora_inicio');
        $envia([$this->bloque(2, '10:00', '10:00')])->assertJsonValidationErrors('horarios.0.hora_fin');
        $envia([$this->bloque(2, '11:00', '10:00')])->assertJsonValidationErrors('horarios.0.hora_fin');
        $envia([$this->bloque(8, '08:00', '10:00')])->assertJsonValidationErrors('horarios.0.dia_semana');
        $envia([$this->bloque(0, '08:00', '10:00')])->assertJsonValidationErrors('horarios.0.dia_semana');
        $envia([$this->bloque(2, '8am', '10:00')])->assertJsonValidationErrors('horarios.0.hora_inicio');
        // Los horarios ya no llevan lugar ni docente: cualquier campo de más se rechaza.
        $envia([$this->bloque(2, '08:00', '10:00', ['docente' => 'x'])])->assertJsonValidationErrors('horarios.0');
        $envia([$this->bloque(2, '08:00', '10:00', ['lugar' => 'x'])])->assertJsonValidationErrors('horarios.0');
        $envia([$this->bloque(2, '08:00', '10:00', ['extra' => 'x'])])->assertJsonValidationErrors('horarios.0');
        $envia(array_map(fn (int $n): array => $this->bloque(1 + $n % 7, '08:00', '09:00'), range(0, 20)))->assertJsonValidationErrors('horarios');
        $this->como($coordinador, 'PUT', $c->identificador.'/horarios', ['_revision' => $c->fresh()->etag()])->assertJsonValidationErrors('horarios');

        $this->assertSame(1, DB::table('convocatoria_horarios')->count());
        $this->assertSame(2, $c->fresh()->revision);
    }

    public function test_los_horarios_exigen_revision_y_convocatoria_vigente(): void
    {
        $coordinador = $this->coordinador();
        $c = $this->crear($coordinador);

        $this->como($coordinador, 'PUT', $c->identificador.'/horarios', ['horarios' => []])->assertStatus(428);
        $this->como($coordinador, 'PUT', $c->identificador.'/horarios', ['horarios' => [], '_revision' => '"convocatoria-'.$c->identificador.'-v9"'])->assertStatus(412);
        $this->estado($coordinador, $c, 'cancelada')->assertOk();
        $this->como($coordinador, 'PUT', $c->identificador.'/horarios', ['horarios' => [], '_revision' => $c->fresh()->etag()])->assertStatus(409);
    }

    // ---------------------------------------------------------------- listado

    public function test_el_listado_filtra_por_estado_curso_y_texto(): void
    {
        $coordinador = $this->coordinador();
        $cursoA = $this->curso('CUR-A');
        $uno = $this->crear($coordinador, $cursoA, ['nombre' => 'Edición mañana']);
        $dos = $this->crear($coordinador, null, ['nombre' => 'Edición noche']);
        $this->estado($coordinador, $dos, 'cancelada')->assertOk();

        $this->como($coordinador, 'GET', '?busqueda=noche')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.codigo', $dos->codigo);
        $this->como($coordinador, 'GET', '?busqueda='.$uno->codigo)->assertOk()->assertJsonCount(1, 'data');
        $this->como($coordinador, 'GET', '?estado=cancelada')->assertOk()->assertJsonCount(1, 'data');
        $this->como($coordinador, 'GET', '?vigentes=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.codigo', $uno->codigo);
        $this->como($coordinador, 'GET', '?curso_id='.$cursoA->id)->assertOk()->assertJsonCount(1, 'data');
        $this->como($coordinador, 'GET', '?estado=inventado')->assertOk()->assertJsonCount(2, 'data');
        $this->como($coordinador, 'GET', 'no-es-un-uuid')->assertNotFound();
        $this->como($coordinador, 'GET', '')->assertOk()->assertJsonMissingPath('data.0.id');
    }

    public function test_filtra_por_fechas_expone_meta_cursos_y_matriculados_en_el_detalle(): void
    {
        $admin = $this->usuarioConRol('admin', 'admin@horus.test');
        $soloEdicion = User::factory()->create(['email' => 'edicion@horus.test']);
        $soloEdicion->givePermissionTo(['panel.academico.acceder', 'convocatorias.ver']);

        $curso = $this->curso('CUR-DET');
        $enero = $this->crear($admin, $curso, [
            'nombre' => 'Edición enero',
            'fecha_inicio' => '2027-01-10',
            'fecha_fin' => '2027-02-10',
        ]);
        $marzo = $this->crear($admin, $curso, [
            'nombre' => 'Edición marzo',
            'fecha_inicio' => '2027-03-10',
            'fecha_fin' => '2027-04-10',
        ]);

        $this->como($admin, 'GET', '?desde=2027-01-01&hasta=2027-02-01')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.codigo', $enero->codigo)
            ->assertJsonPath('meta.cursos.0.codigo', 'CUR-DET');
        $this->assertNotSame($enero->codigo, $marzo->codigo);

        $estudiante = \App\Models\Estudiante::create([
            'dni' => '76543210',
            'nombres' => 'Lucía',
            'apellidos' => 'Paredes',
            'email' => 'lucia@horus.test',
            'telefono' => '987654321',
            'estado' => 'activo',
        ]);
        $matricula = \App\Models\Matricula::create([
            'estudiante_id' => $estudiante->id,
            'curso_id' => $curso->id,
            'convocatoria_id' => $enero->id,
            'fecha_matricula' => '2027-01-05',
            'estado' => 'pendiente',
            'monto_pagado' => 0,
        ]);
        $this->actingAs($admin)->withHeaders(['X-Horus-Web-Token' => self::TOKEN])
            ->postJson("/api/panel/v1/academico/matriculas/{$matricula->id}/obligacion", [
                'concepto' => 'Matrícula enero',
                'plan' => 'completo',
                'monto_total' => 250,
            ])->assertCreated();

        $this->como($admin, 'GET', $enero->identificador)
            ->assertOk()
            ->assertJsonCount(1, 'data.matriculados')
            ->assertJsonPath('data.matriculados.0.estudiante.dni', '76543210')
            ->assertJsonPath('data.matriculados.0.estudiante.telefono', '987654321')
            ->assertJsonPath('data.matriculados.0.obligacion.estado', 'pendiente');

        $this->como($soloEdicion, 'GET', $enero->identificador)
            ->assertOk()
            ->assertJsonMissingPath('data.matriculados');
    }
}
