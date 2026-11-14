<?php

declare(strict_types=1);

namespace Tests\PostgresIntegrity;

use App\Models\AuditLog;
use App\Models\ServicioTecnologico;
use App\Models\SolicitudCotizacion;
use App\Support\FormularioCotizacionPublica;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresIntegrity\Concerns\RequiresIsolatedPostgres;
use Tests\TestCase;

/**
 * Etapa B (14/11/2026) — concurrencia con DOS PROCESOS REALES del sistema operativo. Las pruebas de dos sesiones
 * ({@see CotizacionPublicaConcurrenciaPostgresTest}) viven en un solo proceso PHP; aquí cada petición corre en su propio
 * proceso `php scripts/pgsql-test.php test --filter=CotizacionPublicaTrabajador…` (mismas salvaguardas: perfil aislado,
 * identidad de clúster y consentimiento heredado del entorno del proceso padre; no se pasa ni se relaja nada).
 *
 * Protocolo: el director crea el servicio y la marca, lanza dos trabajadores con la misma marca y el mismo contenido,
 * espera a que ambos estén listos (aplicación arrancada y conexión abierta), deja caer la barrera (un archivo) y ambos
 * envían a la vez. Debe haber UNA sola solicitud, un 201 y un 200 (repetida) con el mismo código.
 *
 * Límite honesto: el cruce exacto de las dos transacciones depende del planificador del sistema operativo y no se
 * garantiza en cada ejecución; el cruce determinista lo prueba la carrera de dos sesiones. Esta prueba demuestra que, con
 * procesos de verdad, el resultado es siempre uno solo y sin errores 500.
 */
class CotizacionPublicaProcesosPostgresTest extends TestCase
{
    use DatabaseTruncation;
    use RequiresIsolatedPostgres;

    private string $directorio = '';

    protected function tearDown(): void
    {
        if ($this->directorio !== '' && is_dir($this->directorio)) {
            foreach (glob($this->directorio.DIRECTORY_SEPARATOR.'*') ?: [] as $archivo) {
                @unlink($archivo);
            }
            @rmdir($this->directorio);
        }
        parent::tearDown();
    }

