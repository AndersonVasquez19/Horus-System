<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AsistenteVirtualService;
use App\Services\HorusAdvisorClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Canales públicos desde una sola fuente (22/11/2026): `public_contact` de knowledge.json, que Horus AI usa para
 * escribir cada teléfono, correo y enlace de WhatsApp. Laravel conserva sus constantes de validación y su mensaje de
 * contingencia (deben existir aunque Horus AI no responda); esta prueba falla si dejan de coincidir con esa fuente.
 */
class ContactoPublicoUnicoTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{phone: string, email: string} */
    private function contactoPublico(): array
    {
        $conocimiento = json_decode((string) file_get_contents((string) config('horus-knowledge.path')), true, 512, JSON_THROW_ON_ERROR);
        $contacto = $conocimiento['public_contact'] ?? null;
        $this->assertIsArray($contacto);
        $this->assertIsString($contacto['phone'] ?? null);
        $this->assertIsString($contacto['email'] ?? null);

        return ['phone' => $contacto['phone'], 'email' => $contacto['email']];
    }

    public function test_las_constantes_de_laravel_coinciden_con_el_contacto_publico(): void
    {
        ['phone' => $telefono, 'email' => $correo] = $this->contactoPublico();
        $whatsapp = 'https://wa.me/'.preg_replace('/\D/', '', $telefono);

        $this->assertSame([
            $whatsapp.'?text='.rawurlencode('Hola, deseo solicitar una cotización'),
            $whatsapp.'?text='.rawurlencode('Hola, deseo consultar sobre la matrícula'),
            $whatsapp,
            $telefono,
            $correo,
        ], array_slice(HorusAdvisorClient::CANALES_OFICIALES_PUBLICOS, 0, 5));
        $this->assertStringContainsString($telefono, AsistenteVirtualService::RESPUESTA_FALLBACK);
        $this->assertStringContainsString($correo, AsistenteVirtualService::RESPUESTA_FALLBACK);
    }

    /** @return iterable<string, array{int}> */
    public static function abstenciones(): iterable
    {
        yield 'cifras publicitarias' => [0];
        // 'convenio pendiente' (ISAM) salió el 27/11/2026: la ficha se verificó con el texto de la web.
    }

    #[DataProvider('abstenciones')]
    public function test_una_abstencion_con_el_canal_del_asesor_llega_al_visitante(int $caso): void
    {
        $casos = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/chat-sin-callejones.json'), true, 512, JSON_THROW_ON_ERROR);
        $reply = $casos[$caso]['reply'];
        config(['horus-advisor.enabled' => true, 'horus-advisor.url' => 'http://127.0.0.1:8001',
            'horus-advisor.token' => str_repeat('t', 32), 'horus-catalog.sync_enabled' => false]);
        Http::preventStrayRequests();
        Http::fake(['*/ready' => Http::response(['status' => 'ready', 'knowledge' => 'loaded', 'auth' => 'configured',
            'semantic' => 'disabled', 'catalog' => 'none']), '*/api/v1/chat' => Http::response($reply)]);

        $respuesta = app(HorusAdvisorClient::class)->orientar($casos[$caso]['query']);

        $this->assertNotNull($respuesta, 'Antes del 22/11/2026 esta abstención honesta ya pasaba; la línea del asesor no debe romperla.');
        $this->assertSame($reply['answer'], $respuesta['respuesta']);
        $this->assertStringContainsString($this->contactoPublico()['phone'], $respuesta['respuesta']);
    }
}
