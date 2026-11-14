<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LlamaMatriculasDelPanel;
use Tests\TestCase;

class AdminMatriculaContextTest extends TestCase
{
    use LlamaMatriculasDelPanel;
    use RefreshDatabase;

    /**
     * Las fichas de estudiante y de curso y el formulario de matrícula los sirve apps/web: el enlace «Nueva matrícula»
     * y la preselección son de su UI. Aquí queda la garantía de servidor: abrir el formulario no escribe nada y
     * guardar con el contexto elegido ocupa un cupo.
     */
    public function test_registrar_con_el_contexto_elegido_ocupa_un_solo_cupo(): void
    {
        [$estudiante, $curso] = $this->records();
        $this->actingAs($this->adminUser());

        $this->assertDatabaseCount('matriculas', 0);
        $this->assertSame(2, $curso->fresh()->cupos_disponibles);

        $this->panelMatriculas('POST', '', [
            'estudiante_id' => $estudiante->id, 'curso_id' => $curso->id,
            'fecha_matricula' => '2026-10-28', 'estado' => 'pendiente',
        ])->assertCreated();

        $this->assertDatabaseCount('matriculas', 1);
        $this->assertSame(1, $curso->fresh()->cupos_disponibles);
    }

    public function test_el_personal_de_solo_lectura_no_puede_registrar_ni_pedir_opciones_de_alta(): void
    {
        [$estudiante, $curso] = $this->records();
        $this->adminUser();
        $lector = User::factory()->create();
        $lector->assignRole('coordinador');
        $lector->roles()->firstOrFail()->syncPermissions(['estudiantes.ver', 'cursos.ver']);
        $this->actingAs($lector);

        $this->panelMatriculas('POST', '', [
            'estudiante_id' => $estudiante->id, 'curso_id' => $curso->id,
            'fecha_matricula' => '2026-10-28', 'estado' => 'pendiente',
        ])->assertForbidden();
        $this->panelMatriculas('GET', 'opciones/estudiantes', ['contexto' => 'crear'])->assertForbidden();
        $this->assertDatabaseCount('matriculas', 0);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidContext(): iterable
    {
        yield 'array' => [[1]];
        yield 'missing' => ['999999'];
        yield 'text' => ['invalido'];
    }

    public function test_un_contexto_no_disponible_no_aparece_en_los_selectores_ni_se_acepta_al_guardar(): void
    {
        [$estudiante, $curso] = $this->records();
        $estudiante->delete();
        $curso->delete();
        $this->actingAs($this->adminUser());
        $this->panelMatriculas('GET', 'opciones/estudiantes', ['contexto' => 'crear', 'q' => $estudiante->dni])
            ->assertOk()->assertJsonCount(0, 'data');
        $this->panelMatriculas('GET', 'opciones/cursos', ['contexto' => 'crear', 'q' => $curso->codigo])
            ->assertOk()->assertJsonCount(0, 'data');
        $this->panelMatriculas('POST', '', [
            'estudiante_id' => $estudiante->id, 'curso_id' => $curso->id,
            'fecha_matricula' => '2026-10-28', 'estado' => 'pendiente',
        ])->assertUnprocessable()->assertJsonValidationErrors(['estudiante_id', 'curso_id']);
        $this->assertDatabaseCount('matriculas', 0);
    }

    /** @return array{Estudiante, Curso} */
    private function records(): array
    {
        return [
            Estudiante::create(['dni' => '70123456', 'nombres' => 'Ana', 'apellidos' => 'Prueba', 'estado' => 'activo']),
            Curso::create([
                'codigo' => 'CTX-001', 'nombre' => 'Curso contextual', 'descripcion' => 'Curso ficticio',
                'categoria' => 'tecnologia', 'duracion_horas' => 20, 'precio' => 150,
                'modalidad' => 'virtual', 'horario' => 'Lunes 18:00',
                'cupos_maximos' => 2, 'cupos_disponibles' => 2, 'estado' => 'activo',
            ]),
        ];
    }
}
