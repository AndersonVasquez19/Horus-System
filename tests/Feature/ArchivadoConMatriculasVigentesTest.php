<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 1 de la auditoría del panel (#9): no se desactiva un estudiante ni se archiva un curso mientras tenga
 * matrículas vigentes (pendiente/pagada); con todas anuladas la operación sigue permitida.
 *
 * SQLite en memoria: no mide concurrencia (lockForUpdate se ignora).
 */
class ArchivadoConMatriculasVigentesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        config(['matricula-mail.enabled' => false]);
        Http::preventStrayRequests();
    }

    /** @return array{0: Estudiante, 1: Curso, 2: Matricula} */
    private function matricula(string $estado): array
    {
        $curso = Curso::create([
            'codigo' => 'COD-ARCH-'.random_int(1000, 9999),
            'nombre' => 'Curso de prueba',
            'descripcion' => 'x',
            'categoria' => 'tecnologia',
            'duracion_horas' => 10,
            'precio' => 100,
            'modalidad' => 'virtual',
            'horario' => 'x',
            'cupos_maximos' => 5,
            'cupos_disponibles' => 4,
            'estado' => 'activo',
        ]);
        $estudiante = Estudiante::create(['dni' => '70000999', 'nombres' => 'Ana', 'apellidos' => 'Prueba', 'estado' => 'activo']);
        $matricula = Matricula::create([
            'estudiante_id' => $estudiante->id,
            'curso_id' => $curso->id,
            'fecha_matricula' => now()->toDateString(),
            'estado' => $estado,
            'monto_pagado' => $estado === 'pagada' ? 100 : 0,
        ]);

        return [$estudiante, $curso, $matricula];
    }

    public function test_no_se_desactiva_un_estudiante_con_matricula_vigente(): void
    {
        foreach (['pendiente', 'pagada'] as $estado) {
            [$estudiante] = $this->matricula($estado);
            $admin = $this->adminUser();

            $this->actingAs($admin)->delete(route('admin.estudiantes.destroy', $estudiante))
                ->assertRedirect(route('admin.estudiantes.show', $estudiante))
                ->assertSessionHas('error');

            $this->assertNull($estudiante->fresh()->deleted_at);
            $this->assertDatabaseMissing('audit_logs', ['action' => 'estudiante_eliminado', 'model_id' => $estudiante->id]);

            // Limpieza para la siguiente iteración (dni único).
            Matricula::query()->delete();
            Estudiante::withTrashed()->forceDelete();
            Curso::withTrashed()->forceDelete();
        }
    }

    public function test_no_se_archiva_un_curso_con_matricula_vigente_y_json_responde_422(): void
    {
        [, $curso] = $this->matricula('pagada');
        $admin = $this->adminUser();

        $this->actingAs($admin)->deleteJson(route('admin.cursos.destroy', $curso))
            ->assertStatus(422)
            ->assertJsonStructure(['message']);

        $this->assertNull($curso->fresh()->deleted_at);
        $this->assertSame(0, AuditLog::where('action', 'curso_archivado')->count());
    }

    public function test_con_todas_las_matriculas_anuladas_si_se_permite(): void
    {
        [$estudiante, $curso] = $this->matricula('anulada');
        $admin = $this->adminUser();

        $this->actingAs($admin)->delete(route('admin.estudiantes.destroy', $estudiante))
            ->assertRedirect(route('admin.estudiantes.index'));
        $this->actingAs($admin)->delete(route('admin.cursos.destroy', $curso))
            ->assertRedirect(route('admin.cursos.index'));

        $this->assertNotNull(Estudiante::withTrashed()->find($estudiante->id)->deleted_at);
        $this->assertNotNull(Curso::withTrashed()->find($curso->id)->deleted_at);
    }
}
