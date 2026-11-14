<?php

declare(strict_types=1);

namespace Tests\PostgresIntegrity;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\PostgresIntegrity\Concerns\RequiresIsolatedPostgres;
use Tests\TestCase;

/**
 * Trabajador de {@see CotizacionPublicaProcesosPostgresTest}: NO es una prueba independiente. Sin las variables
 * `HORUS_COT_TRABAJADOR_*` se omite siempre (también en la suite de Integridad). Lanzado por el director como proceso
 * propio del sistema operativo (por `scripts/pgsql-test.php test`, con las mismas salvaguardas del perfil aislado), espera
 * la barrera, envía UNA solicitud HTTP con la marca recibida y escribe el resultado en un archivo.
 *
 * No usa DatabaseTruncation ni migra: no modifica el esquema ni vacía tablas; solo la petición pública.
 */
class CotizacionPublicaTrabajadorPostgresTest extends TestCase
{
    use RequiresIsolatedPostgres;

    private const TOKEN = 'token-de-prueba-bff-0123456789abcdefghij';

    private const VISITANTE = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    public function test_envia_una_solicitud_cuando_cae_la_barrera(): void
    {
        $carga = getenv('HORUS_COT_TRABAJADOR_CARGA');
        $barrera = getenv('HORUS_COT_TRABAJADOR_BARRERA');
        $listo = getenv('HORUS_COT_TRABAJADOR_LISTO');
        $salida = getenv('HORUS_COT_TRABAJADOR_SALIDA');
        if (! is_string($carga) || ! is_string($barrera) || ! is_string($listo) || ! is_string($salida)
            || $carga === '' || $barrera === '' || $listo === '' || $salida === '') {
            $this->markTestSkipped('Trabajador de la prueba con procesos reales: solo corre lanzado por su director.');
        }

        Http::preventStrayRequests();
        Mail::fake();
        config(['horus-web.service_token' => self::TOKEN, 'catalogo-servicios.enabled' => true, 'recepcion-cotizaciones.enabled' => true]);
        $cuerpo = json_decode((string) file_get_contents($carga), true, 16, JSON_THROW_ON_ERROR);

        // Aplicación ya arrancada y conexión abierta antes de esperar: lo único que queda tras la barrera es la petición.
        $this->getJson('/api/public/v1/cotizaciones/formulario', ['X-Horus-Web-Token' => self::TOKEN, 'X-Horus-Visitante' => self::VISITANTE]);
        file_put_contents($listo, (string) getmypid());

        $limite = microtime(true) + 90;
        while (! is_file($barrera)) {
            if (microtime(true) > $limite) {
                $this->fail('La barrera no cayó a tiempo.');
            }
            usleep(2000);
        }

        $respuesta = $this->postJson('/api/public/v1/cotizaciones', $cuerpo, ['X-Horus-Web-Token' => self::TOKEN, 'X-Horus-Visitante' => self::VISITANTE]);
        file_put_contents($salida, json_encode([
            'pid' => getmypid(), 'status' => $respuesta->getStatusCode(), 'codigo' => $respuesta->json('codigo'), 'repetida' => $respuesta->json('repetida'),
        ], JSON_THROW_ON_ERROR));

        $this->assertContains($respuesta->getStatusCode(), [200, 201]);
    }
}
