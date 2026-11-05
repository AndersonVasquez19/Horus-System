<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SincronizarCatalogoAdvisor;
use App\Models\Curso;
use App\Models\CursoPublicacion;
use App\Models\ServicioTecnologico;
use App\Models\User;
use App\Observers\PublicacionCatalogoAdvisorObserver;
use App\Services\AsistenteVirtualService;
use App\Services\ExportadorCatalogoAdvisor;
use App\Services\FirmaCatalogoAdvisor;
use App\Services\HorusAdvisorClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CatalogoAdvisorSincronizacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'horus-advisor.enabled' => true,
            'horus-advisor.url' => 'http://127.0.0.1:8001',
            'horus-advisor.token' => str_repeat('t', 32),
            'horus-catalog.sync_enabled' => false,
            'horus-advisor.signing_key' => str_repeat('k', 32),
        ]);
        Http::preventStrayRequests();
        $this->assertIsolatedTestDatabase();
    }

    public function test_catalog_none_is_accepted_when_synchronization_is_disabled(): void
    {
        $this->fakeReady('none');

        app(HorusAdvisorClient::class)->orientar('Oriéntame sobre cursos.');

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/v1/chat'));
    }

    public function test_catalog_loaded_is_accepted_only_for_the_expected_signed_version(): void
    {
        config(['horus-catalog.sync_enabled' => true]);
        $expected = str_repeat('a', 40);
        Cache::put('horus-ai.catalog.expected-version', $expected);
        $this->fakeReady('loaded', $expected);

        app(HorusAdvisorClient::class)->orientar('Oriéntame sobre cursos.');

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/v1/chat'));
    }

    public function test_catalog_loaded_is_accepted_when_automatic_sync_is_disabled(): void
    {
        $this->fakeReady('loaded', str_repeat('a', 40));

        app(HorusAdvisorClient::class)->orientar('Oriéntame sobre cursos.');

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/v1/chat'));
    }

    public function test_catalog_version_mismatch_does_not_reach_chat_endpoint(): void
    {
        config(['horus-catalog.sync_enabled' => true]);
        Cache::put('horus-ai.catalog.expected-version', str_repeat('a', 40));
        $this->fakeReady('loaded', str_repeat('b', 40));

        app(HorusAdvisorClient::class)->orientar('Oriéntame sobre cursos.');

        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/api/v1/chat'));
    }

    public function test_stale_and_error_catalog_states_use_fallback_without_chat_request(): void
    {
        config(['horus-catalog.sync_enabled' => true]);
        Cache::put('horus-ai.catalog.expected-version', str_repeat('a', 40));
        foreach (['stale', 'error'] as $state) {
            Http::swap(new Factory);
            $this->fakeReady($state);

            app(HorusAdvisorClient::class)->orientar('Oriéntame sobre cursos.');

            Http::assertSentCount(1);
            Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/api/v1/chat'));
        }
    }

    public function test_managed_types_are_covered_by_the_catalog_signature(): void
    {
        $catalog = [
            'schema_version' => '1.0', 'catalog_version' => 'signature-vector', 'source_system' => 'horus-laravel',
            'generated_at' => '2026-11-04T12:00:00-05:00', 'expires_at' => '2026-11-04T12:05:00-05:00',
            'managed_types' => ['curso', 'servicio'], 'items' => [],
        ];
        $signature = app(FirmaCatalogoAdvisor::class)->firmar($catalog);
        $catalog['signature'] = $signature;
        $this->assertTrue(app(FirmaCatalogoAdvisor::class)->verificar($catalog));
        $catalog['managed_types'] = ['curso'];
        $this->assertFalse(app(FirmaCatalogoAdvisor::class)->verificar($catalog));
    }

    public function test_course_snapshot_is_a_signed_complete_catalog_with_stable_content_version(): void
    {
        $admin = $this->adminUser();
        $course = Curso::create([
            'codigo' => 'CAT-SYNC-1', 'nombre' => 'Nombre administrativo', 'descripcion' => 'Descripción privada',
            'categoria' => 'Redes', 'duracion_horas' => 10, 'precio' => 900.00, 'modalidad' => 'virtual',
            'horario' => 'Horario interno', 'cupos_maximos' => 20, 'cupos_disponibles' => 18, 'estado' => 'activo',
        ]);
        $this->actingAs($admin)->putJson(route('admin.cursos.publicacion.update', $course), [
            'titulo' => 'Curso de redes públicas', 'resumen' => 'Contenido editorial aprobado para consultas.',
            'modalidad' => 'virtual', 'vigente_desde' => now('America/Lima')->toDateString(),
            'vigente_hasta' => now('America/Lima')->addMonth()->toDateString(), 'confirmacion_publica' => '1',
        ])->assertRedirect();

        $catalog = app(ExportadorCatalogoAdvisor::class)->construirParaSincronizacion();

        $this->assertSame(['curso', 'servicio'], $catalog['managed_types']);
        $this->assertSame('curso:publicacion-'.CursoPublicacion::firstOrFail()->id, $catalog['items'][0]['stable_id']);
        $this->assertTrue(app(FirmaCatalogoAdvisor::class)->verificar($catalog));
        $this->assertStringNotContainsString('Nombre administrativo', json_encode($catalog));
        $this->assertStringNotContainsString('Descripción privada', json_encode($catalog));
        $this->assertSame(40, strlen($catalog['catalog_version']));
    }

    public function test_publication_after_commit_invalidates_expected_version_and_dispatches_sync_job(): void
    {
        config(['horus-advisor.enabled' => true, 'horus-catalog.sync_enabled' => true]);
        Cache::forever('horus-ai.catalog.expected-version', str_repeat('a', 40));
        Queue::fake();

        (new PublicacionCatalogoAdvisorObserver)->saved(new CursoPublicacion);

        $this->assertNull(Cache::get('horus-ai.catalog.expected-version'));
        Queue::assertPushed(SincronizarCatalogoAdvisor::class);
    }

    public function test_deleting_an_editorial_publication_queues_a_complete_catalog_sync(): void
    {
        config(['horus-advisor.enabled' => true, 'horus-catalog.sync_enabled' => true]);
        Queue::fake();

        (new PublicacionCatalogoAdvisorObserver)->deleted(new CursoPublicacion);

        Queue::assertPushed(SincronizarCatalogoAdvisor::class);
    }

    public function test_published_technology_service_uses_existing_public_projection(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);
        config(['catalogo-servicios.enabled' => false]);
        $id = (int) $this->postJson('/admin/servicios', [
            'codigo' => 'CATALOGO-SYNC', 'nombre' => 'Servicio de catálogo', 'categoria' => 'Tecnología',
        ])->assertCreated()->json('servicio.id');
        $etag = (string) $this->getJson("/admin/servicios/$id")->assertOk()->headers->get('ETag');
        $this->putJson("/admin/servicios/$id/publicacion", [
            'titulo' => 'Soporte de catálogo', 'resumen' => 'Servicio público de soporte tecnológico.',
            'descripcion' => 'Ayuda técnica autorizada para equipos informáticos.',
            'vigente_desde' => now('America/Lima')->toDateString(), 'vigente_hasta' => now('America/Lima')->addMonth()->toDateString(),
            'titulo_tarjetas' => 'Cobertura',
        ], ['If-Match' => $etag])->assertOk();
        $etag = (string) $this->getJson("/admin/servicios/$id")->assertOk()->headers->get('ETag');
        $this->patchJson("/admin/servicios/$id/estado", ['estado' => 'activo'], ['If-Match' => $etag])->assertOk();
        $etag = (string) $this->getJson("/admin/servicios/$id")->assertOk()->headers->get('ETag');
        $this->postJson("/admin/servicios/$id/publicacion/autorizar", ['confirmacion_publica' => true], ['If-Match' => $etag])->assertOk();
        $etag = (string) $this->getJson("/admin/servicios/$id")->assertOk()->headers->get('ETag');

        $catalog = app(ExportadorCatalogoAdvisor::class)->construirParaSincronizacion();

        $stableId = 'servicio:'.strtolower((string) ServicioTecnologico::query()->findOrFail($id)->identificador_publico);
        $service = collect($catalog['items'])->firstWhere('stable_id', $stableId);
        $this->assertNotNull($service);
        $this->assertSame('vigente', $service['publication_status']);
        $this->assertFalse(config('catalogo-servicios.enabled'));
        $this->assertTrue(app(FirmaCatalogoAdvisor::class)->verificar($catalog));

        $retiredDraft = $this->deleteJson("/admin/servicios/$id/publicacion", [], ['If-Match' => $etag])->assertOk();
        $draft = $this->putJson("/admin/servicios/$id/publicacion", [
            'titulo' => 'Soporte de catálogo actualizado', 'resumen' => 'Servicio tecnológico actualizado y público.',
            'descripcion' => 'Orientación técnica actualizada para equipos informáticos.',
            'vigente_desde' => now('America/Lima')->toDateString(), 'vigente_hasta' => now('America/Lima')->addMonth()->toDateString(),
            'titulo_tarjetas' => 'Cobertura actualizada',
        ], ['If-Match' => $retiredDraft->headers->get('ETag')])->assertOk();
        $this->postJson("/admin/servicios/$id/publicacion/autorizar", ['confirmacion_publica' => true], [
            'If-Match' => $draft->headers->get('ETag'),
        ])->assertOk();
        $updated = app(ExportadorCatalogoAdvisor::class)->construirParaSincronizacion();
        $updatedService = collect($updated['items'])->firstWhere('stable_id', $stableId);
        $this->assertNotNull($updatedService);
        $this->assertSame('Soporte de catálogo actualizado', $updatedService['title']);
        $this->assertNotSame($catalog['catalog_version'], $updated['catalog_version']);

        $etag = (string) $this->getJson("/admin/servicios/$id")->assertOk()->headers->get('ETag');
        $this->deleteJson("/admin/servicios/$id/publicacion", [], ['If-Match' => $etag])->assertOk();
        $retired = app(ExportadorCatalogoAdvisor::class)->construirParaSincronizacion();
        $this->assertNull(collect($retired['items'])->firstWhere('stable_id', $stableId));
    }

    public function test_course_edit_changes_snapshot_and_retirement_removes_it_from_full_catalog(): void
    {
        $admin = $this->adminUser();
        $course = $this->createAndPublishCourse($admin);
        $exporter = app(ExportadorCatalogoAdvisor::class);
        $first = $exporter->construirParaSincronizacion();
        $stableId = $first['items'][0]['stable_id'];

        $this->actingAs($admin)->putJson(route('admin.cursos.publicacion.update', $course), [
            'titulo' => 'Curso de redes actualizado', 'resumen' => 'Descripción editorial revisada y autorizada.',
            'modalidad' => 'presencial', 'vigente_desde' => now('America/Lima')->toDateString(),
            'vigente_hasta' => now('America/Lima')->addMonth()->toDateString(), 'confirmacion_publica' => '1',
        ])->assertRedirect();
        $updated = $exporter->construirParaSincronizacion();
        $this->assertSame($stableId, $updated['items'][0]['stable_id']);
        $this->assertSame('Curso de redes actualizado', $updated['items'][0]['title']);
        $this->assertNotSame($first['catalog_version'], $updated['catalog_version']);

        $this->deleteJson(route('admin.cursos.publicacion.destroy', $course))->assertRedirect();
        $retired = $exporter->construirParaSincronizacion();
        $this->assertSame([], $retired['items']);
        $this->assertNotSame($updated['catalog_version'], $retired['catalog_version']);
    }

    public function test_catalog_version_invalidates_laravel_reply_cache_namespace(): void
    {
        config(['horus-advisor.enabled' => false]);
        $service = app(AsistenteVirtualService::class);
        $service->procesarMensaje('Buenos días', 'catalog-cache', 'direct');
        $this->assertDatabaseCount('chat_logs', 1);

        Cache::forever('horus-ai.catalog.expected-version', str_repeat('c', 40));
        $service->procesarMensaje('Buenos días', 'catalog-cache', 'direct');

        $this->assertDatabaseCount('chat_logs', 2);
    }

    public function test_signed_snapshot_is_sent_to_sync_endpoint_and_response_version_is_verified(): void
    {
        config(['horus-catalog.sync_enabled' => true]);
        $content = ['managed_types' => ['curso', 'servicio'], 'items' => []];
        $version = app(FirmaCatalogoAdvisor::class)->versionContenido($content);
        $catalog = [
            'schema_version' => '1.0', 'catalog_version' => $version, 'source_system' => 'horus-laravel',
            'generated_at' => now('UTC')->toIso8601String(), 'expires_at' => now('UTC')->addMinutes(5)->toIso8601String(),
            ...$content,
        ];
        $catalog['signature'] = app(FirmaCatalogoAdvisor::class)->firmar($catalog);
        Http::fake(['*/api/v1/catalog/sync' => Http::response([
            'accepted' => true, 'applied' => true, 'catalog' => 'loaded', 'catalog_version' => $version,
        ])]);

        $result = app(HorusAdvisorClient::class)->sincronizarCatalogo($catalog);

        $this->assertSame($version, $result['catalog_version']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/v1/catalog/sync'));
    }

    private function fakeReady(string $catalog, ?string $version = null): void
    {
        $ready = [
            'status' => 'ready', 'knowledge' => 'loaded', 'auth' => 'configured',
            'semantic' => 'disabled', 'catalog' => $catalog,
        ];
        if ($version !== null) {
            $ready['catalog_version'] = $version;
        }
        Http::fake([
            '*/ready' => Http::response($ready),
            '*/api/v1/chat' => Http::response([], 503),
        ]);
    }

    private function createAndPublishCourse(User $admin): Curso
    {
        $course = Curso::create([
            'codigo' => 'CAT-SYNC-2', 'nombre' => 'Nombre administrativo', 'descripcion' => 'Descripción privada',
            'categoria' => 'Redes', 'duracion_horas' => 10, 'precio' => 900.00, 'modalidad' => 'virtual',
            'horario' => 'Horario interno', 'cupos_maximos' => 20, 'cupos_disponibles' => 18, 'estado' => 'activo',
        ]);
        $this->actingAs($admin)->putJson(route('admin.cursos.publicacion.update', $course), [
            'titulo' => 'Curso de redes', 'resumen' => 'Contenido editorial aprobado para consultas.',
            'modalidad' => 'virtual', 'vigente_desde' => now('America/Lima')->toDateString(),
            'vigente_hasta' => now('America/Lima')->addMonth()->toDateString(), 'confirmacion_publica' => '1',
        ])->assertRedirect();

        return $course;
    }
}
