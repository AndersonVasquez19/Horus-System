<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChatIntent;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Services\AsistenteVirtualService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\LlamaMatriculasDelPanel;
use Tests\TestCase;

class BackendPerformanceTest extends TestCase
{
    use LlamaMatriculasDelPanel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        $this->travelTo(now()->setDate(2026, 10, 28)->startOfDay());
        config(['matricula-mail.enabled' => false, 'services.openai.api_key' => null,
            'chatbot.public_catalog_enabled' => false]);
        Http::preventStrayRequests();
    }

    /** Listado del reporte por la API del panel (usuario fijado antes con actingAs). @param array<string, mixed> $filtros */
    private function reportePanel(array $filtros = []): TestResponse
    {
        config(['horus-web.service_token' => 'token-de-servicio-del-bff-para-pruebas-0123456789']);

        return $this->withHeaders(['X-Horus-Web-Token' => 'token-de-servicio-del-bff-para-pruebas-0123456789'])
            ->getJson('/api/panel/v1/academico/reportes'.($filtros === [] ? '' : '?'.http_build_query($filtros)));
    }

    public function test_reports_reuse_filtered_total_for_pagination_and_income(): void
    {
        $course = $this->course();
        for ($i = 0; $i < 55; $i++) {
            $this->enrollment($course, 'pagada', 10.25);
        }
        $this->enrollment($course, 'pendiente', 900);
        $this->enrollment($course, 'pagada', 800)->delete();
        $this->enrollment($this->course('OTRO'), 'pagada', 700);
        $this->actingAs($this->adminUser());
        $filters = ['busqueda' => 'PRUEBA', 'estado' => 'pagada', 'curso_id' => (string) $course->id, 'lote' => '2'];

        DB::enableQueryLog();
        $response = $this->reportePanel($filters)->assertOk();
        $aggregates = $this->enrollmentAggregates();
        $response->assertJsonPath('resumen.matriculas', 55)->assertJsonPath('resumen.ingresos', '563.75');
        $this->assertSame(55, $response->json('meta.total'));
        $this->assertCount(5, (array) $response->json('data'));
        $this->assertSame(2, $response->json('meta.lote'));
        // Las cinco más antiguas de las 55 vigentes y pagadas del curso (ids relativos: PostgreSQL no reinicia secuencias).
        $esperados = Matricula::query()->where('curso_id', $course->id)->where('estado', 'pagada')->orderBy('id')->limit(5)->pluck('id')->reverse()->values()->all();
        $this->assertCount(5, $esperados);
        $this->assertSame($esperados, array_map('intval', array_column((array) $response->json('data'), 'id')));
        $this->assertSame('PRUEBA', $response->json('meta.filtros.busqueda'));
        $this->assertSame('pagada', $response->json('meta.filtros.estado'));
        $this->assertSame($course->id, (int) $response->json('meta.filtros.curso_id'));
        $this->assertCount(1, $aggregates);
        Http::assertNothingSent();
    }

    public function test_empty_dashboard_and_reports_keep_zero_totals_and_batch_rejection(): void
    {
        $this->actingAs($this->adminUser());
        $this->reportePanel()->assertOk()->assertJsonPath('resumen.matriculas', 0)->assertJsonPath('resumen.ingresos', '0.00');
        $this->reportePanel(['lote' => 2])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_enrollment_course_filter_resolves_only_the_selected_course_including_deleted_ones(): void
    {
        $active = $this->course();
        $deleted = $this->course('ELIMINADO');
        $deleted->delete();
        $row = $this->enrollment($active);
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->panelMatriculas('GET', '', [
            'curso_id' => (string) $active->id, 'busqueda' => 'PRUEBA', 'estado' => 'pendiente',
        ])->assertOk();
        // El listado devuelve solo las matrículas del curso elegido (no la lista completa de cursos).
        $this->assertSame([$row->id], $response->json('data.*.id'));
        $response->assertJsonPath('meta.por_pagina', 20)->assertJsonPath('meta.filtros.curso_id', $active->id);

        // Un curso eliminado sigue siendo filtrable: el selector de filtro lo ofrece marcado como eliminado.
        $this->panelMatriculas('GET', '', ['curso_id' => (string) $deleted->id])->assertOk()->assertJsonPath('meta.total', 0);
        $this->panelMatriculas('GET', 'opciones/cursos', ['contexto' => 'filtro', 'q' => 'ELIMINADO'])->assertOk()
            ->assertJsonPath('data.0.eliminado', true);
        Http::assertNothingSent();
    }

    public function test_chatbot_does_not_hydrate_unused_inactive_intents_and_refreshes_changes(): void
    {
        for ($i = 0; $i < 100; $i++) {
            ChatIntent::create(['nombre' => 'inactiva_'.$i, 'keywords' => ['beneficio ficticio'],
                'respuesta' => 'No debe utilizarse.', 'prioridad' => 1, 'activo' => false]);
        }
        $builtin = ChatIntent::create(['nombre' => 'consulta_camaras', 'keywords' => ['camaras'],
            'respuesta' => 'No debe reemplazar el conocimiento local.', 'prioridad' => 1, 'activo' => false]);
        $custom = ChatIntent::create(['nombre' => 'personalizada', 'keywords' => ['beneficio ficticio'],
            'respuesta' => 'Respuesta ficticia.', 'prioridad' => 1, 'activo' => true]);
        $retrieved = [];
        ChatIntent::retrieved(function (ChatIntent $intent) use (&$retrieved): void {
            $retrieved[] = $intent->nombre;
        });
        $service = app(AsistenteVirtualService::class);
        $answer = $service->procesarMensaje('beneficio ficticio', 'performance-custom');
        $this->assertSame(AsistenteVirtualService::RESPUESTA_FALLBACK, $answer['respuesta']);
        $this->assertSame([], $retrieved);
        $this->assertNull($service->buscarIntent('camaras'));
        $builtin->update(['activo' => true]);
        $this->assertSame('consulta_camaras', $service->buscarIntent('camaras')->nombre);
        $custom->update(['activo' => false]);
        $this->assertNull($service->buscarIntent('beneficio ficticio'));
        $custom->update(['activo' => true, 'respuesta' => 'Actualizada.']);
        $this->assertSame(AsistenteVirtualService::RESPUESTA_FALLBACK, $service->procesarMensaje('beneficio ficticio', 'performance-custom')['respuesta']);
        Http::assertNothingSent();
    }

    private function enrollmentAggregates(): array
    {
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();

        return array_values(array_filter($queries, fn ($query) => str_contains($query['query'], 'from "matriculas"')
            && preg_match('/\b(count|sum)\(/i', $query['query'])));
    }

    private function course(string $code = 'PERF'): Curso
    {
        return Curso::create(['codigo' => $code, 'nombre' => $code === 'PERF' ? 'Curso Prueba' : 'Otro curso',
            'descripcion' => str_repeat('Descripción ficticia. ', 500), 'categoria' => 'tecnologia',
            'duracion_horas' => 10, 'precio' => 100, 'modalidad' => 'virtual', 'horario' => 'Ficticio',
            'cupos_maximos' => 100, 'cupos_disponibles' => 100, 'estado' => 'activo']);
    }

    private function enrollment(Curso $course, string $status = 'pendiente', ?float $amount = null): Matricula
    {
        $student = Estudiante::create(['dni' => sprintf('%08d', Estudiante::withTrashed()->count() + 1),
            'nombres' => 'Prueba', 'apellidos' => 'Ficticia', 'estado' => 'activo']);

        return Matricula::create(['estudiante_id' => $student->id, 'curso_id' => $course->id,
            'fecha_matricula' => '2026-10-28', 'estado' => $status, 'monto_pagado' => $amount]);
    }
}