    public function test_dos_procesos_reales_con_la_misma_marca_dejan_una_sola_solicitud(): void
    {
        config(['catalogo-servicios.enabled' => true, 'recepcion-cotizaciones.enabled' => true]);
        $this->adminUser(); // DatabaseTruncation vacía los roles; el catálogo público consulta el rol admin.
        $servicio = ServicioTecnologico::forceCreate([
            'identificador_publico' => (string) Str::uuid(), 'codigo' => 'CABLEADO', 'nombre' => 'Servicio administrativo de cableado',
            'categoria' => 'redes', 'estado' => 'activo', 'revision' => 1,
        ]);
        $this->travelTo(now()->subSeconds(30));
        $marca = FormularioCotizacionPublica::emitir();
        $this->travelBack();

        $this->directorio = sys_get_temp_dir().DIRECTORY_SEPARATOR.'horus-cot-'.Str::random(12);
        $this->assertTrue(mkdir($this->directorio, 0700));
        $carga = $this->directorio.DIRECTORY_SEPARATOR.'carga.json';
        $barrera = $this->directorio.DIRECTORY_SEPARATOR.'barrera';
        file_put_contents($carga, json_encode([
            'servicios' => [(string) $servicio->identificador_publico], 'nombre' => 'Cliente Ficticio', 'empresa' => 'Empresa Sintética SAC',
            'email' => 'cliente.ficticio@example.test', 'telefono' => '+51 900 000 000', 'ubicacion' => 'Cajamarca, sede norte',
            'necesidad' => 'Necesitamos cablear dos pisos de oficinas con 30 puntos de red.', 'informacion' => 'Visita posible los sábados.',
            'consentimiento' => true, 'marca' => $marca,
        ], JSON_THROW_ON_ERROR));

        $procesos = [];
        foreach ([1, 2] as $n) {
            $procesos[$n] = $this->lanzar($n, $carga, $barrera);
        }

        try {
            $limite = microtime(true) + 120;
            while (! is_file($this->directorio.'/listo-1') || ! is_file($this->directorio.'/listo-2')) {
                if (microtime(true) > $limite) {
                    $this->fail('Los trabajadores no estuvieron listos a tiempo: '.$this->diagnostico($procesos));
                }
                foreach ($procesos as $p) {
                    $estado = proc_get_status($p['recurso']);
                    if (! $estado['running'] && ! is_file($this->directorio.'/listo-'.$p['n'])) {
                        $this->fail('Un trabajador terminó antes de estar listo: '.$this->diagnostico($procesos));
                    }
                }
                usleep(20000);
            }
            file_put_contents($barrera, '1');

            $limite = microtime(true) + 120;
            foreach ($procesos as $p) {
                while (proc_get_status($p['recurso'])['running']) {
                    if (microtime(true) > $limite) {
                        $this->fail('Un trabajador no terminó a tiempo: '.$this->diagnostico($procesos));
                    }
                    usleep(20000);
                }
            }
        } finally {
            foreach ($procesos as $p) {
                $estado = proc_get_status($p['recurso']);
                if ($estado['running']) {
                    proc_terminate($p['recurso']);
                }
            }
        }

        $resultados = [];
        foreach ($procesos as $p) {
            $archivo = $this->directorio.'/salida-'.$p['n'].'.json';
            $this->assertFileExists($archivo, 'El trabajador '.$p['n'].' no escribió resultado: '.$this->diagnostico($procesos));
            $resultados[] = json_decode((string) file_get_contents($archivo), true, 8, JSON_THROW_ON_ERROR);
        }
        foreach ($procesos as $p) {
            proc_close($p['recurso']);
        }

        $this->assertNotSame($resultados[0]['pid'], $resultados[1]['pid'], 'Son dos procesos distintos del sistema operativo.');
        $estados = array_column($resultados, 'status');
        sort($estados);
        $this->assertSame([200, 201], $estados, 'Una creada (201) y una repetida (200): '.json_encode($resultados));
        $this->assertSame($resultados[0]['codigo'], $resultados[1]['codigo'], 'Ambas devuelven la misma solicitud.');
        $this->assertSame(1, SolicitudCotizacion::count(), 'Una sola solicitud en la base.');
        $this->assertSame(1, DB::table('cotizacion_historial')->count());
        $this->assertSame(1, AuditLog::where('action', 'cotizacion_creada')->count());
    }

    /** @return array{n: int, recurso: resource, tuberias: array<int, resource>} */
    private function lanzar(int $n, string $carga, string $barrera): array
    {
        $base = dirname(__DIR__, 2);
        $comando = [PHP_BINARY, $base.'/scripts/pgsql-test.php', 'test', '--filter=CotizacionPublicaTrabajadorPostgresTest'];
        // Hereda el entorno del proceso padre (perfil y consentimiento por comando); solo se añaden las rutas de trabajo.
        $entorno = array_merge(getenv(), [
            'HORUS_COT_TRABAJADOR_CARGA' => $carga, 'HORUS_COT_TRABAJADOR_BARRERA' => $barrera,
            'HORUS_COT_TRABAJADOR_LISTO' => $this->directorio.'/listo-'.$n, 'HORUS_COT_TRABAJADOR_SALIDA' => $this->directorio.'/salida-'.$n.'.json',
        ]);
        $descriptores = [0 => ['pipe', 'r'], 1 => ['file', $this->directorio.'/stdout-'.$n.'.txt', 'w'], 2 => ['file', $this->directorio.'/stderr-'.$n.'.txt', 'w']];
        $recurso = proc_open($comando, $descriptores, $tuberias, $base, $entorno);
        $this->assertIsResource($recurso, 'No se pudo lanzar el trabajador '.$n);
        fclose($tuberias[0]);

        return ['n' => $n, 'recurso' => $recurso, 'tuberias' => $tuberias];
    }

    /** Solo salida de PHPUnit del trabajador (sin credenciales: el guardia nunca las imprime). */
    private function diagnostico(array $procesos): string
    {
        $texto = '';
        foreach ($procesos as $p) {
            foreach (['stdout', 'stderr'] as $flujo) {
                $archivo = $this->directorio.'/'.$flujo.'-'.$p['n'].'.txt';
                $texto .= "[{$flujo}-{$p['n']}] ".substr((string) @file_get_contents($archivo), -600).' ';
            }
        }

        return $texto;
    }
}
