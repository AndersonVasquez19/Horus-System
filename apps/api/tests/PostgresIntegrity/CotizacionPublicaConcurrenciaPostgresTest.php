<?php

declare(strict_types=1);

namespace Tests\PostgresIntegrity;

use App\Models\AuditLog;
use App\Models\ServicioTecnologico;
use App\Models\SolicitudCotizacion;
use App\Support\FormularioCotizacionPublica;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\PostgresIntegrity\Concerns\RequiresIsolatedPostgres;
use Tests\TestCase;

/**
 * Etapa B (14/11/2026) — recepción pública de cotizaciones (`POST /api/public/v1/cotizaciones`) sobre PostgreSQL real.
 *
 * La «Idempotency-Key» de este endpoint NO es una cabecera: es el identificador de la marca cifrada del formulario
 * (`FormularioCotizacionPublica`), de la que sale `creacion_clave_hash` con la restricción única
 * `cotizaciones_creacion_unica (creador_ambito, creacion_clave_hash)`. Aquí se mide, por HTTP real y con dos sesiones
 * PostgreSQL (mismo patrón que `PanelConcurrenciaPostgresTest`), lo que SQLite no puede medir: que la perdedora de una
 * carrera por la misma marca termina en 23505, hace rollback completo y se reconcilia con la ganadora (200, repetida).
 *
 * Datos ficticios; solo la base desechable del perfil aislado (127.0.0.1:55433, horus_l2_test). DatabaseTruncation:
 * con RefreshDatabase la segunda sesión no vería los datos.
 */
class CotizacionPublicaConcurrenciaPostgresTest extends TestCase
{
    use DatabaseTruncation;
    use RequiresIsolatedPostgres;

    private const TOKEN = 'token-de-prueba-bff-0123456789abcdefghij';

    private const VISITANTE = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private ServicioTecnologico $servicio;

    /** Servicio con página propia (CABLEADO) activo, recepción y catálogo habilitados solo para esta prueba. */
    private function preparar(): void
    {
        Http::preventStrayRequests();
        Mail::fake();
        config(['horus-web.service_token' => self::TOKEN, 'catalogo-servicios.enabled' => true, 'recepcion-cotizaciones.enabled' => true]);
        $this->adminUser(); // DatabaseTruncation vacía los roles; el catálogo público consulta el rol admin.
        $this->servicio = ServicioTecnologico::forceCreate([
            'identificador_publico' => (string) Str::uuid(), 'codigo' => 'CABLEADO', 'nombre' => 'Servicio administrativo de cableado',
            'categoria' => 'redes', 'estado' => 'activo', 'revision' => 1,
        ]);
    }

    /** Marca ya madura (emitida hace 10 s: supera el mínimo de 3 s) y su identificador de intención. */
    private function marcaMadura(): string
    {
        $this->travelTo(now()->subSeconds(10));
        $marca = FormularioCotizacionPublica::emitir();
        $this->travelBack();

        return $marca;
    }

    private function identificador(string $marca): string
    {
        $datos = json_decode(Crypt::decryptString($marca), true, 8, JSON_THROW_ON_ERROR);

        return (string) $datos['identificador'];
    }

    /** @return array<string, mixed> */
    private function cuerpo(string $marca, string $necesidad = 'Necesitamos cablear dos pisos de oficinas con 30 puntos de red.'): array
    {
        return [
            'servicios' => [(string) $this->servicio->identificador_publico], 'nombre' => 'Cliente Ficticio', 'empresa' => 'Empresa Sintética SAC',
            'email' => 'cliente.ficticio@example.test', 'telefono' => '+51 900 000 000', 'ubicacion' => 'Cajamarca, sede norte',
            'necesidad' => $necesidad, 'informacion' => 'Visita posible los sábados.', 'consentimiento' => true, 'marca' => $marca,
        ];
    }

    private function enviar(array $cuerpo): TestResponse
    {
        return $this->postJson('/api/public/v1/cotizaciones', $cuerpo, ['X-Horus-Web-Token' => self::TOKEN, 'X-Horus-Visitante' => self::VISITANTE]);
    }

    /** Vacía lo recibido sin tocar el servicio; devuelve la fila borrada (sin id) para reutilizarla como «ganadora». */
    private function retirarSolicitudBase(): array
    {
        $fila = (array) DB::table('solicitudes_cotizacion')->first();
        $this->assertNotSame([], $fila);
        DB::table('cotizacion_historial')->delete();
        DB::table('solicitudes_cotizacion')->delete();
        DB::table('audit_logs')->where('action', 'cotizacion_creada')->delete();
        unset($fila['id']);

        return $fila;
    }

