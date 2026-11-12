<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Curso;
use App\Models\CursoPublicacion;
use App\Models\User;
use App\Services\ExportadorCatalogoAdvisor;
use App\Services\FirmaCatalogoAdvisor;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdvisorCatalogoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        $this->travelTo(Carbon::parse('2026-10-28 23:59:00', 'America/Lima'));
        config(['horus-advisor.signing_key' => str_repeat('k', 32)]);
        Http::preventStrayRequests();
    }

    public function test_export_is_authorized_allowlisted_signed_and_read_only(): void
    {
        [$admin, $curso] = $this->publicar();
        $export = app(ExportadorCatalogoAdvisor::class)->construir($admin);
        $item = $export['items'][0];
        $this->assertSame('curso:publicacion-'.CursoPublicacion::firstOrFail()->id, $item['stable_id']);
        $this->assertSame(['stable_id', 'type', 'publication_status', 'title', 'summary', 'details', 'modalities', 'schedule_text', 'updated_at', 'valid_from', 'valid_until'], array_keys($item));
        $this->assertSame('vigente', $item['publication_status']);
        $this->assertTrue(app(FirmaCatalogoAdvisor::class)->verificar($export));
        $this->assertSame('2026-10-29T00:00:00-05:00', $export['expires_at']);
        foreach (['Nombre privado', '9876', 'Cupos', 'autorizado_por', 'curso_id', 'public_price'] as $privado) {
            $this->assertStringNotContainsString($privado, json_encode($export));
        }
        $this->assertSame('9876.54', $curso->fresh()->precio);
        $this->assertDatabaseCount('curso_publicaciones', 1);
        Http::assertNothingSent();
    }

    public static function exclusiones(): iterable
    {
        foreach (['borrador', 'retirado', 'caducado', 'futuro', 'inactivo', 'archivado', 'eliminado', 'sin_autor', 'sin_permiso', 'autorizacion_futura'] as $caso) {
            yield $caso => [$caso];
        }
    }

    #[DataProvider('exclusiones')]
    public function test_non_authorized_records_never_leave_laravel(string $caso): void
    {
        [$admin, $curso] = $this->publicar();
        $lector = $this->adminUser();
        match ($caso) {
            'borrador', 'retirado' => CursoPublicacion::query()->update(['publicada' => false]),
            'caducado' => CursoPublicacion::query()->update(['vigente_hasta' => '2026-10-27']),
            'futuro' => CursoPublicacion::query()->update(['vigente_desde' => '2026-10-29']),
            'inactivo', 'archivado' => $curso->update(['estado' => $caso]),
            'eliminado' => $curso->delete(),
            'sin_autor' => CursoPublicacion::query()->update(['autorizado_por' => null]),
            'sin_permiso' => $admin->removeRole('admin'),
            'autorizacion_futura' => CursoPublicacion::query()->update(['autorizado_at' => now()->addDay()]),
        };
        $this->assertSame([], app(ExportadorCatalogoAdvisor::class)->construir($lector)['items']);
        Http::assertNothingSent();
    }

    public function test_export_requires_publisher_permission_not_just_authenticated_user(): void
    {
        $user = User::factory()->create();
        $this->expectException(AuthorizationException::class);
        app(ExportadorCatalogoAdvisor::class)->construir($user);
    }

    public function test_empty_catalog_and_editorial_transitions_keep_stable_identity(): void
    {
        $admin = $this->adminUser();
        $service = app(ExportadorCatalogoAdvisor::class);
        $this->assertSame([], $service->construir($admin)['items']);
        [, $curso] = $this->publicar($admin);
        $antes = $service->construir($admin);
        CursoPublicacion::query()->update(['publicada' => false]);
        $this->assertSame([], $service->construir($admin)['items']);
        CursoPublicacion::query()->update(['publicada' => true, 'titulo' => 'Redes actualizadas']);
        $despues = $service->construir($admin);
        $this->assertSame($antes['items'][0]['stable_id'], $despues['items'][0]['stable_id']);
        $this->assertNotSame($antes['signature'], $despues['signature']);
        $this->assertSame($curso->id, CursoPublicacion::firstOrFail()->curso_id);
    }

    public function test_tampering_changes_signature_and_key_is_required(): void
    {
        $export = app(ExportadorCatalogoAdvisor::class)->construir($this->adminUser());
        $export['catalog_version'] = 'alterado';
        $this->assertFalse(app(FirmaCatalogoAdvisor::class)->verificar($export));
        config(['horus-advisor.signing_key' => null]);
        $this->expectException(InvalidArgumentException::class);
        app(ExportadorCatalogoAdvisor::class)->construir($this->adminUser());
    }

    public function test_canonical_hmac_matches_python_stdlib_golden_vector(): void
    {
        // Calculado independientemente con json.dumps(ensure_ascii=False, sort_keys=True,
        // separators=(',', ':')) y hmac.new(b'k'*32, ..., hashlib.sha256).
        $raw = [
            'schema_version' => '1.0', 'catalog_version' => 'vector-1', 'source_system' => 'horus-laravel',
            'generated_at' => '2026-10-28T12:00:00-05:00', 'expires_at' => '2026-10-28T12:05:00-05:00',
            'items' => [['title' => "Formación / redes\u{2028}Perú", 'topics' => ['redes', 'formación'], 'stable_id' => 'curso:publicacion-1']],
        ];
        $firma = app(FirmaCatalogoAdvisor::class);
        $this->assertSame('hmac-sha256:0b4303a4145fa7fbd42b6a286901ab777d4eed4ddb4ba7771e77e3cc8aad797b', $firma->firmar($raw));
        $this->assertSame($firma->firmar($raw), $firma->firmar(array_reverse($raw, true)));
        $raw['signature'] = $firma->firmar($raw);
        $raw['items'][0]['title'] = 'Contenido alterado';
        $this->assertFalse($firma->verificar($raw));
    }

    public static function incompatibles(): iterable
    {
        yield 'correo' => ['resumen', 'Contacto privado ana@example.invalid'];
        yield 'dni' => ['resumen', 'Documento personal 12345678'];
        yield 'secret' => ['resumen', 'token no debe publicarse'];
        yield 'html' => ['titulo', '<b>Redes</b>'];
        yield 'short title' => ['titulo', 'Ab'];
        yield 'short summary' => ['resumen', 'Corto'];
        yield 'modality' => ['modalidad', 'inventada'];
    }

    #[DataProvider('incompatibles')]
    public function test_incompatible_editorial_text_fails_closed(string $campo, string $valor): void
    {
        [$admin] = $this->publicar();
        CursoPublicacion::query()->update([$campo => $valor]);
        $this->expectException(InvalidArgumentException::class);
        app(ExportadorCatalogoAdvisor::class)->construir($admin);
    }

    public function test_hybrid_modality_is_preserved_without_inventing_python_enum(): void
    {
        [$admin] = $this->publicar();
        CursoPublicacion::query()->update(['modalidad' => 'hibrido']);
        $item = app(ExportadorCatalogoAdvisor::class)->construir($admin)['items'][0];
        $this->assertSame([], $item['modalities']);
        $this->assertStringContainsString('hibrido', $item['details']);
    }

    /** @return array{User, Curso} */
    private function publicar(?User $admin = null): array
    {
        $admin ??= $this->adminUser();
        $curso = Curso::create(['codigo' => 'PRIVADO', 'nombre' => 'Nombre privado', 'descripcion' => 'Cupos privados', 'categoria' => 'Interno', 'duracion_horas' => 10, 'precio' => 9876.54, 'modalidad' => 'virtual', 'horario' => 'Horario privado', 'cupos_maximos' => 10, 'cupos_disponibles' => 9, 'estado' => 'activo']);
        $this->actingAs($admin)->putJson(route('admin.cursos.publicacion.update', $curso), [
            'titulo' => 'Redes iniciales', 'resumen' => 'Formación pública autorizada en redes.', 'modalidad' => 'virtual',
            'vigente_desde' => '2026-10-28', 'vigente_hasta' => '2026-10-28', 'confirmacion_publica' => '1',
        ])->assertRedirect();

        return [$admin, $curso];
    }
}
