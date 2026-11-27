<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Curso;
use App\Models\CursoPublicacion;
use App\Services\CatalogoPublicoCursos;
use App\Services\ExportadorCatalogoAdvisor;
use App\Services\FirmaCatalogoAdvisor;
use App\Services\FuentesCatalogoAdvisor;
use App\Services\HorusAdvisorClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LlamaCursosDelPanel;
use Tests\TestCase;

/**
 * Datos académicos del catálogo (22/11/2026): inicio, duración, certificación, temario y enlace.
 *
 * Los literales son el contrato con Python: apps/ai/tests/test_catalogo_campos_academicos.py exige
 * exactamente las mismas frases. Si cambian aquí, cambian allá.
 */
class AdvisorCamposAcademicosTest extends TestCase
{
    use LlamaCursosDelPanel;
    use RefreshDatabase;

    private const ITEM = [
        'stable_id' => 'curso:publicacion-7', 'type' => 'curso', 'publication_status' => 'vigente',
        'title' => 'Ofimática para oficinas', 'summary' => 'Aprende Excel y Word para el trabajo diario.',
        'details' => 'Inscripción por WhatsApp oficial. Temario: Excel básico, tablas dinámicas y Word profesional.',
        'modalities' => ['virtual'], 'duration_text' => '8 semanas', 'schedule_text' => 'sábados de 9 a 12',
        'start_date' => '2026-12-05', 'certification_text' => 'Certificado digital a nombre de Horus Group.',
        'url_path' => '/educacion/cursos', 'updated_at' => '2026-11-22T10:00:00-05:00',
    ];

    private const RESPUESTA_COMPLETA = 'Ofimática para oficinas: Aprende Excel y Word para el trabajo diario. Modalidad: virtual. Duración: 8 semanas. '
        .'Inicio: 5 de diciembre de 2026. Horario: sábados de 9 a 12. Certificación: Certificado digital a nombre de Horus Group.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        // Reloj fijo: el inicio solo se publica mientras sea futuro (el ITEM empieza el 05/12/2026).
        $this->travelTo(Carbon::parse('2026-11-20 12:00:00', 'America/Lima'));
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    public static function frases(): iterable
    {
        yield 'inicio y horario' => ['«Ofimática para oficinas»: inicia el 5 de diciembre de 2026; el horario es sábados de 9 a 12.'];
        yield 'certificación' => ['«Ofimática para oficinas»: certificación: Certificado digital a nombre de Horus Group.'];
        yield 'duración' => ['«Ofimática para oficinas»: dura 8 semanas.'];
        yield 'temario' => ['Ofimática para oficinas: Inscripción por WhatsApp oficial. Temario: Excel básico, tablas dinámicas y Word profesional.'];
        yield 'plazo de atención sin dato' => ['«Ofimática para oficinas»: el plazo de atención lo confirma un asesor.'];
        yield 'público sin dato' => ['«Ofimática para oficinas»: el público al que se dirige lo confirma un asesor.'];
        yield 'ficha completa' => [self::RESPUESTA_COMPLETA];
    }

    #[DataProvider('frases')]
    public function test_cada_frase_de_python_se_acepta_como_texto_verificado(string $frase): void
    {
        $catalogo = $this->catalogoFirmado([self::ITEM]);
        $fuente = $this->fuente();
        $this->assertContains($frase, [$fuente['answer'], ...$fuente['fragments']]);
        $respuesta = $this->consultar($catalogo, $frase);
        $this->assertSame('advisor', $respuesta['fuente'] ?? null, $frase);
        $this->assertSame(['/educacion/cursos'], $respuesta['enlaces'] ?? null);
    }

    public function test_una_fecha_distinta_a_la_publicada_se_rechaza(): void
    {
        $catalogo = $this->catalogoFirmado([self::ITEM]);
        $this->assertNull($this->consultar($catalogo, '«Ofimática para oficinas»: inicia el 6 de diciembre de 2026.'));
    }

    public function test_solo_fecha_sin_horario_usa_la_frase_corta(): void
    {
        $item = self::ITEM;
        unset($item['schedule_text']);
        $this->catalogoFirmado([$item]);
        $fragmentos = $this->fuente()['fragments'];
        $this->assertContains('«Ofimática para oficinas»: inicia el 5 de diciembre de 2026.', $fragmentos);
    }

    public function test_fecha_larga_en_espanol(): void
    {
        $this->assertSame('5 de diciembre de 2026', FuentesCatalogoAdvisor::fechaLarga('2026-12-05'));
        $this->assertSame('31 de enero de 2027', FuentesCatalogoAdvisor::fechaLarga('2027-01-31'));
    }