    /**
     * Justo después de que la petición mire «¿existe esta clave?» y «¿hay una abierta con la misma huella?» sin hallar
     * nada, y ANTES de que bloquee el servicio, otra sesión confirma la solicitud ganadora con la misma clave: el INSERT
     * de la principal termina en 23505. (Más tarde no sería posible: la principal ya sostiene FOR UPDATE sobre el servicio
     * y el INSERT de B, por su FK, esperaría ese bloqueo; en la realidad la perdedora espera en ese mismo bloqueo.)
     * Devuelve una función que informa si la carrera se provocó.
     *
     * @param  array<string, mixed>  $fila
     */
    private function provocarCarrera(array $fila): \Closure
    {
        $b = $this->secondSession();
        $principal = DB::connection();
        $hecho = false;
        DB::listen(function (QueryExecuted $q) use ($b, $principal, $fila, &$hecho): void {
            if (! $hecho && $q->connection === $principal && str_contains($q->sql, 'from "solicitudes_cotizacion"') && str_contains($q->sql, '"creacion_huella"')) {
                $hecho = true;
                $b->table('solicitudes_cotizacion')->insert($fila);
            }
        });

        return function () use (&$hecho): bool {
            return $hecho;
        };
    }

    // 1. Repetición secuencial ------------------------------------------------------------------------------------------

    public function test_la_misma_marca_enviada_dos_veces_crea_una_sola_solicitud(): void
    {
        $this->preparar();
        $marca = $this->marcaMadura();

        $primera = $this->enviar($this->cuerpo($marca))->assertCreated()->assertJsonPath('repetida', false);
        $segunda = $this->enviar($this->cuerpo($marca))->assertOk()->assertJsonPath('repetida', true);

        $this->assertSame($primera->json('codigo'), $segunda->json('codigo'));
        $this->assertSame(1, SolicitudCotizacion::count());
        $this->assertSame(1, DB::table('cotizacion_historial')->count());
        $this->assertSame(1, AuditLog::where('action', 'cotizacion_creada')->count());
    }

    // 2. Carrera por la misma marca ----------------------------------------------------------------------------------------

    public function test_carrera_por_la_misma_marca_la_perdedora_se_reconcilia_con_200_y_no_deja_rastro(): void
    {
        $this->preparar();
        // Una solicitud previa (otra marca) fija la huella del contenido; se retira para que no la encuentre «abierta».
        $this->enviar($this->cuerpo($this->marcaMadura()))->assertCreated();
        $fila = $this->retirarSolicitudBase();

        $marca = $this->marcaMadura();
        $codigoGanadora = 'SOL-GANADORA-'.Str::upper(Str::random(6));
        $fila['identificador'] = (string) Str::uuid();
        $fila['codigo'] = $codigoGanadora;
        $fila['creacion_clave_hash'] = hash('sha256', 'publico:'.$this->identificador($marca));
        $carrera = $this->provocarCarrera($fila);

        $respuesta = $this->enviar($this->cuerpo($marca));

        $this->assertTrue($carrera(), 'La carrera se provocó.');
        $respuesta->assertOk()->assertJsonPath('codigo', $codigoGanadora)->assertJsonPath('repetida', true);
        $this->assertSame(1, SolicitudCotizacion::count(), 'La perdedora no crea un segundo expediente.');
        $this->assertSame(0, DB::table('cotizacion_historial')->count(), 'La transacción perdedora hizo rollback del historial.');
        $this->assertSame(0, AuditLog::where('action', 'cotizacion_creada')->count(), 'La repetición no audita una creación.');
    }

    public function test_carrera_con_otro_contenido_en_la_misma_marca_da_422_sin_crear_nada(): void
    {
        $this->preparar();
        $this->enviar($this->cuerpo($this->marcaMadura()))->assertCreated();
        $fila = $this->retirarSolicitudBase();

        $marca = $this->marcaMadura();
        // La ganadora tiene otra huella (otro contenido con la misma marca): no es una repetición legítima.
        $fila['identificador'] = (string) Str::uuid();
        $fila['codigo'] = 'SOL-OTRA-'.Str::upper(Str::random(6));
        $fila['creacion_clave_hash'] = hash('sha256', 'publico:'.$this->identificador($marca));
        $fila['creacion_huella'] = str_repeat('0', 64);
        $carrera = $this->provocarCarrera($fila);

        $this->enviar($this->cuerpo($marca))->assertStatus(422)->assertJsonValidationErrors('marca');

        $this->assertTrue($carrera(), 'La carrera se provocó.');
        $this->assertSame(1, SolicitudCotizacion::count(), 'Solo existe la ganadora de la otra sesión.');
        $this->assertSame(0, DB::table('cotizacion_historial')->count());
        $this->assertSame(0, AuditLog::where('action', 'cotizacion_creada')->count());
    }
}
