<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use App\Support\AuditoriaAcademica;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Cálculo de diferencias, listas permitidas y efecto sobre cupos de AuditoriaAcademica.
 *
 * Casi todo es puro (modelos en memoria); la base de datos (SQLite en memoria vía RefreshDatabase) solo se
 * usa en las pruebas que comprueban lo que realmente se persiste en audit_logs.
 */
class AuditoriaAcademicaTest extends TestCase
{
    use RefreshDatabase;

    /** Estudiante «persistido» en memoria: atributos originales sincronizados y sin cambios pendientes. */
    private function persistido(): Estudiante
    {
        $estudiante = new Estudiante;
        $estudiante->setRawAttributes([
            'id' => 5,
            'dni' => '70000001',
            'nombres' => 'Nombre',
            'apellidos' => 'Apellido',
            'email' => 'a@example.test',
            'fecha_nacimiento' => '2000-04-15 00:00:00',
            'estado' => 'activo',
            'updated_at' => '2026-01-01 00:00:00',
        ], true);
        $estudiante->exists = true;

        return $estudiante;
    }

    public function test_lista_permitida_de_estudiante(): void
    {
        $this->assertEqualsCanonicalizing(
            ['estado', 'dni', 'nombres', 'apellidos', 'email', 'telefono', 'fecha_nacimiento', 'direccion'],
            AuditoriaAcademica::camposPermitidos(AuditoriaAcademica::ESTUDIANTE),
        );
    }

