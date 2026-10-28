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
use Tests\TestCase;

class BackendPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->travelTo(now()->setDate(2026, 10, 28)->startOfDay());
        config(['matricula-mail.enabled' => false, 'services.openai.api_key' => null,
            'chatbot.public_catalog_enabled' => false]);
        Http::preventStrayRequests();
    }

    public function test_dashboard_uses_one_monthly_aggregate_and_preserves_totals(): void
    {
        $course = $this->course();
        foreach ([['pendiente', 999], ['pagada', 10.25], ['pagada', 20.75], ['pagada', null], ['anulada', 500]] as [$status, $amount]) {
            $this->enrollment($course, $status, $amount);
        }
        $this->enrollment($course, 'pagada', 777)->update(['fecha_matricula' => '2026-09-30']);
        $this->enrollment($course, 'pagada', 900)->update(['fecha_matricula' => '2026-11-01']);
        $this->enrollment($course, 'pagada', 100)->delete();
        $this->actingAs($this->adminUser());

        DB::enableQueryLog();
        $response = $this->get(route('admin.dashboard'))->assertOk();
        $aggregates = $this->enrollmentAggregates();
        $response->assertViewHas('matriculasMes', 5)
            ->assertViewHas('ingresosMes', fn ($value) => (float) $value === 31.0);
        $this->assertSame(5, $response->viewData('meses')->last()['total']);
        $this->assertSame(1, $response->viewData('meses')[4]['total']);
        // Una consulta mensual conjunta y otra agrupada para los seis meses.
        $this->assertCount(2, $aggregates);
        Http::assertNothingSent();
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
        $response = $this->get(route('admin.reportes.index', $filters))->assertOk();
        $aggregates = $this->enrollmentAggregates();
        $response->assertViewHas('resumen', fn ($value) => $value['matriculas'] === 55 && (float) $value['ingresos'] === 563.75);
        $rows = $response->viewData('matriculas');
        $this->assertSame(55, $rows->total());
        $this->assertCount(5, $rows);
        $this->assertSame(2, $rows->currentPage());
        $this->assertSame([5, 4, 3, 2, 1], $rows->pluck('id')->all());
        parse_str(parse_url($rows->previousPageUrl(), PHP_URL_QUERY), $query);
        $this->assertSame('PRUEBA', $query['busqueda']);
        $this->assertSame('pagada', $query['estado']);
        $this->assertSame((string) $course->id, $query['curso_id']);
        $this->assertCount(1, $aggregates);
        Http::assertNothingSent();
    }

    public function test_empty_dashboard_and_reports_keep_zero_totals_and_batch_rejection(): void
    {
        $this->actingAs($this->adminUser());
        $this->get(route('admin.dashboard'))->assertOk()
            ->assertViewHas('matriculasMes', 0)->assertViewHas('ingresosMes', fn ($value) => (float) $value === 0.0);
        $this->get(route('admin.reportes.index'))->assertOk()
            ->assertViewHas('resumen', fn ($value) => $value['matriculas'] === 0 && (float) $value['ingresos'] === 0.0);
        $this->get(route('admin.reportes.index', ['lote' => 2]))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_enrollment_selector_loads_only_required_fields_including_deleted_courses(): void
    {
        $active = $this->course();
        $deleted = $this->course('ELIMINADO');
        $deleted->delete();
        $row = $this->enrollment($active);
        $response = $this->actingAs($this->adminUser())->get(route('admin.matriculas.index', [
            'curso_id' => (string) $active->id, 'busqueda' => 'PRUEBA', 'estado' => 'pendiente',
        ]))->assertOk();
        $courses = $response->viewData('cursos');
        $this->assertSame([$active->id, $deleted->id], $courses->pluck('id')->all());
        foreach ($courses as $course) {
            $this->assertEqualsCanonicalizing(['id', 'codigo', 'nombre', 'deleted_at'], array_keys($course->getAttributes()));
        }
        $this->assertTrue($courses->last()->trashed());
        $this->assertSame([$row->id], $response->viewData('matriculas')->pluck('id')->all());
        $this->assertSame(15, $response->viewData('matriculas')->perPage());
        $response->assertSee('value="'.$active->id.'" selected', false);
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
        $this->assertSame('Respuesta ficticia.', $answer['respuesta']);
        $this->assertEqualsCanonicalizing(['consulta_camaras', 'personalizada'], $retrieved);
        $this->assertNull($service->buscarIntent('camaras'));
        $builtin->update(['activo' => true]);
        $this->assertSame('consulta_camaras', $service->buscarIntent('camaras')->nombre);
        $custom->update(['activo' => false]);
        $this->assertNull($service->buscarIntent('beneficio ficticio'));
        $custom->update(['activo' => true, 'respuesta' => 'Actualizada.']);
        $this->assertSame('Actualizada.', $service->procesarMensaje('beneficio ficticio', 'performance-custom')['respuesta']);
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
