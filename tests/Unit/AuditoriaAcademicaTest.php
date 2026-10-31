<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Curso;
use App\Models\Estudiante;
use App\Support\AuditoriaAcademica;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Cálculo de diferencias y listas permitidas de AuditoriaAcademica, sin escribir en la base de datos.
 */
class AuditoriaAcademicaTest extends TestCase
{
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
}