    public function test_el_exportador_envia_los_datos_academicos_publicados(): void
    {
        $publicacion = $this->publicar();
        $publicacion->forceFill(['fecha_inicio' => '2026-12-05', 'duracion' => '8 semanas',
            'certificacion' => 'Certificado digital a nombre de Horus Group.', 'temario' => 'Excel básico, tablas dinámicas y Word profesional.'])->save();

        $item = $this->itemExportado($publicacion);

        $this->assertSame('8 semanas', $item['duration_text']);
        $this->assertSame('2026-12-05', $item['start_date']);
        $this->assertSame('Certificado digital a nombre de Horus Group.', $item['certification_text']);
        $this->assertSame('/educacion/cursos', $item['url_path']);
        $this->assertStringEndsWith('Temario: Excel básico, tablas dinámicas y Word profesional.', (string) $item['details']);
    }

    public function test_un_temario_con_un_dato_personal_se_omite_sin_bloquear_la_ficha(): void
    {
        $publicacion = $this->publicar();
        $publicacion->forceFill(['duracion' => '8 semanas', 'temario' => 'Escribe al 999 888 777 para el sílabo.'])->save();

        $item = $this->itemExportado($publicacion);

        $this->assertStringNotContainsString('999', json_encode($item, JSON_THROW_ON_ERROR));
        $this->assertSame('8 semanas', $item['duration_text']);
    }

    public function test_un_temario_largo_se_recorta_por_palabra_dentro_del_limite(): void
    {
        $publicacion = $this->publicar();
        $publicacion->forceFill(['temario' => str_repeat('Módulo de hojas de cálculo avanzadas ', 80)])->save();

        $detalles = (string) $this->itemExportado($publicacion)['details'];

        $this->assertLessThanOrEqual(1500, mb_strlen($detalles));
        // El corte cae al final de una palabra completa del temario, nunca a mitad de palabra.
        $this->assertMatchesRegularExpression('/ (?:Módulo|de|hojas|cálculo|avanzadas)…\.\z/u', $detalles);
    }

    public function test_sin_datos_academicos_no_se_envian_campos_vacios(): void
    {
        $item = $this->itemExportado($this->publicar());

        $this->assertNull($item['duration_text']);
        $this->assertNull($item['start_date']);
        $this->assertNull($item['certification_text']);
    }

    public function test_el_inicio_se_publica_mientras_sea_futuro_y_se_oculta_desde_ese_dia(): void
    {
        $publicacion = $this->publicar();
        $publicacion->forceFill(['fecha_inicio' => '2026-12-05', 'duracion' => '8 semanas'])->save();
        config(['catalogo-publico.enabled' => true]);

        // En UTC ya es 05/12; en Lima todavía es la víspera.
        $this->travelTo(Carbon::parse('2026-12-04 23:59:00', 'America/Lima'));
        $this->assertSame('2026-12-05', $this->itemExportado($publicacion)['start_date']);
        $this->assertSame('2026-12-05', app(CatalogoPublicoCursos::class)->paraWeb()['cursos'][0]['fecha_inicio']);

        foreach (['2026-12-05 00:00:00', '2026-12-12 12:00:00'] as $momento) {
            $this->travelTo(Carbon::parse($momento, 'America/Lima'));
            $item = $this->itemExportado($publicacion);
            $web = app(CatalogoPublicoCursos::class)->paraWeb()['cursos'];

            $this->assertNull($item['start_date'], $momento);
            $this->assertSame('8 semanas', $item['duration_text']);
            $this->assertCount(1, $web, 'El curso sigue publicado.');
            $this->assertNull($web[0]['fecha_inicio'], $momento);
        }
        // El panel y la base conservan la fecha guardada.
        $this->assertSame('2026-12-05', $publicacion->refresh()->fecha_inicio?->format('Y-m-d'));
    }

    public function test_el_catalogo_del_chat_caduca_a_medianoche_cuando_el_inicio_deja_de_ser_futuro(): void
    {
        $publicacion = $this->publicar();
        $publicacion->forceFill(['fecha_inicio' => '2026-12-05'])->save();
        config(['horus-advisor.signing_key' => str_repeat('k', 32), 'horus-catalog.ttl_minutes' => 30]);

        $this->travelTo(Carbon::parse('2026-12-04 23:50:00', 'America/Lima'));
        $antes = app(ExportadorCatalogoAdvisor::class)->construirParaSincronizacion();
        $this->assertTrue(Carbon::parse($antes['expires_at'])->equalTo(Carbon::parse('2026-12-05 00:00:00', 'America/Lima')));

        $this->travelTo(Carbon::parse('2026-12-05 00:01:00', 'America/Lima'));
        $despues = app(ExportadorCatalogoAdvisor::class)->construirParaSincronizacion();
        $this->assertNotSame($antes['catalog_version'], $despues['catalog_version']);
        $this->assertTrue(Carbon::parse($despues['expires_at'])->equalTo(Carbon::parse('2026-12-05 00:31:00', 'America/Lima')));
    }

