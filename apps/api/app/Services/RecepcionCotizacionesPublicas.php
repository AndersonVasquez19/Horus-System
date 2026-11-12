<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CotizacionHistorial;
use App\Models\ServicioTecnologico;
use App\Models\SolicitudCotizacion;
use App\Support\Email;
use App\Support\FormularioCotizacionPublica;
use App\Support\PaginasServicios;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Recepción pública de solicitudes de cotización (Fase 2c.3). Entra por el módulo comercial EXISTENTE
 * (`solicitudes_cotizacion` con `origen = publico`, su historial y su bandeja `/admin/cotizaciones`): no hay un CRM
 * paralelo. Una solicitud es la NECESIDAD de un cliente, no una cotización emitida: no tiene precio, no genera
 * documento ni compromiso económico, y arranca en `recibida` sin responsable. Los servicios se resuelven siempre en el
 * servidor contra los servicios activos con presencia pública; el navegador solo aporta referencias.
 *
 * La tabla guarda un único servicio por solicitud: el primero elegido es el servicio asociado y todos los elegidos,
 * junto con empresa, ubicación e información adicional, se conservan como texto en `necesidad_cliente`.
 * La identidad del alta anónima usa el ámbito técnico 0 (los operadores usan su id) y como clave de idempotencia el
 * identificador de la marca del formulario.
 */
class RecepcionCotizacionesPublicas
{
    public const AMBITO_PUBLICO = 0;

    public const MAX_SERVICIOS = 5;

    public function __construct(private readonly CatalogoPublicoServicios $catalogo) {}

    /** Apagada por defecto: la activación real exige decisiones de privacidad, retención y antispam aprobadas por escrito. */
    public function habilitado(): bool
    {
        return config('recepcion-cotizaciones.enabled') === true
            && Schema::hasTable('solicitudes_cotizacion') && Schema::hasColumn('solicitudes_cotizacion', 'origen')
            && Schema::hasTable('cotizacion_historial') && Schema::hasTable('servicios_tecnologicos');
    }

    /**
     * Servicios que pueden solicitarse: las tres líneas con página propia (si el servicio está activo) y las fichas
     * publicadas y vigentes del catálogo. El título es siempre el público, nunca el nombre administrativo.
     *
     * @return list<array{valor: string, titulo: string, resumen: string|null}>
     */
    public function servicios(): array
    {
        $salida = [];
        $etiquetas = array_map(fn (array $pagina): string => $pagina['etiqueta'], PaginasServicios::todas());
        $propios = ServicioTecnologico::query()->whereIn('codigo', array_keys($etiquetas))->where('estado', 'activo')->orderBy('id')->get(['identificador_publico', 'codigo']);
        foreach ($propios as $servicio) {
            $salida[] = ['valor' => (string) $servicio->identificador_publico, 'titulo' => $etiquetas[$servicio->codigo], 'resumen' => null];
        }
        foreach ($this->catalogo->pagina(1, 50)['servicios'] as $ficha) {
            $salida[] = ['valor' => (string) $ficha['identificador_publico'], 'titulo' => (string) $ficha['titulo'], 'resumen' => $ficha['resumen'] ?? null];
        }

        return $salida;
    }

    /**
     * @param  array<string, mixed>  $datos  ya validados: servicios, nombre, empresa, email, telefono, ubicacion, necesidad, informacion, marca
     * @return array{solicitud: SolicitudCotizacion, repetida: bool}
     */
    public function registrar(array $datos): array
    {
        $marca = FormularioCotizacionPublica::leer($datos['marca'] ?? null);
        if ($marca === null) {
            throw ValidationException::withMessages(['marca' => 'El formulario caducó o se envió demasiado rápido. Revisa los datos e inténtalo de nuevo.']);
        }
        $elegidos = array_values(array_unique(array_map('strtolower', (array) $datos['servicios'])));
        $disponibles = collect($this->servicios())->keyBy(fn (array $s): string => strtolower($s['valor']));
        $titulos = [];
        foreach ($elegidos as $uuid) {
            if (! $disponibles->has($uuid)) {
                throw ValidationException::withMessages(['servicios' => 'Alguno de los servicios elegidos ya no está disponible. Revisa la lista e inténtalo de nuevo.']);
            }
            $titulos[] = $disponibles[$uuid]['titulo'];
        }
        $contenido = [
            'servicios' => $elegidos, 'nombre' => (string) $datos['nombre'], 'empresa' => (string) ($datos['empresa'] ?? ''),
            'email' => Email::normalize($datos['email']), 'telefono' => (string) ($datos['telefono'] ?? ''), 'ubicacion' => (string) $datos['ubicacion'],
            'necesidad' => (string) $datos['necesidad'], 'informacion' => (string) ($datos['informacion'] ?? ''),
        ];
        $huella = $this->huella($contenido);
        $clave = hash('sha256', 'publico:'.$marca['identificador']);

        try {
            return DB::transaction(fn (): array => $this->crear($contenido, $titulos, $huella, $clave));
        } catch (UniqueConstraintViolationException) {
            // Carrera por la misma marca: tras el rollback la solicitud ganadora ya existe.
            $previa = $this->previa($clave);
            if ($previa === null) {
                throw ValidationException::withMessages(['marca' => 'No pudimos registrar la solicitud. Abre un formulario nuevo e inténtalo de nuevo.']);
            }

            return ['solicitud' => $this->coincide($previa, $huella), 'repetida' => true];
        }
    }

