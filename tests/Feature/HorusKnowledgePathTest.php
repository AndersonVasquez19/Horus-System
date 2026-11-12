<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ConveniosPublicos;
use App\Services\HorusAdvisorClient;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Laravel no debe depender de la carpeta `apps/ai`: la ruta de `knowledge.json` sale de una clave de
 * configuración, de modo que mover el servicio Python no obliga a tocar el código de producción.
 */
class HorusKnowledgePathTest extends TestCase
{
    /** @var list<string> */
    private array $temporales = [];

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }
        unset($_SERVER['HORUS_AI_KNOWLEDGE_PATH'], $_ENV['HORUS_AI_KNOWLEDGE_PATH']);

        parent::tearDown();
    }

    public function test_por_defecto_la_clave_apunta_al_archivo_actual_de_conocimiento(): void
    {
        $ruta = str_replace('\\', '/', (string) config('horus-knowledge.path'));

        $this->assertStringEndsWith('apps/ai/data/knowledge.json', $ruta);
        $this->assertFileExists((string) config('horus-knowledge.path'));
    }

    public function test_la_variable_compartida_con_python_sobrescribe_la_ruta_y_vacia_conserva_la_actual(): void
    {
        $_SERVER['HORUS_AI_KNOWLEDGE_PATH'] = $_ENV['HORUS_AI_KNOWLEDGE_PATH'] = '/otra/ruta/knowledge.json';
        $this->assertSame('/otra/ruta/knowledge.json', (require base_path('config/horus-knowledge.php'))['path']);

        $_SERVER['HORUS_AI_KNOWLEDGE_PATH'] = $_ENV['HORUS_AI_KNOWLEDGE_PATH'] = '';
        $this->assertSame(
            base_path('apps/ai/data/knowledge.json'),
            (require base_path('config/horus-knowledge.php'))['path']
        );
    }

    public function test_convenios_publicos_lee_la_ruta_configurada(): void
    {
        config(['horus-knowledge.path' => $this->conocimientoTemporal([
            ['id' => 'convenio_prueba', 'status' => 'verified', 'answer' => 'Respuesta', 'category' => 'convenio',
                'title' => 'Institución de prueba', 'description' => 'Descripción de prueba',
                'public_card' => ['vigencia' => '2030-01-01']],
            ['id' => 'servicio_ajeno', 'status' => 'verified', 'answer' => 'Otra', 'category' => 'servicio',
                'title' => 'No es convenio', 'description' => 'No debe aparecer'],
        ])]);

        $this->assertSame(
            [['vigencia' => '2030-01-01', 'nombre' => 'Institución de prueba', 'descripcion' => 'Descripción de prueba']],
            app(ConveniosPublicos::class)->tarjetas()
        );
    }

    public function test_el_cliente_del_asistente_lee_la_ruta_configurada_y_no_mezcla_cachés_entre_rutas(): void
    {
        $cliente = app(HorusAdvisorClient::class);

        config(['horus-knowledge.path' => $this->conocimientoTemporal([
            ['id' => 'servicio_camaras', 'status' => 'verified', 'answer' => 'Respuesta', 'title' => 'Título solo de la prueba'],
        ])]);
        $this->assertSame(
            'y qué incluye de Título solo de la prueba',
            $cliente->resolverSeguimiento('y qué incluye', ['servicio_camaras'])
        );

        // Otra ruta con el mismo identificador y un archivo del mismo tamaño debe responder con su propio contenido,
        // no con el de la anterior: la firma de la caché incluye la ruta.
        config(['horus-knowledge.path' => $this->conocimientoTemporal([
            ['id' => 'servicio_camaras', 'status' => 'verified', 'answer' => 'Respuesta', 'title' => 'Título solo de la ruta 2'],
        ])]);
        $this->assertSame(
            'y qué incluye de Título solo de la ruta 2',
            $cliente->resolverSeguimiento('y qué incluye', ['servicio_camaras'])
        );

        // Un archivo ausente deja el conocimiento vacío en lugar de fallar.
        config(['horus-knowledge.path' => sys_get_temp_dir().'/horus-no-existe-'.bin2hex(random_bytes(4)).'.json']);
        $this->assertSame('y qué incluye', $cliente->resolverSeguimiento('y qué incluye', ['servicio_camaras']));
    }

    public function test_el_codigo_de_la_aplicacion_no_contiene_la_ruta_fija_de_chatbot_python(): void
    {
        $directorio = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), RecursiveDirectoryIterator::SKIP_DOTS));
        $infractores = [];
        foreach ($directorio as $archivo) {
            if ($archivo->isFile() && $archivo->getExtension() === 'php'
                && str_contains((string) file_get_contents($archivo->getPathname()), 'apps/ai')) {
                $infractores[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $archivo->getPathname());
            }
        }

        $this->assertSame([], $infractores, 'La ruta de apps/ai debe salir de config/horus-knowledge.php.');
    }

    /** @param list<array<string, mixed>> $registros */
    private function conocimientoTemporal(array $registros): string
    {
        $ruta = sys_get_temp_dir().'/horus-knowledge-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($ruta, json_encode(['records' => $registros], JSON_THROW_ON_ERROR));
        $this->temporales[] = $ruta;

        return $ruta;
    }
}
