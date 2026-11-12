<?php

declare(strict_types=1);

namespace Tests\ConsolidacionPostgres;

use App\Models\ChatLog;
use App\Models\User;
use App\Services\AsistenteVirtualService;
use App\Services\ContenidoPublicoServicios;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\PostgresIntegrity\Concerns\ReclamacionesPostgres;

class MigracionesAprobadasTest extends CasoPostgresReversible
{
    use ReclamacionesPostgres;

    protected bool $ddlAutorizado = true;

    public function test_000033_check_editorial_y_reversion_sin_perdida_preexistente(): void
    {
        $this->assertFalse(Schema::hasTable('servicio_publicacion_elementos'), '000033 ya existe: requiere revisión independiente.');
        $this->assertFalse(Schema::hasColumn('servicio_publicaciones', 'titulo_tarjetas'));
        $nombre = '2026_11_02_000033_add_contenido_to_servicio_publicaciones';
        $migracion = $this->migracion($nombre);
        $migracion->up();
        $registro = $this->fixture('migrations', ['migration' => $nombre, 'batch' => 999999]);
        $this->assertSame($nombre, DB::table('migrations')->where('id', $registro)->value('migration'));
        $autor = User::factory()->create();
        $autor->assignRole('admin');
        $this->assertTrue($autor->can('servicios.publicar'));
        $this->assertFalse(DB::table('servicios_tecnologicos')->where('codigo', 'CABLEADO')->exists(), 'Código editorial ocupado: no se altera la fila existente.');
        $servicio = $this->fixture('servicios_tecnologicos', ['identificador_publico' => (string) Str::uuid(),
            'codigo' => 'CABLEADO', 'nombre' => 'Servicio sintético', 'estado' => 'activo', 'revision' => 1]);
        $publicacion = $this->fixture('servicio_publicaciones', ['servicio_tecnologico_id' => $servicio,
            'titulo' => 'Cableado sintético', 'resumen' => 'Contenido editorial sintético.',
            'descripcion' => 'Descripción editorial sintética.', 'estado' => 'publicada',
            'vigente_desde' => now()->subDay()->toDateString(), 'vigente_hasta' => now()->addDay()->toDateString(),
            'autorizado_por' => $autor->id, 'autorizado_at' => now(), 'titulo_tarjetas' => 'Tarjetas sintéticas']);
        $fila = ['servicio_publicacion_id' => $publicacion, 'titulo' => 'Tarjeta sintética',
            'descripcion' => 'Descripción sintética.', 'activo' => true];
        $elemento = $this->fixture('servicio_publicacion_elementos', $fila + ['orden' => 0]);
        foreach ([-1, 10000] as $orden) {
            foreach ([fn () => $this->fixture('servicio_publicacion_elementos', $fila + ['orden' => $orden]),
                fn () => DB::table('servicio_publicacion_elementos')->where('id', $elemento)->update(['orden' => $orden])] as $operacion) {
                $error = $this->assertSqlState('23514', $operacion);
                $this->assertStringContainsString('servicio_elementos_orden_rango', $error->getMessage());
            }
        }
        DB::table('servicio_publicacion_elementos')->where('id', $elemento)->update(['orden' => 9999]);
        config(['catalogo-servicios.enabled' => true]);
        $pagina = app(ContenidoPublicoServicios::class)->pagina('CABLEADO');
        $this->assertNotNull($pagina);
        $this->assertSame('Cableado sintético', $pagina['titulo']);
        $this->assertCount(1, $pagina['tarjetas']);
        $this->assertSame('Tarjeta sintética', $pagina['tarjetas'][0]['titulo']);
        DB::table('servicio_publicaciones')->where('id', $publicacion)->update(['estado' => 'retirada']);
        $this->assertNull(app(ContenidoPublicoServicios::class)->pagina('CABLEADO'));
        $migracion->down();
        $this->assertFalse(Schema::hasTable('servicio_publicacion_elementos'));
        $this->assertFalse(Schema::hasColumn('servicio_publicaciones', 'titulo_tarjetas'));
        $this->assertTrue(DB::table('servicio_publicaciones')->where('id', $publicacion)->exists());
    }

    public function test_000034_conserva_historicos_y_audita_sin_texto_privado(): void
    {
        $this->assertFalse(Schema::hasColumn('chat_logs', 'fuente'), '000034 ya existe: no se revierte.');
        $nombre = '2026_11_03_000034_add_fuente_to_chat_logs';
        $migracion = $this->migracion($nombre);
        $historico = $this->fixture('chat_logs', ['session_id' => 'consolidacion-historico',
            'mensaje_usuario' => '[contenido omitido por privacidad]', 'respuesta_bot' => 'Contingencia sintética']);
        $migracion->up();
        $this->fixture('migrations', ['migration' => $nombre, 'batch' => 999999]);
        $this->assertNull(DB::table('chat_logs')->where('id', $historico)->value('fuente'));
        $resultado = app(AsistenteVirtualService::class)->procesarMensaje('cursos', 'consolidacion-fuente');
        $this->assertSame('respaldo', $resultado['fuente']);
        $session = hash_hmac('sha256', 'direct|consolidacion-fuente', (string) config('app.key'));
        $registro = ChatLog::query()->where('session_id', $session)->sole();
        $this->assertSame('respaldo', $registro->fuente);
        $this->assertSame('[contenido omitido por privacidad]', $registro->mensaje_usuario);
        Http::assertNothingSent();
        $migracion->down();
        $this->assertFalse(Schema::hasColumn('chat_logs', 'fuente'));
        $this->assertTrue(DB::table('chat_logs')->where('id', $historico)->exists());
    }
}
