<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChatLog;
use App\Services\AsistenteVirtualService;
use App\Services\MetricasAsistente;
use App\Support\IntencionesAsistente;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ChatPublicoBff;
use Tests\TestCase;

/** Bloque D: canal, intención y 👍/👎 en el registro del chat, y las cifras del panel. */
class AsistenteMetricasTest extends TestCase
{
    use ChatPublicoBff;
    use RefreshDatabase;

    private const SESION = 'sesion-de-prueba-0123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertIsolatedTestDatabase();
        config(['horus-advisor.enabled' => false]);
        Http::preventStrayRequests();
    }

    private function preguntar(string $mensaje = '¿Qué cursos hay?'): string
    {
        $referencia = $this->postChatPublico(['mensaje' => $mensaje, 'session_id' => self::SESION])
            ->assertOk()->json('respuesta_id');
        $this->assertIsString($referencia);

        return $referencia;
    }

    /** @param array<string, string> $cambios */
    private function votar(string $referencia, array $cambios = []): TestResponse
    {
        $token = 'token-de-prueba-bff-0123456789abcdefghij';
        config(['horus-web.service_token' => $token]);

        return $this->postJson('/api/public/v1/chat/voto',
            [...['session_id' => self::SESION, 'respuesta_id' => $referencia, 'voto' => 'up'], ...$cambios],
            ['X-Horus-Web-Token' => $token]);
    }

    public function test_cada_consulta_guarda_el_canal_y_entrega_una_referencia_votable(): void
    {
        $referencia = $this->preguntar();
        $this->assertSame('web', ChatLog::sole()->getAttribute('canal'));

        $this->votar($referencia)->assertOk();
        $this->assertSame('up', ChatLog::sole()->getAttribute('voto'));
        // Solo cuenta el primer voto.
        $this->votar($referencia, ['voto' => 'down'])->assertOk();
        $this->assertSame('up', ChatLog::sole()->getAttribute('voto'));
    }

    public function test_un_envio_repetido_apunta_a_la_misma_fila(): void
    {
        $this->assertSame($this->preguntar(), $this->preguntar());
        $this->assertSame(1, ChatLog::count());
    }

    public function test_no_se_puede_votar_con_otra_sesion_ni_con_una_referencia_alterada(): void
    {
        $referencia = $this->preguntar();
        [$id, $firma] = explode('.', $referencia);

        $this->votar($referencia, ['session_id' => 'otra-sesion-0123456789'])->assertNotFound();
        $this->votar(((int) $id + 1).'.'.$firma)->assertNotFound();
        $this->votar($referencia, ['voto' => 'excelente'])->assertStatus(422);
        $this->votar('1.abc')->assertStatus(422);
        $this->assertNull(ChatLog::sole()->getAttribute('voto'));
    }

    private function contacto(string $referencia, string $sesion = self::SESION): TestResponse
    {
        $token = 'token-de-prueba-bff-0123456789abcdefghij';
        config(['horus-web.service_token' => $token]);

        return $this->postJson('/api/public/v1/chat/contacto', ['session_id' => $sesion, 'respuesta_id' => $referencia],
            ['X-Horus-Web-Token' => $token]);
    }

    public function test_el_clic_en_whatsapp_se_anota_solo_en_una_respuesta_propia(): void
    {
        $referencia = $this->preguntar();
        $this->assertNull(ChatLog::sole()->getAttribute('abrio_whatsapp'));

        $this->contacto($referencia, 'otra-sesion-0123456789')->assertNotFound();
        $this->contacto('1.'.str_repeat('a', 64))->assertNotFound();
        $this->contacto('abc')->assertStatus(422);
        $this->assertNull(ChatLog::sole()->getAttribute('abrio_whatsapp'));

        $this->contacto($referencia)->assertOk();
        $this->contacto($referencia)->assertOk();
        $this->assertTrue((bool) ChatLog::sole()->getAttribute('abrio_whatsapp'));
        $this->assertSame(1, app(MetricasAsistente::class)->resumen()['etapas']['whatsapp']);
    }

    public function test_el_clic_en_whatsapp_exige_el_token_de_servicio(): void
    {
        config(['horus-web.service_token' => 'token-de-prueba-bff-0123456789abcdefghij']);
        $this->postJson('/api/public/v1/chat/contacto', ['session_id' => self::SESION, 'respuesta_id' => '1.'.str_repeat('a', 64)])
            ->assertUnauthorized();
    }

    public function test_antes_de_la_migracion_de_contacto_el_clic_no_rompe_nada(): void
    {
        Schema::table('chat_logs', fn ($tabla) => $tabla->dropColumn('abrio_whatsapp'));

        $referencia = $this->preguntar();
        $this->contacto($referencia)->assertStatus(503);
        $this->assertNull(app(MetricasAsistente::class)->resumen()['etapas']['whatsapp']);
        $this->votar($referencia)->assertOk();
    }

    public function test_las_etapas_cuentan_conversaciones_distintas_y_el_origen_desconocido_queda_aparte(): void
    {
        $fila = static fn (string $sesion, string $respuesta, array $datos = []): ChatLog => ChatLog::create([...[
            'session_id' => $sesion, 'mensaje_usuario' => '', 'respuesta_bot' => $respuesta, 'fuente' => 'advisor', 'canal' => 'web',
        ], ...$datos]);
        $fila('a', 'Cursos y capacitaciones vigentes: X.');
        $fila('a', 'Para matricularte sigue estos pasos: 1.');
        $fila('a', 'Para matricularte sigue estos pasos: 1.');
        $fila('b', 'Ofrecemos capacitaciones empresariales.');
        $fila('c', 'Puedes registrar un reclamo en el Libro de Reclamaciones virtual.', ['fuente' => null]);
        $fila('d', 'Para consultar la matrícula, continúa.', ['canal' => 'admin']);

        $resumen = app(MetricasAsistente::class)->resumen();

        $this->assertSame(['interesado' => 2, 'matricula' => 1, 'contacto' => 0, 'reclamos' => 1, 'whatsapp' => 0], $resumen['etapas']);
        $this->assertSame([5, 4, 0, 1], [$resumen['consultas'], $resumen['respondidas'], $resumen['contingencia'], $resumen['sin_origen']]);
    }

    public function test_el_voto_exige_el_token_de_servicio(): void
    {
        config(['horus-web.service_token' => 'token-de-prueba-bff-0123456789abcdefghij']);
        $this->postJson('/api/public/v1/chat/voto', ['session_id' => self::SESION, 'respuesta_id' => '1.'.str_repeat('a', 64), 'voto' => 'up'])
            ->assertUnauthorized();
    }

    public function test_antes_de_la_migracion_el_chat_responde_y_el_panel_no_falla(): void
    {
        Schema::table('chat_logs', fn ($tabla) => $tabla->dropColumn(['canal', 'voto']));

        $referencia = $this->preguntar();
        $this->assertSame('respaldo', ChatLog::sole()->getAttribute('fuente'));
        $this->votar($referencia)->assertStatus(503);

        $resumen = app(MetricasAsistente::class)->resumen();
        $this->assertSame(1, $resumen['consultas']);
        $this->assertNull($resumen['votos']);
        $this->assertFalse($resumen['separa_canal']);
    }

    public function test_la_migracion_se_aplica_y_se_revierte_sobre_una_base_con_registros(): void
    {
        $migracion = require database_path('migrations/2026_11_25_000104_add_metricas_to_chat_logs.php');
        $contacto = require database_path('migrations/2026_11_25_000105_add_abrio_whatsapp_to_chat_logs.php');
        $contacto->down();
        $migracion->down();
        DB::table('chat_logs')->insert([
            'session_id' => 'historico', 'mensaje_usuario' => '', 'respuesta_bot' => 'r', 'fuente' => 'advisor',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migracion->up();
        $contacto->up();

        $fila = ChatLog::sole();
        $this->assertNull($fila->getAttribute('abrio_whatsapp'));
        $this->assertSame('historico', $fila->getAttribute('session_id'));
        $this->assertNull($fila->getAttribute('canal'));
        $this->assertNull($fila->getAttribute('voto'));
        $this->assertSame(1, app(MetricasAsistente::class)->resumen()['consultas']);
        $contacto->down();
        $migracion->down();
        $this->assertSame(1, ChatLog::count());
        $migracion->up();
        $contacto->up();
    }

    public function test_el_resumen_excluye_el_probador_y_las_consultas_antiguas(): void
    {
        $fila = static fn (array $datos): ChatLog => ChatLog::create([...[
            'session_id' => 'a', 'mensaje_usuario' => '', 'respuesta_bot' => '', 'fuente' => 'advisor', 'canal' => 'web',
        ], ...$datos]);
        $fila(['intent_detectado' => 'training_info', 'voto' => 'up']);
        $fila(['intent_detectado' => 'training_info', 'voto' => 'down']);
        $fila(['intent_detectado' => 'unavailable_info', 'session_id' => 'b']);
        $fila(['fuente' => 'respaldo', 'canal' => null]);
        $fila(['intent_detectado' => 'greeting', 'canal' => 'admin', 'voto' => 'up']);
        $fila(['intent_detectado' => 'greeting'])->forceFill(['created_at' => now()->subDays(31)])->save();

        $resumen = app(MetricasAsistente::class)->resumen();

        $this->assertSame(4, $resumen['consultas']);
        $this->assertSame(2, $resumen['conversaciones']);
        $this->assertSame(3, $resumen['respondidas']);
        $this->assertSame(1, $resumen['contingencia']);
        $this->assertSame(1, $resumen['sin_dato']);
        $this->assertSame(3, $resumen['con_tema']);
        $this->assertSame([['nombre' => 'training_info', 'total' => 2], ['nombre' => 'unavailable_info', 'total' => 1]], $resumen['temas']);
        $this->assertSame(['utiles' => 1, 'no_utiles' => 1], $resumen['votos']);
    }

    public function test_las_cortesias_se_agrupan_y_el_grafico_cuenta_por_dia_de_lima(): void
    {
        // 02:00 UTC del día 25 son las 21:00 del 24 en Lima.
        $this->travelTo(CarbonImmutable::parse('2026-11-25 15:00:00', 'UTC'));
        $fila = static fn (array $datos): ChatLog => ChatLog::create([...[
            'session_id' => 'a', 'mensaje_usuario' => '', 'respuesta_bot' => '', 'fuente' => 'advisor', 'canal' => 'web',
        ], ...$datos]);
        $fila(['intent_detectado' => 'greeting']);
        $fila(['intent_detectado' => 'thanks']);
        $fila(['intent_detectado' => null])->forceFill(['created_at' => '2026-11-25 02:00:00'])->save();

        $resumen = app(MetricasAsistente::class)->resumen(7);

        $this->assertSame([['nombre' => 'cortesia', 'total' => 2]], $resumen['temas']);
        $this->assertSame(2, $resumen['con_tema']);
        $this->assertCount(7, $resumen['por_dia']);
        $this->assertSame([['etiqueta' => '24/11', 'total' => 1], ['etiqueta' => '25/11', 'total' => 2]], array_slice($resumen['por_dia'], -2));
    }

    public function test_el_probador_se_registra_como_admin(): void
    {
        app(AsistenteVirtualService::class)->procesarMensaje('¿Qué cursos hay?', 'x', 'admin:sesion');

        $this->assertSame('admin', ChatLog::sole()->getAttribute('canal'));
        $this->assertSame(0, app(MetricasAsistente::class)->resumen()['consultas']);
    }

    public function test_toda_intencion_de_los_fixtures_del_motor_esta_en_la_lista_cerrada(): void
    {
        $vistas = [];
        foreach (glob(base_path('tests/Fixtures/chat-*.json')) ?: [] as $archivo) {
            preg_match_all('/"intent":\s*"([a-z_]+)"/', (string) file_get_contents($archivo), $coincidencias);
            $vistas = [...$vistas, ...$coincidencias[1]];
        }

        $this->assertNotEmpty($vistas);
        $this->assertSame([], array_values(array_diff(array_unique($vistas), IntencionesAsistente::CONOCIDAS)));
        $this->assertSame('otro', IntencionesAsistente::normalizar('me_llamo_pedro'));
    }
}