    /**
     * @param  array<string, mixed>  $c
     * @param  list<string>  $titulos
     * @return array{solicitud: SolicitudCotizacion, repetida: bool}
     */
    private function crear(array $c, array $titulos, string $huella, string $clave): array
    {
        $previa = $this->previa($clave, true);
        if ($previa !== null) {
            return ['solicitud' => $this->coincide($previa, $huella), 'repetida' => true];
        }
        // Mismo contenido ya recibido y todavía abierto (otro formulario de la misma persona): no se duplica.
        $abierta = SolicitudCotizacion::query()->where('origen', 'publico')->where('creacion_huella', $huella)
            ->whereIn('estado', ['recibida', 'en_revision'])->orderBy('id')->first();
        if ($abierta !== null) {
            return ['solicitud' => $abierta, 'repetida' => true];
        }
        $principal = ServicioTecnologico::query()->where('identificador_publico', $c['servicios'][0])->lockForUpdate()->first();
        if ($principal === null) {
            throw ValidationException::withMessages(['servicios' => 'Alguno de los servicios elegidos ya no está disponible.']);
        }
        $solicitud = new SolicitudCotizacion([
            'contacto_nombre' => $c['nombre'], 'contacto_email' => $c['email'], 'contacto_telefono' => $c['telefono'] === '' ? null : $c['telefono'],
            'necesidad_cliente' => $this->necesidad($c, $titulos),
        ]);
        $solicitud->identificador = (string) Str::uuid();
        $solicitud->codigo = 'SOL-'.strtoupper($solicitud->identificador);
        $solicitud->origen = 'publico';
        $solicitud->estado = 'recibida';
        $solicitud->recibida_at = now();
        $solicitud->servicio_id = $principal->id;
        $solicitud->servicio_uuid = $principal->identificador_publico;
        $solicitud->servicio_nombre_historico = $principal->nombre;
        $solicitud->creado_por = null;
        $solicitud->creador_ambito = self::AMBITO_PUBLICO;
        $solicitud->creacion_clave_hash = $clave;
        $solicitud->creacion_huella = $huella;
        $solicitud->revision = 1;
        $solicitud->save();

        CotizacionHistorial::query()->create([
            'solicitud_id' => $solicitud->id, 'revision' => 1, 'evento' => 'creada', 'actor_id' => null, 'actor_ambito' => self::AMBITO_PUBLICO,
            'metadatos' => ['estado' => 'recibida', 'origen' => 'publico'], 'nota' => null, 'clave_hash' => null, 'huella' => null, 'created_at' => now(),
        ]);
        // Sin actor ni IP (en el BFF sería la del servidor de Next) y sin datos personales.
        AuditLog::create([
            'user_id' => null, 'action' => 'cotizacion_creada', 'model_type' => SolicitudCotizacion::class, 'model_id' => $solicitud->id,
            'changes' => ['revision' => 1, 'estado' => 'recibida', 'origen' => 'publico', 'servicios' => count($titulos)], 'ip_address' => null, 'created_at' => now(),
        ]);

        return ['solicitud' => $solicitud, 'repetida' => false];
    }

    /**
     * Texto de la necesidad tal como lo verá el personal; las etiquetas son fijas y no se interpretan.
     *
     * @param  array<string, mixed>  $c
     * @param  list<string>  $titulos
     */
    private function necesidad(array $c, array $titulos): string
    {
        $lineas = ['Servicios de interés: '.implode('; ', $titulos)];
        if ($c['empresa'] !== '') {
            $lineas[] = 'Empresa: '.$c['empresa'];
        }
        $lineas[] = 'Ubicación del proyecto: '.$c['ubicacion'];
        $lineas[] = '';
        $lineas[] = 'Necesidad:';
        $lineas[] = $c['necesidad'];
        if ($c['informacion'] !== '') {
            $lineas[] = '';
            $lineas[] = 'Información adicional:';
            $lineas[] = $c['informacion'];
        }

        return implode("\n", $lineas);
    }

    private function previa(string $clave, bool $bloquear = false): ?SolicitudCotizacion
    {
        $consulta = SolicitudCotizacion::query()->where('creador_ambito', self::AMBITO_PUBLICO)->where('creacion_clave_hash', $clave);

        return ($bloquear ? $consulta->lockForUpdate() : $consulta)->first();
    }

    private function coincide(SolicitudCotizacion $previa, string $huella): SolicitudCotizacion
    {
        if (! hash_equals((string) $previa->creacion_huella, $huella)) {
            throw ValidationException::withMessages(['marca' => 'Este formulario ya fue recibido con otros datos. Abre uno nuevo para otra solicitud.']);
        }

        return $previa;
    }

    /** @param array<string, mixed> $contenido */
    private function huella(array $contenido): string
    {
        $secreto = config('app.key');
        abort_unless(is_string($secreto) && $secreto !== '', 503, 'Configuración de integridad no disponible.');
        sort($contenido['servicios']);
        foreach (['nombre', 'empresa', 'ubicacion', 'necesidad', 'informacion'] as $campo) {
            $contenido[$campo] = mb_strtolower($contenido[$campo]);
        }
        ksort($contenido);

        return hash_hmac('sha256', json_encode($contenido, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $secreto);
    }
}
