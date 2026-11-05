<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChatIntent;
use App\Models\ChatLog;
use App\Services\AsistenteVirtualService;
use App\Services\HorusAdvisorClient;
use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HorusAiTransicionTest extends TestCase
{
    use RefreshDatabase;

    public function test_additive_source_migration_preserves_old_rows_and_unmigrated_chat_works(): void
    {
        Http::preventStrayRequests();
        config(['horus-advisor.enabled' => false]);
        $migration = require database_path('migrations/2026_11_03_000034_add_fuente_to_chat_logs.php');
        $migration->down();
        try {
            $result = app(AsistenteVirtualService::class)->procesarMensaje('cursos', 'old-schema', 'public:test');
            $this->assertSame('respaldo', $result['fuente']);
            $row = ChatLog::sole();
            $this->assertSame('[contenido omitido por privacidad]', $row->mensaje_usuario);
            $this->assertArrayNotHasKey('fuente', $row->getAttributes());
        } finally {
            $migration->up();
        }
        $row = ChatLog::sole();
        $this->assertNull($row->getAttribute('fuente'));
        $this->assertSame(AsistenteVirtualService::RESPUESTA_FALLBACK, $row->respuesta_bot);
        Http::assertNothingSent();
    }

    public function test_advisor_rejects_sensitive_requests_and_unverified_commercial_claims(): void
    {
        config(['horus-advisor.enabled' => true, 'horus-advisor.token' => str_repeat('t', 32)]);
        foreach (['El asesor es Juan Pérez', 'Para avanzar envíame tu DNI y tu número de celular por aquí', 'El curso cuesta ciento cincuenta soles', 'Inicia el 15 de diciembre de 2026', 'Quedan cinco cupos confirmados', 'El horario es de nueve a cinco'] as $answer) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake([
                '*/ready' => Http::response(['status' => 'ready', 'knowledge' => 'loaded', 'auth' => 'configured', 'catalog' => 'none', 'semantic' => 'disabled']),
                '*/api/v1/chat' => Http::response(['answer' => $answer, 'intent' => 'recommendation', 'mode' => 'lexical', 'match_level' => 'alta', 'abstained' => false, 'needs_clarification' => false, 'recommendations' => [], 'sources' => [['id' => 'formacion', 'title' => 'Formación', 'url_path' => '/educacion/cursos']]]),
            ]);
            $this->assertNull(app(HorusAdvisorClient::class)->orientar('cursos'), $answer);
        }
    }

    public function test_explicit_environment_activation_is_loaded_into_configuration(): void
    {
        $property = new \ReflectionProperty(Env::class, 'repository');
        $previous = $property->getValue();
        $repository = RepositoryBuilder::createWithNoAdapters()
            ->addAdapter(ArrayAdapter::class)->make();
        try {
            $property->setValue(null, $repository);
            $configuration = require config_path('horus-advisor.php');
            $this->assertFalse($configuration['enabled']);
            $repository->set('HORUS_ADVISOR_ENABLED', 'true');
            $configuration = require config_path('horus-advisor.php');
            $this->assertTrue($configuration['enabled']);
            $repository->set('HORUS_ADVISOR_ENABLED', 'false');
            $configuration = require config_path('horus-advisor.php');
            $this->assertFalse($configuration['enabled']);
        } finally {
            $property->setValue(null, $previous);
        }
    }

    public function test_disabled_engine_never_answers_from_custom_or_builtin_php_intents(): void
    {
        Http::preventStrayRequests();
        config(['horus-advisor.enabled' => false]);
        ChatIntent::create(['nombre' => 'personalizada', 'keywords' => ['cursos'], 'respuesta' => 'Respuesta PHP anterior', 'activo' => true, 'prioridad' => 100]);
        foreach (['public:prueba', 'admin:prueba', 'direct'] as $scope) {
            $reply = app(AsistenteVirtualService::class)->procesarMensaje('cursos', 'prueba', $scope);
            $this->assertSame('respaldo', $reply['fuente']);
            $this->assertNull($reply['intent']);
            $this->assertSame(AsistenteVirtualService::RESPUESTA_FALLBACK, $reply['respuesta']);
        }
        $this->assertSame(3, ChatLog::count());
        $this->assertSame('respaldo', ChatLog::firstOrFail()->getAttribute('fuente'));
        Http::assertNothingSent();
    }
}
