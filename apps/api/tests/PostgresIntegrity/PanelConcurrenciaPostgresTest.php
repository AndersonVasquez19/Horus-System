<?php

declare(strict_types=1);

namespace Tests\PostgresIntegrity;

use App\Models\AuditLog;
use App\Models\ServicioPublicacion;
use App\Models\ServicioTecnologico;
use App\Models\SolicitudCotizacion;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\PostgresIntegrity\Concerns\RequiresIsolatedPostgres;
use Tests\TestCase;

/**
 * Fase 5.2, paso 3 — matriz a nivel servicio / HTTP del panel sobre PostgreSQL real: revisión (ETag 200 / 412),
 * publicación de curso, creación idempotente de cotizaciones en carrera, archivo y registro de imágenes y auditoría.
 *
 * Datos ficticios; solo la base desechable del perfil aislado (127.0.0.1:55433, horus_l2_test). Usan DatabaseTruncation
 * (con RefreshDatabase la segunda sesión no vería los datos). Protocolo (igual que MatriculaOperacionesConcurrencia…):
 * la sesión B toma el bloqueo de la fila; la petición real, con lock_timeout corto, debe quedar BLOQUEADA (55P03) y no
 * dejar rastro; al confirmar B se repite y decide con el estado ya confirmado.
 */
class PanelConcurrenciaPostgresTest extends TestCase
{
    use DatabaseTruncation;
    use RequiresIsolatedPostgres;

    private const TOKEN = 'token-de-servicio-del-bff-para-pruebas-0123456789';

    private const LOCK_NOT_AVAILABLE = '55P03';

    private const CHECK_VIOLATION = '23514';

    // Utilidades ---------------------------------------------------------------------------------------------------

    private function admin(): User
    {
        config(['horus-web.service_token' => self::TOKEN]);

        return $this->adminUser();
    }

    /** @param array<string, mixed> $datos */
    private function como(User $user, string $metodo, string $ruta, array $datos = []): TestResponse
    {
        return $this->actingAs($user)
            ->withHeaders(['X-Horus-Web-Token' => self::TOKEN])
            ->json($metodo, '/api/panel/v1/'.$ruta, $datos);
    }

    private function assertBlocked(callable $peticion): void
    {
        $this->withoutExceptionHandling();
        try {
            $peticion();
            $this->fail('La petición debía quedar bloqueada por la transacción de la otra sesión.');
        } catch (QueryException $e) {
            $this->assertSame(self::LOCK_NOT_AVAILABLE, $e->getCode(), 'Debe esperar el bloqueo (55P03), no fallar por otra causa.');
        } finally {
            $this->withExceptionHandling();
        }
    }

    private function servicioConFicha(string $codigo): ServicioTecnologico
    {
        $servicio = ServicioTecnologico::forceCreate([
            'identificador_publico' => (string) Str::uuid(), 'codigo' => $codigo, 'nombre' => "Servicio {$codigo}",
            'categoria' => 'redes', 'estado' => 'activo', 'revision' => 1,
        ]);
        $ficha = new ServicioPublicacion([
            'titulo' => 'Redes', 'resumen' => 'Resumen', 'descripcion' => 'Descripción pública.',
            'vigente_desde' => now('America/Lima')->toDateString(), 'vigente_hasta' => now('America/Lima')->addMonth()->toDateString(),
        ]);
        $ficha->servicio_tecnologico_id = $servicio->id;
        $ficha->estado = 'borrador';
        $ficha->save();

        return $servicio->refresh();
    }

    /** @return array<string, mixed> */
    private function fichaCurso(array $cambios = []): array
    {
        return [
            'titulo' => 'Excel Intermedio', 'resumen' => 'Aprende tablas dinámicas.', 'modalidad' => 'virtual',
            'vigente_desde' => now('America/Lima')->toDateString(), 'vigente_hasta' => now('America/Lima')->addMonth()->toDateString(),
            'confirmacion_publica' => true, ...$cambios,
        ];
    }

    // 1. ETag en servicios --------------------------------------------------------------------------------------------

    public function test_dos_escrituras_con_la_misma_revision_de_servicio_una_200_y_otra_412(): void
    {
        $admin = $this->admin();
        $servicio = $this->servicioConFicha('ETAG-1');
        $revision = $servicio->etag();

        $this->como($admin, 'PUT', "servicios/servicios/{$servicio->id}", ['nombre' => 'Primero', '_revision' => $revision])->assertOk();
        $auditorias = AuditLog::count();

        $this->como($admin, 'PUT', "servicios/servicios/{$servicio->id}", ['nombre' => 'Segundo', '_revision' => $revision])->assertStatus(412);

        $this->assertSame('Primero', $servicio->refresh()->nombre, 'La escritura obsoleta no cambia nada.');
        $this->assertSame($auditorias, AuditLog::count(), 'La escritura rechazada no audita.');
        $this->assertSame(2, (int) $servicio->revision, 'Una sola escritura avanzó la revisión.');
    }