    public function test_lista_permitida_de_curso_solo_da_valor_a_precio_y_cupos(): void
    {
        $curso = new Curso;
        $curso->setRawAttributes([
            'id' => 9, 'codigo' => 'A', 'nombre' => 'N', 'descripcion' => 'D', 'precio' => '250.00',
            'cupos_maximos' => 20, 'cupos_disponibles' => 20, 'estado' => 'activo', 'horario' => 'H',
        ], true);
        $curso->exists = true;
        $curso->fill(['precio' => 300, 'cupos_maximos' => 40, 'nombre' => 'NUEVO', 'estado' => 'archivado', 'horario' => 'H']);

        $diferencias = AuditoriaAcademica::diferencias($curso, AuditoriaAcademica::CURSO);

        $this->assertEqualsCanonicalizing(['precio', 'cupos_maximos', 'nombre', 'estado'], $diferencias['campos']);
        $this->assertSame(['precio' => '250.00', 'cupos_maximos' => 20], $diferencias['antes']);
        $this->assertSame(['precio' => '300.00', 'cupos_maximos' => 40], $diferencias['despues']);
        $this->assertStringNotContainsString('NUEVO', json_encode($diferencias, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('archivado', json_encode($diferencias, JSON_THROW_ON_ERROR));
    }

    public function test_entidad_desconocida_lanza_excepcion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AuditoriaAcademica::camposPermitidos('inexistente');
    }

    public function test_sin_cambios_no_hay_diferencias_ni_siquiera_con_la_misma_fecha(): void
    {
        $estudiante = $this->persistido();
        $estudiante->fill(['fecha_nacimiento' => '2000-04-15', 'estado' => 'activo', 'nombres' => 'Nombre']);

        $diferencias = AuditoriaAcademica::diferencias($estudiante, AuditoriaAcademica::ESTUDIANTE);

        $this->assertSame(['campos' => [], 'antes' => [], 'despues' => []], $diferencias);
        $this->assertFalse(AuditoriaAcademica::registrarActualizacion('x', $estudiante, $diferencias, 1));
    }

    public function test_solo_estado_lleva_valores_y_el_resto_solo_el_nombre(): void
    {
        $estudiante = $this->persistido();
        $estudiante->fill(['estado' => 'inactivo', 'dni' => '70000002', 'email' => 'b@example.test', 'fecha_nacimiento' => '1999-01-01']);

        $diferencias = AuditoriaAcademica::diferencias($estudiante, AuditoriaAcademica::ESTUDIANTE);

        $this->assertEqualsCanonicalizing(['estado', 'dni', 'email', 'fecha_nacimiento'], $diferencias['campos']);
        $this->assertSame(['estado' => 'activo'], $diferencias['antes']);
        $this->assertSame(['estado' => 'inactivo'], $diferencias['despues']);
        $this->assertStringNotContainsString('70000002', json_encode($diferencias, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('b@example.test', json_encode($diferencias, JSON_THROW_ON_ERROR));
    }

    public function test_excluye_updated_at_y_campos_fuera_de_la_lista(): void
    {
        $estudiante = $this->persistido();
        $estudiante->forceFill(['updated_at' => '2026-02-02 00:00:00', 'remember_token' => 'TOKEN-SECRETO', 'clave_interna' => 'x']);

        $diferencias = AuditoriaAcademica::diferencias($estudiante, AuditoriaAcademica::ESTUDIANTE);

        $this->assertSame([], $diferencias['campos']);
        $this->assertStringNotContainsString('TOKEN-SECRETO', json_encode($diferencias, JSON_THROW_ON_ERROR));
    }

    // ---------------------------------------------------------------------------------------------
    // Sprint 21.2 · Fase 1 — núcleo de auditoría de matrículas
    // ---------------------------------------------------------------------------------------------

    /**
     * Matrícula «persistida» en memoria, vigente, con observaciones privadas y sin cambios pendientes.
     *
     * @param  array<string, mixed>  $atributos
     */
    private function matricula(array $atributos = []): Matricula
    {
        $matricula = new Matricula;
        $matricula->setRawAttributes($atributos + [
            'id' => 11,
            'estudiante_id' => 5,
            'curso_id' => 3,
            'fecha_matricula' => '2026-03-01',
            'estado' => 'pendiente',
            'monto_pagado' => '100.00',
            'observaciones' => 'PRIVATE-NOTE',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
            'deleted_at' => null,
        ], true);
        $matricula->exists = true;

        return $matricula;
    }

    private function aJson(mixed $valor): string
    {
        return json_encode($valor, JSON_THROW_ON_ERROR);
    }

    public function test_lista_permitida_de_matricula(): void
    {
        $this->assertEqualsCanonicalizing(
            ['estudiante_id', 'curso_id', 'fecha_matricula', 'estado', 'monto_pagado', 'observaciones'],
            AuditoriaAcademica::camposPermitidos(AuditoriaAcademica::MATRICULA),
        );
    }

    public function test_matricula_registra_identificadores_monto_fecha_y_estado_con_antes_y_despues(): void
    {
        $matricula = $this->matricula();
        $matricula->fill([
            'estudiante_id' => 6,
            'curso_id' => 4,
            'fecha_matricula' => '2026-03-02',
            'estado' => 'pagada',
            'monto_pagado' => '150.5',
        ]);

        $diferencias = AuditoriaAcademica::diferencias($matricula, AuditoriaAcademica::MATRICULA);

        $this->assertEqualsCanonicalizing(
            ['estudiante_id', 'curso_id', 'fecha_matricula', 'estado', 'monto_pagado'],
            $diferencias['campos'],
        );
        $this->assertSame([
            'estudiante_id' => 5,
            'curso_id' => 3,
            'fecha_matricula' => '2026-03-01',
            'estado' => 'pendiente',
            'monto_pagado' => '100.00',
        ], $diferencias['antes']);
        $this->assertSame([
            'estudiante_id' => 6,
            'curso_id' => 4,
            'fecha_matricula' => '2026-03-02',
            'estado' => 'pagada',
            'monto_pagado' => '150.50',
        ], $diferencias['despues']);
    }

    public function test_matricula_solo_incluye_lo_que_cambio(): void
    {
        $matricula = $this->matricula();
        $matricula->fill(['estado' => 'pagada', 'curso_id' => 3, 'monto_pagado' => '100.00']);

        $diferencias = AuditoriaAcademica::diferencias($matricula, AuditoriaAcademica::MATRICULA);

        $this->assertSame(['estado'], $diferencias['campos']);
        $this->assertSame(['estado' => 'pendiente'], $diferencias['antes']);
        $this->assertSame(['estado' => 'pagada'], $diferencias['despues']);
    }

    public function test_matricula_sin_cambios_reales_no_genera_diferencias_con_formatos_equivalentes(): void
    {
        $matricula = $this->matricula();
        $matricula->fill([
            'fecha_matricula' => '2026-03-01',
            'monto_pagado' => '100',
            'estado' => 'pendiente',
            'estudiante_id' => '5',
            'curso_id' => '3',
            'observaciones' => 'PRIVATE-NOTE',
        ]);

        $diferencias = AuditoriaAcademica::diferencias($matricula, AuditoriaAcademica::MATRICULA);

        $this->assertSame(['campos' => [], 'antes' => [], 'despues' => []], $diferencias);
        $this->assertFalse(AuditoriaAcademica::registrarActualizacion('matricula_actualizada', $matricula, $diferencias, 1));
        $this->assertSame(0, AuditLog::count());
    }

    public function test_matricula_excluye_atributos_tecnicos_y_campos_ajenos(): void
    {
        $matricula = $this->matricula();
        $matricula->forceFill([
            'created_at' => '2026-02-02 00:00:00',
            'updated_at' => '2026-02-02 00:00:00',
            'deleted_at' => '2026-02-02 00:00:00',
            'dni' => '70000009',
            'password' => 'SECRETO-1',
            'remember_token' => 'TOKEN-SECRETO',
        ]);

        $diferencias = AuditoriaAcademica::diferencias($matricula, AuditoriaAcademica::MATRICULA);

        $this->assertSame(['campos' => [], 'antes' => [], 'despues' => []], $diferencias);
    }

    /**
     * D10-a (Fase 2): una fecha con hora sobre una fila con la misma fecha deja el atributo «sucio» en Eloquent
     * (se compara con fromDateTime), pero no es un cambio real y no debe auditarse como tal.
     *
     * @return array<string, array{0: string}>
     */
    public static function fechasConLaMismaFecha(): array
    {
        return [
            'con hora' => ['2026-03-01 10:30:00'],
            'con hora a medianoche' => ['2026-03-01 00:00:00'],
            'formato ISO' => ['2026-03-01T10:30:00'],
        ];
    }

    #[DataProvider('fechasConLaMismaFecha')]
    public function test_matricula_una_fecha_con_hora_de_la_misma_fecha_no_es_un_cambio(string $fecha): void
    {
        $matricula = $this->matricula();
        $matricula->fill(['fecha_matricula' => $fecha]);

        $diferencias = AuditoriaAcademica::diferencias($matricula, AuditoriaAcademica::MATRICULA);

        $this->assertSame(['campos' => [], 'antes' => [], 'despues' => []], $diferencias);
        $this->assertFalse(AuditoriaAcademica::registrarActualizacion('matricula_actualizada', $matricula, $diferencias, 1));
        $this->assertSame(0, AuditLog::count());
    }

    public function test_matricula_con_fecha_de_otro_dia_si_es_un_cambio_aunque_traiga_hora(): void
    {
        $matricula = $this->matricula();
        $matricula->fill(['fecha_matricula' => '2026-03-02 10:30:00', 'estado' => 'pagada']);

        $diferencias = AuditoriaAcademica::diferencias($matricula, AuditoriaAcademica::MATRICULA);

        $this->assertEqualsCanonicalizing(['fecha_matricula', 'estado'], $diferencias['campos']);
        $this->assertSame('2026-03-01', $diferencias['antes']['fecha_matricula']);
        $this->assertSame('2026-03-02', $diferencias['despues']['fecha_matricula']);
    }

    public function test_matricula_una_fecha_con_hora_no_oculta_otros_cambios_reales(): void
    {
        $matricula = $this->matricula();
        $matricula->fill(['fecha_matricula' => '2026-03-01 10:30:00', 'estado' => 'pagada', 'observaciones' => 'PRIVATE-NOTE-2']);

        $diferencias = AuditoriaAcademica::diferencias($matricula, AuditoriaAcademica::MATRICULA);

        $this->assertEqualsCanonicalizing(['estado', 'observaciones'], $diferencias['campos']);
        $this->assertSame(['estado' => 'pendiente'], $diferencias['antes']);
        $this->assertSame(['estado' => 'pagada'], $diferencias['despues']);
    }

    public function test_la_normalizacion_de_valores_iguales_no_altera_estudiantes_ni_cursos(): void
    {
        $estudiante = $this->persistido();
        $estudiante->fill(['estado' => 'inactivo', 'fecha_nacimiento' => '2000-04-15 10:30:00']);
        $dEstudiante = AuditoriaAcademica::diferencias($estudiante, AuditoriaAcademica::ESTUDIANTE);
        // «fecha_nacimiento» solo lleva su nombre: sigue la regla de siempre (atributo sucio ⇒ se nombra).
        $this->assertEqualsCanonicalizing(['estado', 'fecha_nacimiento'], $dEstudiante['campos']);
        $this->assertSame(['estado' => 'activo'], $dEstudiante['antes']);

        $curso = new Curso;
        $curso->setRawAttributes(['id' => 9, 'codigo' => 'A', 'nombre' => 'N', 'precio' => '250.00', 'cupos_maximos' => 20, 'cupos_disponibles' => 20, 'estado' => 'activo'], true);
        $curso->exists = true;
        $curso->fill(['precio' => 300, 'cupos_disponibles' => 5]);
        $dCurso = AuditoriaAcademica::diferencias($curso, AuditoriaAcademica::CURSO);
        $this->assertEqualsCanonicalizing(['precio', 'cupos_disponibles'], $dCurso['campos']);
        $this->assertSame(['precio' => '250.00', 'cupos_disponibles' => 20], $dCurso['antes']);
        $this->assertSame(['precio' => '300.00', 'cupos_disponibles' => 5], $dCurso['despues']);
    }

    public function test_observaciones_solo_registra_el_nombre_y_nunca_el_contenido(): void
    {
        $matricula = $this->matricula();
        $matricula->fill(['observaciones' => 'PRIVATE-NOTE-NUEVA DNI 70000001', 'estado' => 'pagada']);

        $diferencias = AuditoriaAcademica::diferencias($matricula, AuditoriaAcademica::MATRICULA);

        $this->assertEqualsCanonicalizing(['estado', 'observaciones'], $diferencias['campos']);
        $this->assertArrayNotHasKey('observaciones', $diferencias['antes']);
        $this->assertArrayNotHasKey('observaciones', $diferencias['despues']);
        $this->assertStringNotContainsString('PRIVATE-NOTE', $this->aJson($diferencias));
        $this->assertStringNotContainsString('70000001', $this->aJson($diferencias));
    }

    public function test_valores_nulos_se_registran_como_null_en_antes_y_despues(): void
    {
        $sinMonto = $this->matricula(['monto_pagado' => null]);
        $sinMonto->fill(['monto_pagado' => 50]);
        $alta = AuditoriaAcademica::diferencias($sinMonto, AuditoriaAcademica::MATRICULA);
        $this->assertSame(['monto_pagado' => null], $alta['antes']);
        $this->assertSame(['monto_pagado' => '50.00'], $alta['despues']);

        $conMonto = $this->matricula();
        $conMonto->fill(['monto_pagado' => null]);
        $baja = AuditoriaAcademica::diferencias($conMonto, AuditoriaAcademica::MATRICULA);
        $this->assertSame(['monto_pagado' => '100.00'], $baja['antes']);
        $this->assertSame(['monto_pagado' => null], $baja['despues']);
    }

    public function test_detalle_de_creacion_de_matricula_incluye_valores_autorizados_y_efecto(): void
    {
        $matricula = $this->matricula(['monto_pagado' => null]);

        $detalle = AuditoriaAcademica::detalleCreacion($matricula, AuditoriaAcademica::MATRICULA, AuditoriaAcademica::EFECTO_OCUPA);

        $this->assertEqualsCanonicalizing(
            ['estudiante_id', 'curso_id', 'fecha_matricula', 'estado', 'observaciones'],
            $detalle['campos'],
        );
        $this->assertSame([
            'estudiante_id' => 5,
            'curso_id' => 3,
            'fecha_matricula' => '2026-03-01',
            'estado' => 'pendiente',
        ], $detalle['valores']);
        $this->assertSame('ocupa', $detalle['efecto_cupo']);
        $this->assertStringNotContainsString('PRIVATE-NOTE', $this->aJson($detalle));
    }

    public function test_sin_efecto_la_auditoria_de_estudiantes_y_cursos_conserva_su_formato(): void
    {
        $estudiante = $this->persistido();
        $detalle = AuditoriaAcademica::detalleCreacion($estudiante, AuditoriaAcademica::ESTUDIANTE);

        $this->assertSame(['campos', 'valores'], array_keys($detalle));
        $this->assertSame(['estado' => 'activo'], $detalle['valores']);
        $this->assertStringNotContainsString('70000001', $this->aJson($detalle));
    }

    /**
     * @return array<string, array{0: ?string, 1: ?int, 2: ?string, 3: ?int, 4: string}>
     */
    public static function casosDeEfectoSobreCupos(): array
    {
        return [
            'alta pendiente' => [null, null, 'pendiente', 3, 'ocupa'],
            'alta pagada' => [null, null, 'pagada', 3, 'ocupa'],
            'alta nacida anulada' => [null, null, 'anulada', 3, 'ninguno'],
            'anular pendiente' => ['pendiente', 3, 'anulada', 3, 'libera'],
            'anular pagada' => ['pagada', 3, 'anulada', 3, 'libera'],
            'anular con cambio de curso libera sin trasladar' => ['pendiente', 3, 'anulada', 4, 'libera'],
            'ya anulada' => ['anulada', 3, 'anulada', 3, 'ninguno'],
            'ya anulada con cambio de curso' => ['anulada', 3, 'anulada', 4, 'ninguno'],
            'reactivar anulada' => ['anulada', 3, 'pendiente', 3, 'ocupa'],
            'reactivar anulada en otro curso' => ['anulada', 3, 'pagada', 4, 'ocupa'],
            'eliminar vigente' => ['pendiente', 3, null, null, 'libera'],
            'eliminar anulada' => ['anulada', 3, null, null, 'ninguno'],
            'restaurar vigente' => [null, 3, 'pagada', 3, 'ocupa'],
            'restaurar anulada' => [null, 3, 'anulada', 3, 'ninguno'],
            'pendiente a pagada' => ['pendiente', 3, 'pagada', 3, 'ninguno'],
            'traslado pendiente' => ['pendiente', 3, 'pendiente', 4, 'traslada'],
            'traslado con pago' => ['pendiente', 3, 'pagada', 4, 'traslada'],
            'sin cambios relevantes' => ['pagada', 3, 'pagada', 3, 'ninguno'],
            'nada antes ni despues' => [null, null, null, null, 'ninguno'],
            'curso desconocido contra conocido cuenta como cambio' => ['pendiente', null, 'pendiente', 3, 'traslada'],
        ];
    }

    #[DataProvider('casosDeEfectoSobreCupos')]
    public function test_efecto_sobre_cupos(?string $estadoAntes, ?int $cursoAntes, ?string $estadoDespues, ?int $cursoDespues, string $esperado): void
    {
        $efecto = AuditoriaAcademica::efectoCupo($estadoAntes, $cursoAntes, $estadoDespues, $cursoDespues);

        $this->assertSame($esperado, $efecto);
        $this->assertContains($efecto, AuditoriaAcademica::efectosPermitidos());
    }

    public function test_los_cuatro_efectos_son_la_lista_cerrada_y_todos_son_alcanzables(): void
    {
        $this->assertSame(['ocupa', 'libera', 'traslada', 'ninguno'], AuditoriaAcademica::efectosPermitidos());

        $producidos = array_unique(array_map(
            static fn (array $caso): string => $caso[4],
            array_values(self::casosDeEfectoSobreCupos()),
        ));
        $this->assertEqualsCanonicalizing(AuditoriaAcademica::efectosPermitidos(), $producidos);
    }

    #[DataProvider('estadosDesconocidos')]
    public function test_estado_desconocido_lanza_excepcion(string $estado): void
    {
        $this->expectException(InvalidArgumentException::class);
        AuditoriaAcademica::efectoCupo($estado, 1, 'pendiente', 1);
    }

    #[DataProvider('estadosDesconocidos')]
    public function test_estado_desconocido_despues_lanza_excepcion(string $estado): void
    {
        $this->expectException(InvalidArgumentException::class);
        AuditoriaAcademica::efectoCupo('pendiente', 1, $estado, 1);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function estadosDesconocidos(): array
    {
        return ['vacio' => [''], 'mayusculas' => ['ANULADA'], 'otro' => ['archivada']];
    }

    public function test_un_efecto_fuera_de_la_lista_cerrada_se_rechaza_en_todos_los_registros(): void
    {
        $matricula = $this->matricula();

        try {
            AuditoriaAcademica::detalleCreacion($matricula, AuditoriaAcademica::MATRICULA, 'otro');
            $this->fail('detalleCreacion aceptó un efecto inválido.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $matricula->fill(['estado' => 'pagada']);
        $diferencias = AuditoriaAcademica::diferencias($matricula, AuditoriaAcademica::MATRICULA);
        try {
            AuditoriaAcademica::registrarActualizacion('matricula_actualizada', $matricula, $diferencias, 1, 'Ocupa');
            $this->fail('registrarActualizacion aceptó un efecto inválido.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        try {
            AuditoriaAcademica::detalleOperacionMatricula($matricula, AuditoriaAcademica::OPERACION_ELIMINACION, 'libero');
            $this->fail('detalleOperacionMatricula aceptó un efecto inválido.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, AuditLog::count());
    }

    public function test_eliminacion_y_restauracion_solo_registran_operacion_efecto_curso_y_estado(): void
    {
        $matricula = $this->matricula(['estado' => 'pagada', 'curso_id' => 8]);

        $this->assertSame([
            'operacion' => 'eliminacion_logica',
            'efecto_cupo' => 'libera',
            'curso_id' => 8,
            'estado' => 'pagada',
        ], AuditoriaAcademica::detalleOperacionMatricula($matricula, AuditoriaAcademica::OPERACION_ELIMINACION, AuditoriaAcademica::EFECTO_LIBERA));

        $anulada = $this->matricula(['estado' => 'anulada']);
        $this->assertSame([
            'operacion' => 'restauracion',
            'efecto_cupo' => 'ninguno',
            'curso_id' => 3,
            'estado' => 'anulada',
        ], AuditoriaAcademica::detalleOperacionMatricula($anulada, AuditoriaAcademica::OPERACION_RESTAURACION, AuditoriaAcademica::EFECTO_NINGUNO));

        $json = $this->aJson(AuditoriaAcademica::detalleOperacionMatricula($matricula, AuditoriaAcademica::OPERACION_ELIMINACION, AuditoriaAcademica::EFECTO_LIBERA));
        foreach (['PRIVATE-NOTE', '100.00', '2026-03-01', 'estudiante_id'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $json);
        }
    }

    public function test_operacion_de_matricula_desconocida_lanza_excepcion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AuditoriaAcademica::detalleOperacionMatricula($this->matricula(), 'texto libre con DNI 70000001', AuditoriaAcademica::EFECTO_NINGUNO);
    }

    public function test_creacion_de_matricula_se_persiste_con_actor_valores_y_efecto(): void
    {
        $actor = User::factory()->create();
        $matricula = $this->matricula();

        $registro = AuditoriaAcademica::registrarCreacion('matricula_creada', $matricula, AuditoriaAcademica::MATRICULA, $actor->id, AuditoriaAcademica::EFECTO_OCUPA);

        $guardado = AuditLog::findOrFail($registro->id);
        $this->assertSame('matricula_creada', $guardado->action);
        $this->assertSame(Matricula::class, $guardado->model_type);
        $this->assertSame(11, (int) $guardado->model_id);
        $this->assertSame($actor->id, $guardado->user_id);
        $this->assertSame(1, $guardado->changes['esquema']);
        $this->assertSame('ocupa', $guardado->changes['efecto_cupo']);
        $this->assertSame('pendiente', $guardado->changes['valores']['estado']);
        $this->assertSame('100.00', $guardado->changes['valores']['monto_pagado']);
        $this->assertStringNotContainsString('PRIVATE-NOTE', $this->aJson($guardado->getAttributes()));
    }

    public function test_anulacion_por_put_con_otros_cambios_genera_un_unico_evento_con_todas_las_diferencias(): void
    {
        $actor = User::factory()->create();
        $matricula = $this->matricula(['estado' => 'pagada']);
        $matricula->fill(['estado' => 'anulada', 'monto_pagado' => '0', 'observaciones' => 'PRIVATE-NOTE-ANULADA']);

        $efecto = AuditoriaAcademica::efectoCupo('pagada', 3, 'anulada', 3);
        $diferencias = AuditoriaAcademica::diferencias($matricula, AuditoriaAcademica::MATRICULA);

        $this->assertTrue(AuditoriaAcademica::registrarActualizacion('matricula_anulada', $matricula, $diferencias, $actor->id, $efecto));

        $this->assertSame(1, AuditLog::count());
        $guardado = AuditLog::sole();
        $this->assertSame('matricula_anulada', $guardado->action);
        $this->assertSame('libera', $guardado->changes['efecto_cupo']);
        $this->assertEqualsCanonicalizing(['estado', 'monto_pagado', 'observaciones'], $guardado->changes['campos']);
        $this->assertSame(['estado' => 'pagada', 'monto_pagado' => '100.00'], $guardado->changes['antes']);
        $this->assertSame(['estado' => 'anulada', 'monto_pagado' => '0.00'], $guardado->changes['despues']);
        $this->assertStringNotContainsString('PRIVATE-NOTE', $this->aJson($guardado->getAttributes()));
    }

    public function test_anular_una_matricula_ya_anulada_no_genera_evento_y_su_efecto_es_ninguno(): void
    {
        $matricula = $this->matricula(['estado' => 'anulada']);
        $matricula->fill(['estado' => 'anulada']);

        $diferencias = AuditoriaAcademica::diferencias($matricula, AuditoriaAcademica::MATRICULA);

        $this->assertSame('ninguno', AuditoriaAcademica::efectoCupo('anulada', 3, 'anulada', 3));
        $this->assertFalse(AuditoriaAcademica::registrarActualizacion('matricula_anulada', $matricula, $diferencias, 1, 'ninguno'));
        $this->assertSame(0, AuditLog::count());
    }

    public function test_traslado_registra_curso_anterior_y_nuevo_con_efecto_traslada(): void
    {
        $actor = User::factory()->create();
        $matricula = $this->matricula();
        $matricula->fill(['curso_id' => 9]);

        $efecto = AuditoriaAcademica::efectoCupo('pendiente', 3, 'pendiente', 9);
        $diferencias = AuditoriaAcademica::diferencias($matricula, AuditoriaAcademica::MATRICULA);
        AuditoriaAcademica::registrarActualizacion('matricula_actualizada', $matricula, $diferencias, $actor->id, $efecto);

        $guardado = AuditLog::sole();
        $this->assertSame('traslada', $guardado->changes['efecto_cupo']);
        $this->assertSame(['curso_id' => 3], $guardado->changes['antes']);
        $this->assertSame(['curso_id' => 9], $guardado->changes['despues']);
    }

    public function test_eliminacion_y_restauracion_se_persisten_sin_valores_ajenos(): void
    {
        $actor = User::factory()->create();
        $matricula = $this->matricula();

        AuditoriaAcademica::registrarOperacionMatricula('matricula_eliminada', $matricula, AuditoriaAcademica::OPERACION_ELIMINACION, AuditoriaAcademica::EFECTO_LIBERA, $actor->id);
        AuditoriaAcademica::registrarOperacionMatricula('matricula_restaurada', $matricula, AuditoriaAcademica::OPERACION_RESTAURACION, AuditoriaAcademica::EFECTO_OCUPA, $actor->id);

        $eliminada = AuditLog::where('action', 'matricula_eliminada')->sole();
        $restaurada = AuditLog::where('action', 'matricula_restaurada')->sole();
        $this->assertSame(['esquema' => 1, 'operacion' => 'eliminacion_logica', 'efecto_cupo' => 'libera', 'curso_id' => 3, 'estado' => 'pendiente'], $eliminada->changes);
        $this->assertSame(['esquema' => 1, 'operacion' => 'restauracion', 'efecto_cupo' => 'ocupa', 'curso_id' => 3, 'estado' => 'pendiente'], $restaurada->changes);
        $this->assertSame($actor->id, $eliminada->user_id);
        $this->assertSame($actor->id, $restaurada->user_id);
    }

    public function test_los_registradores_existentes_no_cambian_sin_efecto(): void
    {
        $actor = User::factory()->create();
        $estudiante = $this->persistido();
        $estudiante->fill(['estado' => 'inactivo']);
        $diferencias = AuditoriaAcademica::diferencias($estudiante, AuditoriaAcademica::ESTUDIANTE);

        AuditoriaAcademica::registrarActualizacion('estudiante_actualizado', $estudiante, $diferencias, $actor->id);
        AuditoriaAcademica::registrarOperacion('estudiante_eliminado', $estudiante, 'eliminacion_logica', $actor->id);

        $this->assertSame(
            ['esquema' => 1, 'campos' => ['estado'], 'antes' => ['estado' => 'activo'], 'despues' => ['estado' => 'inactivo']],
            AuditLog::where('action', 'estudiante_actualizado')->sole()->changes,
        );
        $this->assertSame(
            ['esquema' => 1, 'operacion' => 'eliminacion_logica'],
            AuditLog::where('action', 'estudiante_eliminado')->sole()->changes,
        );
    }

    public function test_los_campos_ajenos_o_sensibles_de_una_matricula_nunca_llegan_a_la_auditoria(): void
    {
        $actor = User::factory()->create();
        $matricula = $this->matricula();
        $matricula->forceFill(['dni' => '70000009', 'password' => 'SECRETO-1', 'remember_token' => 'TOKEN-SECRETO', 'session_id' => 'SESION-1']);

        AuditoriaAcademica::registrarCreacion('matricula_creada', $matricula, AuditoriaAcademica::MATRICULA, $actor->id, AuditoriaAcademica::EFECTO_OCUPA);
        AuditoriaAcademica::registrarOperacionMatricula('matricula_eliminada', $matricula, AuditoriaAcademica::OPERACION_ELIMINACION, AuditoriaAcademica::EFECTO_LIBERA, $actor->id);

        foreach (AuditLog::all() as $registro) {
            $json = $this->aJson($registro->getAttributes());
            foreach (['70000009', 'SECRETO-1', 'TOKEN-SECRETO', 'SESION-1', 'PRIVATE-NOTE'] as $prohibido) {
                $this->assertStringNotContainsString($prohibido, $json);
            }
        }
    }

    // ---- actorDe (Sprint 21.2, Fase 5): el actor siempre es explícito y válido ----------------------

    public function test_actor_de_devuelve_el_identificador_entero_del_usuario_autenticado(): void
    {
        $this->assertSame(7, AuditoriaAcademica::actorDe($this->peticionConIdentificador(7)));
    }

    public function test_actor_de_acepta_un_identificador_numerico_en_texto_y_lo_convierte_a_entero(): void
    {
        $this->assertSame(12, AuditoriaAcademica::actorDe($this->peticionConIdentificador('12')));
    }

    public function test_actor_de_rechaza_con_403_una_peticion_sin_usuario(): void
    {
        $this->assertActorRechazado(Request::create('/'));
    }

    #[DataProvider('identificadoresInvalidos')]
    public function test_actor_de_rechaza_con_403_un_identificador_no_numerico(mixed $identificador): void
    {
        $this->assertActorRechazado($this->peticionConIdentificador($identificador));
    }

    /** @return array<string, array{0: mixed}> */
    public static function identificadoresInvalidos(): array
    {
        return ['texto' => ['abc'], 'vacio' => [''], 'mixto' => ['12abc'], 'negativo' => ['-3'], 'nulo' => [null], 'decimal' => [1.5]];
    }

    private function assertActorRechazado(Request $peticion): void
    {
        try {
            AuditoriaAcademica::actorDe($peticion);
            $this->fail('actorDe debía rechazar la petición con 403.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    private function peticionConIdentificador(mixed $identificador): Request
    {
        $usuario = new class($identificador) implements Authenticatable
        {
            public function __construct(private readonly mixed $identificador) {}

            public function getAuthIdentifierName(): string
            {
                return 'id';
            }

            public function getAuthIdentifier(): mixed
            {
                return $this->identificador;
            }

            public function getAuthPasswordName(): string
            {
                return 'password';
            }

            public function getAuthPassword(): string
            {
                return '';
            }

            public function getRememberToken(): ?string
            {
                return null;
            }

            public function setRememberToken($value): void {}

            public function getRememberTokenName(): string
            {
                return 'remember_token';
            }
        };

        $peticion = Request::create('/');
        $peticion->setUserResolver(static fn () => $usuario);

        return $peticion;
    }
}