    private function publicar(): CursoPublicacion
    {
        $curso = Curso::create([
            'codigo' => 'CAT-ACAD-1', 'nombre' => 'Nombre administrativo', 'descripcion' => 'Descripción privada',
            'categoria' => 'Ofimática', 'duracion_horas' => 10, 'precio' => 900.00, 'modalidad' => 'virtual',
            'horario' => 'Horario interno', 'cupos_maximos' => 20, 'cupos_disponibles' => 18, 'estado' => 'activo',
        ]);
        $this->actingAs($this->adminUser())->publicarFichaCursoPanel($curso, [
            'titulo' => 'Ofimática para oficinas', 'resumen' => 'Aprende Excel y Word para el trabajo diario.',
            'modalidad' => 'virtual', 'vigente_desde' => now('America/Lima')->toDateString(),
            'vigente_hasta' => now('America/Lima')->addMonth()->toDateString(), 'confirmacion_publica' => '1',
        ])->assertOk();

        return CursoPublicacion::firstOrFail();
    }

    /** @return array<string, mixed> */
    private function itemExportado(CursoPublicacion $publicacion): array
    {
        config(['horus-advisor.signing_key' => str_repeat('k', 32)]);
        $catalogo = app(ExportadorCatalogoAdvisor::class)->construirParaSincronizacion();
        $this->assertTrue(app(FirmaCatalogoAdvisor::class)->verificar($catalogo));

        return collect($catalogo['items'])->firstWhere('stable_id', 'curso:publicacion-'.$publicacion->id);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function catalogoFirmado(array $items): array
    {
        config(['horus-advisor.enabled' => true, 'horus-advisor.url' => 'http://127.0.0.1:8001',
            'horus-advisor.token' => str_repeat('t', 32), 'horus-advisor.signing_key' => str_repeat('k', 32),
            'horus-catalog.sync_enabled' => true]);
        $firma = app(FirmaCatalogoAdvisor::class);
        $catalogo = ['schema_version' => '1.0', 'catalog_version' => $firma->versionContenido(['managed_types' => ['curso', 'servicio'], 'items' => $items]),
            'source_system' => 'horus-laravel', 'generated_at' => now()->toIso8601String(), 'expires_at' => now()->addMinutes(5)->toIso8601String(),
            'managed_types' => ['curso', 'servicio'], 'items' => $items];
        $catalogo['signature'] = $firma->firmar($catalogo);
        $this->mock(ExportadorCatalogoAdvisor::class)->shouldReceive('construirParaSincronizacion')->andReturn($catalogo);
        Cache::forever('horus-ai.catalog.expected-version', $catalogo['catalog_version']);

        return $catalogo;
    }

    /** @return array{title: string, url_path: string|null, answer: string, fragments: list<string>} */
    private function fuente(): array
    {
        $fuente = app(FuentesCatalogoAdvisor::class)->actuales()['cat_curso_publicacion_7'] ?? null;
        $this->assertNotNull($fuente);

        return ['fragments' => [], ...$fuente];
    }

    /**
     * @param  array<string, mixed>  $catalogo
     * @return array<string, mixed>|null
     */
    private function consultar(array $catalogo, string $respuesta): ?array
    {
        Http::fake(['*/ready' => Http::response(['status' => 'ready', 'knowledge' => 'loaded', 'auth' => 'configured',
            'semantic' => 'disabled', 'catalog' => 'loaded', 'catalog_version' => $catalogo['catalog_version']]),
            '*/api/v1/chat' => Http::response(['answer' => $respuesta, 'intent' => 'training_info', 'mode' => 'lexical',
                'match_level' => 'alta', 'abstained' => false, 'needs_clarification' => false,
                'sources' => [['id' => 'cat_curso_publicacion_7', 'title' => 'Ofimática para oficinas', 'url_path' => '/educacion/cursos']],
                'recommendations' => []])]);

        return app(HorusAdvisorClient::class)->orientar('¿Cuándo empieza el curso de Ofimática para oficinas?');
    }
}