    public function test_escritura_de_servicio_espera_el_bloqueo_y_la_revision_obsoleta_da_412(): void
    {
        $admin = $this->admin();
        $servicio = $this->servicioConFicha('ETAG-2');
        $obsoleta = $servicio->etag();
        $auditorias = AuditLog::count();

        $b = $this->secondSession();
        $b->beginTransaction();
        $b->select('select id from servicios_tecnologicos where id = ? for update', [$servicio->id]);
        $b->update('update servicios_tecnologicos set revision = revision + 1 where id = ?', [$servicio->id]);

        $this->limitLockWait();
        $this->assertBlocked(fn () => $this->como($admin, 'PUT', "servicios/servicios/{$servicio->id}", ['nombre' => 'Bloqueado', '_revision' => $obsoleta]));
        $this->assertSame($auditorias, AuditLog::count(), 'El intento bloqueado no deja auditoría.');

        $b->commit();

        $this->como($admin, 'PUT', "servicios/servicios/{$servicio->id}", ['nombre' => 'Obsoleto', '_revision' => $obsoleta])->assertStatus(412);
        $this->como($admin, 'PUT', "servicios/servicios/{$servicio->id}", ['nombre' => 'Vigente', '_revision' => $servicio->refresh()->etag()])->assertOk();

        $this->assertSame('Vigente', $servicio->refresh()->nombre);
        $this->assertSame(1, AuditLog::count() - $auditorias, 'Una sola escritura efectiva, una sola auditoría.');
    }

    // 2. Publicación de curso en paralelo -----------------------------------------------------------------------------

    public function test_publicar_y_retirar_un_curso_en_paralelo_deja_un_estado_coherente(): void
    {
        $admin = $this->admin();
        $curso = $this->makeCourse('PG52-PUB', 10);
        $revision = fn (): string => (string) $this->como($admin, 'GET', "academico/cursos/{$curso->id}/publicacion")->assertOk()->json('data.revision');

        $this->como($admin, 'PUT', "academico/cursos/{$curso->id}/publicacion", $this->fichaCurso(['_revision' => $revision()]))->assertOk();
        $publicada = $revision();
        $this->assertSame(1, AuditLog::where('action', 'curso_publicado')->count());

        // La sesión B (otra petición) tiene el curso bloqueado: el retiro espera y no deja rastro.
        $b = $this->secondSession();
        $b->beginTransaction();
        $b->select('select id from cursos where id = ? for update', [$curso->id]);

        $this->limitLockWait();
        $this->assertBlocked(fn () => $this->como($admin, 'POST', "academico/cursos/{$curso->id}/publicacion/retirar", ['_revision' => $publicada]));
        $this->assertSame(0, AuditLog::where('action', 'curso_despublicado')->count());

        $b->commit();

        $this->como($admin, 'POST', "academico/cursos/{$curso->id}/publicacion/retirar", ['_revision' => $publicada])->assertOk();
        // Publicar con la revisión de antes del retiro: obsoleta.
        $this->como($admin, 'PUT', "academico/cursos/{$curso->id}/publicacion", $this->fichaCurso(['_revision' => $publicada]))->assertStatus(412);

        $this->assertSame(1, AuditLog::where('action', 'curso_publicado')->count());
        $this->assertSame(1, AuditLog::where('action', 'curso_despublicado')->count());
        $this->assertSame(1, DB::table('curso_publicaciones')->where('curso_id', $curso->id)->count(), 'Una sola ficha por curso.');
    }

    // 3. Cotizaciones: creación idempotente en carrera -----------------------------------------------------------------

    public function test_creacion_de_cotizacion_con_la_misma_clave_en_carrera_se_reconcilia_con_200(): void
    {
        $admin = $this->admin();
        $datos = [
            'contacto_nombre' => 'Cliente Manual', 'necesidad_cliente' => 'Cablear dos pisos.', 'contacto_email' => 'cliente@example.test',
        ];

        // Una creación previa con otra clave fija el contenido (la huella depende solo de los datos).
        $this->como($admin, 'POST', 'servicios/cotizaciones', $datos + ['_clave' => (string) Str::uuid()])->assertCreated();
        $modelo = DB::table('solicitudes_cotizacion')->first();
        $this->assertNotNull($modelo);

        $clave = (string) Str::uuid();
        $b = $this->secondSession();
        $hecho = false;
        $codigoGanadora = 'SOL-GANADORA-'.Str::upper(Str::random(6));
        $principal = DB::connection();
        DB::listen(function (QueryExecuted $q) use ($b, $principal, $modelo, $clave, $codigoGanadora, &$hecho): void {
            // Justo después de que la petición lea «¿ya existe esta clave?» (SELECT … FOR UPDATE) y no encuentre nada,
            // otra petición confirma la misma clave: el INSERT de la principal termina en 23505 y se reconcilia.
            if (! $hecho && $q->connection === $principal && str_contains($q->sql, 'from "solicitudes_cotizacion"') && str_contains($q->sql, 'for update')) {
                $hecho = true;
                $fila = (array) $modelo;
                unset($fila['id']);
                $fila['identificador'] = (string) Str::uuid();
                $fila['codigo'] = $codigoGanadora;
                $fila['creacion_clave_hash'] = hash('sha256', strtolower($clave));
                $b->table('solicitudes_cotizacion')->insert($fila);
            }
        });

        $respuesta = $this->como($admin, 'POST', 'servicios/cotizaciones', $datos + ['_clave' => $clave]);

        $this->assertTrue($hecho, 'La carrera se provocó.');
        $respuesta->assertOk()->assertJsonPath('data.codigo', $codigoGanadora);
        $this->assertSame(2, SolicitudCotizacion::count(), 'La clave repetida no crea un tercer expediente.');
        $this->assertSame(1, AuditLog::where('action', 'cotizacion_creada')->count(), 'La repetición no audita una segunda creación.');
    }

}
