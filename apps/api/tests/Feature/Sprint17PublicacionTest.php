<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Requests\PublicarCursoRequest;
use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\CursoPublicacion;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use App\Services\CatalogoPublicoCursos;
use App\Services\ExportadorCatalogoAdvisor;
use App\Services\FirmaCatalogoAdvisor;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\Support\LlamaCursosDelPanel;
use Tests\TestCase;

class Sprint17PublicacionTest extends TestCase
{
    use LlamaCursosDelPanel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        $this->travelTo(Carbon::parse('2026-10-28 12:00:00', 'America/Lima'));
        config(['horus-advisor.signing_key' => str_repeat('k', 32)]);
        Http::preventStrayRequests();
    }

    public function test_repeated_publication_and_withdrawal_are_noops_without_duplicate_audit(): void
    {
        [$admin, $curso] = $this->publicar();
        $antes = CursoPublicacion::firstOrFail()->getAttributes();
        $this->travel(1)->minute();
        $this->publicarFichaCursoPanel($curso, $this->datos())->assertOk();
        $this->assertSame($antes, CursoPublicacion::firstOrFail()->getAttributes());
        $this->assertSame(1, AuditLog::where('action', 'curso_publicado')->count());
        $this->retirarFichaCursoPanel($curso)->assertOk();
        $retirada = CursoPublicacion::firstOrFail()->getAttributes();
        $this->travel(1)->minute();
        $this->retirarFichaCursoPanel($curso)->assertOk();
        $this->assertSame($retirada, CursoPublicacion::firstOrFail()->getAttributes());
        $this->assertSame(1, AuditLog::where('action', 'curso_despublicado')->count());
        $this->assertSame([], app(ExportadorCatalogoAdvisor::class)->construir($admin)['items']);
    }

    public function test_withdrawal_without_a_publication_does_not_create_content_or_audit(): void
    {
        $this->actingAs($this->adminUser());
        $curso = $this->curso();
        $this->retirarFichaCursoPanel($curso)->assertOk();
        $this->assertDatabaseCount('curso_publicaciones', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'curso_despublicado']);
    }

    public function test_editor_returns_a_private_revision_and_stale_updates_or_withdrawals_fail(): void
    {
        [, $curso] = $this->publicar();
        $editor = $this->fichaCursoPanel($curso)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $revision = $editor->headers->get('ETag');
        $this->assertSame($revision, $editor->json('data.revision'));
        $this->assertMatchesRegularExpression('/\A"[a-f0-9]{64}"\z/', $revision);
        $this->publicarFichaCursoPanel($curso, $this->datos(['titulo' => 'Redes revisadas']), $revision)->assertOk();
        $this->publicarFichaCursoPanel($curso, $this->datos(['titulo' => 'Edición obsoleta']), $revision)->assertStatus(412);
        $this->retirarFichaCursoPanel($curso, $revision)->assertStatus(412);
        $this->assertDatabaseHas('curso_publicaciones', ['titulo' => 'Redes revisadas', 'publicada' => true]);
        $this->assertSame(2, AuditLog::where('action', 'curso_publicado')->count());
        $actual = $this->fichaCursoPanel($curso)->headers->get('ETag');
        $this->assertNotSame($revision, $actual);
        $this->retirarFichaCursoPanel($curso, $actual)->assertOk();
    }

    public function test_revision_for_absent_publication_detects_an_intervening_first_publish(): void
    {
        $this->actingAs($this->adminUser());
        $curso = $this->curso();
        $revision = $this->fichaCursoPanel($curso)->headers->get('ETag');
        $this->publicarFichaCursoPanel($curso, $this->datos(), $revision)->assertOk();
        $this->publicarFichaCursoPanel($curso, $this->datos(['titulo' => 'Obsoleto']), $revision)->assertStatus(412);
        $this->assertDatabaseCount('curso_publicaciones', 1);
    }

    public static function revisionesInvalidas(): iterable
    {
        yield 'unquoted' => ['incorrecta'];
        yield 'weak' => ['W/"'.str_repeat('a', 64).'"'];
        yield 'wildcard' => ['*'];
        yield 'list' => ['"'.str_repeat('a', 64).'", "'.str_repeat('b', 64).'"'];
    }

    #[DataProvider('revisionesInvalidas')]
    public function test_malformed_revision_is_rejected_without_writes(string $revision): void
    {
        [, $curso] = $this->publicar();
        $antes = CursoPublicacion::firstOrFail()->getAttributes();
        $this->publicarFichaCursoPanel($curso, $this->datos(), $revision)->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->retirarFichaCursoPanel($curso, $revision)->assertUnprocessable();
        $this->assertSame($antes, CursoPublicacion::firstOrFail()->getAttributes());
    }

    public function test_revision_cannot_be_reused_for_another_course_or_to_bypass_permission(): void
    {
        [, $uno] = $this->publicar();
        $dos = $this->curso();
        $revision = $this->fichaCursoPanel($uno)->headers->get('ETag');
        $this->publicarFichaCursoPanel($dos, $this->datos(), $revision)->assertStatus(412);
        $user = User::factory()->create();
        $user->assignRole('coordinador');
        $this->actingAs($user)->publicarFichaCursoPanel($uno, $this->datos(), $revision)->assertForbidden();
        $this->assertDatabaseCount('curso_publicaciones', 1);
    }

    public function test_permission_revoked_after_request_authorization_is_rechecked_before_write(): void
    {
        [$admin, $curso] = $this->publicar();
        $antes = CursoPublicacion::firstOrFail()->getAttributes();
        $this->app->afterResolving(PublicarCursoRequest::class, function () use ($admin): void {
            // Simula un cambio entre la autorización HTTP y la escritura; no mide concurrencia PG.
            User::findOrFail($admin->id)->removeRole('admin');
        });
        $this->publicarFichaCursoPanel($curso, $this->datos(['titulo' => 'Sin permiso actual']))->assertForbidden();
        $this->assertSame($antes, CursoPublicacion::firstOrFail()->getAttributes());
    }

    public function test_lost_staff_role_blocks_write_even_if_direct_permission_remains(): void
    {
        [$admin, $curso] = $this->publicar();
        $admin->givePermissionTo('cursos.publicar');
        $this->app->afterResolving(PublicarCursoRequest::class, fn () => User::findOrFail($admin->id)->removeRole('admin'));
        $this->publicarFichaCursoPanel($curso, $this->datos(['titulo' => 'Sin rol actual']))->assertForbidden();
        $this->assertDatabaseHas('curso_publicaciones', ['titulo' => 'Redes iniciales']);
    }

    public function test_withdrawal_rechecks_fresh_permission_after_acquiring_course(): void
    {
        [$admin, $curso] = $this->publicar();
        $disparado = false;
        // La revisión se obtiene antes de armar el disparador, para que este actúe dentro de la escritura.
        $revision = $this->revisionFichaCursoPanel($curso);
        Event::listen('eloquent.retrieved: '.Curso::class, function () use ($admin, &$disparado): void {
            if (! $disparado && DB::transactionLevel() > 0) {
                $disparado = true;
                User::findOrFail($admin->id)->removeRole('admin');
            }
        });
        try {
            $this->retirarFichaCursoPanel($curso, $revision)->assertForbidden();
            $this->assertTrue($disparado);
            $this->assertTrue(CursoPublicacion::firstOrFail()->publicada);
        } finally {
            Event::forget('eloquent.retrieved: '.Curso::class);
        }
    }

    public function test_public_read_contract_is_disabled_and_does_not_query_editorial_tables(): void
    {
        config(['catalogo-publico.enabled' => false]);
        $this->publicar();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->assertFalse(config('catalogo-publico.enabled'));
        $this->assertSame(['version' => '1.0', 'habilitado' => false, 'total' => 0, 'cursos' => []], app(CatalogoPublicoCursos::class)->paraWeb());
        $this->assertStringNotContainsString('curso_publicaciones', implode(' ', $queries));
        $this->assertFalse(config('horus-advisor.enabled'));
        $this->assertFalse(config('chatbot.public_catalog_enabled'));
    }

    public function test_public_read_is_allowlisted_deterministic_live_and_feeds_enabled_public_page(): void
    {
        [$admin, $uno] = $this->publicar();
        $this->publicar($admin, ['titulo' => 'Alfabetización digital']);
        config(['catalogo-publico.enabled' => true]);
        $service = app(CatalogoPublicoCursos::class);
        $antes = $service->paraWeb();
        $this->assertSame($antes, $service->paraWeb());
        $this->assertSame(2, $antes['total']);
        $this->assertSame('Alfabetización digital', $antes['cursos'][0]['titulo']);
        $this->assertSame(['titulo', 'resumen', 'modalidad', 'horario', 'inscripcion', 'vigente_desde', 'vigente_hasta'], array_keys($antes['cursos'][0]));
        foreach (['Nombre interno', '9876', 'autorizado_por', 'cupos', 'curso_id'] as $privado) {
            $this->assertStringNotContainsString($privado, json_encode($antes));
        }
        $this->retirarFichaCursoPanel($uno)->assertOk();
        $this->assertSame(1, $service->paraWeb()['total']);
        $export = app(ExportadorCatalogoAdvisor::class)->construir($admin);
        $this->assertCount(1, $export['items']);
        $this->assertTrue(app(FirmaCatalogoAdvisor::class)->verificar($export));
        $this->travelTo(Carbon::parse('2026-10-29 00:00:00', 'America/Lima'));
        $this->assertSame([], $service->paraWeb()['cursos']);
        $this->assertSame([], app(ExportadorCatalogoAdvisor::class)->construir($admin)['items']);
        Http::assertNothingSent();
    }

    public function test_public_projection_fails_closed_when_publication_permission_is_missing(): void
    {
        $this->publicar();
        config(['catalogo-publico.enabled' => true]);
        Permission::findByName('cursos.publicar')->delete();
        $this->assertSame([], app(CatalogoPublicoCursos::class)->paraWeb()['cursos']);
    }

    public static function accesos(): iterable
    {
        foreach (['guest', 'without_staff_role', 'secretaria'] as $usuario) {
            foreach (['GET', 'PUT', 'DELETE'] as $metodo) {
                yield $usuario.' '.$metodo => [$usuario, $metodo];
            }
        }
    }

    #[DataProvider('accesos')]
    public function test_editor_and_mutations_are_inaccessible_without_staff_and_permission(string $usuario, string $metodo): void
    {
        $curso = $this->curso();
        if ($usuario !== 'guest') {
            $this->adminUser();
            $user = User::factory()->create();
            if ($usuario === 'secretaria') {
                $user->assignRole('secretaria');
            } else {
                $user->givePermissionTo('cursos.publicar');
            }
            $this->actingAs($user);
        }
        // Panel: el editor es GET `…/publicacion`, guardar es PUT y retirar es POST `…/publicacion/retirar`.
        $response = match ($metodo) {
            'GET' => $this->fichaCursoPanel($curso),
            'PUT' => $this->publicarFichaCursoPanel($curso, $this->datos()),
            'DELETE' => $this->retirarFichaCursoPanel($curso),
        };
        $response->assertStatus($usuario === 'guest' ? 401 : 403);
        $this->assertFalse($response->headers->has('ETag'));
        $this->assertDatabaseCount('curso_publicaciones', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'curso_publicado']);
    }

    public static function ocultos(): iterable
    {
        foreach (['sin_ficha', 'retirado', 'caducado', 'futuro', 'inactivo', 'archivado', 'eliminado', 'autor_ausente', 'autor_sin_permiso', 'autorizacion_futura'] as $caso) {
            yield $caso => [$caso];
        }
    }

    #[DataProvider('ocultos')]
    public function test_web_projection_excludes_nonpublic_content(string $caso): void
    {
        [$admin, $curso] = $this->publicar();
        config(['catalogo-publico.enabled' => true]);
        match ($caso) {
            'sin_ficha' => CursoPublicacion::query()->delete(),
            'retirado' => CursoPublicacion::query()->update(['publicada' => false]),
            'caducado' => CursoPublicacion::query()->update(['vigente_desde' => '2026-10-20', 'vigente_hasta' => '2026-10-27']),
            'futuro' => CursoPublicacion::query()->update(['vigente_desde' => '2026-10-29', 'vigente_hasta' => '2026-10-30']),
            'inactivo', 'archivado' => $curso->update(['estado' => $caso]),
            'eliminado' => $curso->delete(),
            'autor_ausente' => CursoPublicacion::query()->update(['autorizado_por' => null]),
            'autor_sin_permiso' => $admin->removeRole('admin'),
            'autorizacion_futura' => CursoPublicacion::query()->update(['autorizado_at' => now()->addMinute()]),
        };
        $this->assertSame(['version' => '1.0', 'habilitado' => true, 'total' => 0, 'cursos' => []], app(CatalogoPublicoCursos::class)->paraWeb());
    }

    public function test_publication_cycle_keeps_course_enrollment_and_notification_intents_unchanged(): void
    {
        [$admin, $curso] = $this->publicar();
        $estudiante = Estudiante::create(['dni' => '00000001', 'nombres' => 'Persona ficticia', 'apellidos' => 'Prueba', 'email' => 'ficticio@example.invalid', 'estado' => 'activo']);
        $matricula = Matricula::create(['estudiante_id' => $estudiante->id, 'curso_id' => $curso->id, 'fecha_matricula' => '2026-10-28', 'estado' => 'pendiente', 'monto_pagado' => 0]);
        $antesCurso = $curso->fresh()->getAttributes();
        $antesMatricula = $matricula->fresh()->getAttributes();
        $avisos = DB::table('matricula_notifications')->get()->map(fn ($r) => (array) $r)->all();
        $this->publicarFichaCursoPanel($curso, $this->datos(['titulo' => 'Ficha actualizada', 'curso_id' => 999, 'autorizado_por' => 999, 'precio' => 1, 'cupos_disponibles' => 0]))->assertOk();
        $this->retirarFichaCursoPanel($curso)->assertOk();
        $this->publicarFichaCursoPanel($curso, $this->datos())->assertOk();
        $this->assertSame($antesCurso, $curso->fresh()->getAttributes());
        $this->assertSame($antesMatricula, $matricula->fresh()->getAttributes());
        $this->assertSame($avisos, DB::table('matricula_notifications')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertSame($admin->id, CursoPublicacion::firstOrFail()->autorizado_por);
        Http::assertNothingSent();
    }

    public function test_withdrawal_audit_failure_rolls_back_content_and_revision(): void
    {
        [, $curso] = $this->publicar();
        $antes = CursoPublicacion::firstOrFail()->getAttributes();
        Event::listen('eloquent.creating: '.AuditLog::class, static function (): void {
            throw new RuntimeException('fallo simulado');
        });
        $this->withoutExceptionHandling();
        try {
            $this->retirarFichaCursoPanel($curso);
            $this->fail('Se esperaba rollback');
        } catch (RuntimeException $e) {
            $this->assertSame('fallo simulado', $e->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.AuditLog::class);
        }
        $this->assertSame($antes, CursoPublicacion::firstOrFail()->getAttributes());
    }

    private function curso(): Curso
    {
        return Curso::create(['codigo' => 'PRIVADO-'.Curso::withTrashed()->count(), 'nombre' => 'Nombre interno', 'descripcion' => 'Descripción privada', 'categoria' => 'Interno', 'duracion_horas' => 10, 'precio' => 9876.54, 'modalidad' => 'virtual', 'horario' => 'Horario privado', 'cupos_maximos' => 10, 'cupos_disponibles' => 9, 'estado' => 'activo']);
    }

    private function datos(array $cambios = []): array
    {
        return array_replace(['titulo' => 'Redes iniciales', 'resumen' => 'Formación pública autorizada en redes.', 'modalidad' => 'virtual', 'horario' => null, 'inscripcion' => null, 'vigente_desde' => '2026-10-28', 'vigente_hasta' => '2026-10-28', 'confirmacion_publica' => '1'], $cambios);
    }

    private function publicar(?User $admin = null, array $cambios = []): array
    {
        $admin ??= $this->adminUser();
        $curso = $this->curso();
        $this->actingAs($admin)->publicarFichaCursoPanel($curso, $this->datos($cambios))->assertOk();

        return [$admin, $curso];
    }
}
