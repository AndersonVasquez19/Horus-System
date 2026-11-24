<?php

declare(strict_types=1);

namespace App\Services\Panel;

use App\Services\RespaldoLocal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Salud operativa del sistema para el administrador: base de datos, scheduler (latido), cola, trabajos fallidos, avisos
 * externos atascados, disco y respaldo local. Solo lectura y sin datos personales: devuelve conteos, edades y estados.
 *
 * Limitación declarada: si el scheduler está caído, lo que no corre es justamente la vigilancia periódica; por eso el
 * estado también se calcula al abrir el panel (endpoint `compartido/salud`) y el latido envejece a la vista.
 */
final class SaludSistema
{
    public const LATIDO = 'horus:scheduler:latido';

    public const OK = 'ok';

    public const INFO = 'info';

    public const ALERTA = 'alerta';

    public const ERROR = 'error';

    private const PESO = [self::OK => 0, self::INFO => 0, self::ALERTA => 1, self::ERROR => 2];

    /** Lo ejecuta el scheduler cada minuto. */
    public static function latir(): void
    {
        Cache::put(self::LATIDO, CarbonImmutable::now()->getTimestamp(), 86400);
    }

    /**
     * @return array{estado: string, revisado_en: string, comprobaciones: list<array{clave: string, etiqueta: string, estado: string, detalle: string}>}
     */
    public function evaluar(): array
    {
        $comprobaciones = [
            $this->baseDatos(),
            $this->scheduler(),
            $this->cola(),
            $this->trabajosFallidos(),
            $this->avisos(),
            $this->disco(),
            $this->respaldo(),
        ];

        $peor = 0;
        foreach ($comprobaciones as $c) {
            $peor = max($peor, self::PESO[$c['estado']]);
        }

        return [
            'estado' => $peor === 2 ? self::ERROR : ($peor === 1 ? self::ALERTA : self::OK),
            'revisado_en' => CarbonImmutable::now()->toIso8601String(),
            'comprobaciones' => $comprobaciones,
        ];
    }

    /** @return array{clave: string, etiqueta: string, estado: string, detalle: string} */
    private function baseDatos(): array
    {
        try {
            $inicio = microtime(true);
            DB::select('select 1');
            $ms = (int) round((microtime(true) - $inicio) * 1000);

            return $this->fila('base_datos', 'Base de datos', $ms > 1000 ? self::ALERTA : self::OK, 'Responde en '.$ms.' ms.');
        } catch (Throwable) {
            return $this->fila('base_datos', 'Base de datos', self::ERROR, 'No responde.');
        }
    }

    /** @return array{clave: string, etiqueta: string, estado: string, detalle: string} */
    private function scheduler(): array
    {
        $ultimo = Cache::get(self::LATIDO);
        if (! is_int($ultimo)) {
            return $this->fila('scheduler', 'Tareas programadas', self::ALERTA, 'Aún no hay latido registrado: verifica que el scheduler esté en marcha.');
        }
        $edad = max(0, CarbonImmutable::now()->getTimestamp() - $ultimo);
        $estado = $edad > 300 ? self::ERROR : ($edad > 120 ? self::ALERTA : self::OK);

        return $this->fila('scheduler', 'Tareas programadas', $estado, 'Último latido hace '.$this->duracion($edad).'.');
    }

    /** @return array{clave: string, etiqueta: string, estado: string, detalle: string} */
    private function cola(): array
    {
        if (config('queue.default') !== 'database' || ! Schema::hasTable('jobs')) {
            return $this->fila('cola', 'Cola de trabajos', self::INFO, 'No se mide con este controlador de cola.');
        }
        $pendientes = (int) DB::table('jobs')->whereNull('reserved_at')->count();
        $masAntiguo = DB::table('jobs')->whereNull('reserved_at')->min('available_at');
        $edad = is_numeric($masAntiguo) ? max(0, CarbonImmutable::now()->getTimestamp() - (int) $masAntiguo) : 0;
        $estado = $edad > 1800 ? self::ERROR : ($edad > 600 ? self::ALERTA : self::OK);

        return $this->fila('cola', 'Cola de trabajos', $estado, $pendientes === 0
            ? 'Sin trabajos pendientes.'
            : $pendientes.' pendiente(s); el más antiguo espera '.$this->duracion($edad).'.');
    }

    /** @return array{clave: string, etiqueta: string, estado: string, detalle: string} */
    private function trabajosFallidos(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return $this->fila('fallidos', 'Trabajos fallidos', self::INFO, 'La tabla de fallidos no existe.');
        }
        $n = (int) DB::table('failed_jobs')->where('failed_at', '>=', CarbonImmutable::now()->subDay()->toDateTimeString())->count();
        $estado = $n === 0 ? self::OK : ($n >= 10 ? self::ERROR : self::ALERTA);

        return $this->fila('fallidos', 'Trabajos fallidos', $estado, $n === 0 ? 'Ninguno en las últimas 24 horas.' : $n.' en las últimas 24 horas.');
    }

    /** @return array{clave: string, etiqueta: string, estado: string, detalle: string} */
    private function avisos(): array
    {
        if (! Schema::hasTable('avisos_externos')) {
            return $this->fila('avisos', 'Avisos externos', self::INFO, 'No disponible.');
        }
        $limite = CarbonImmutable::now()->subMinutes(30);
        $atascados = (int) DB::table('avisos_externos')->where('status', 'pending')->where('available_at', '<', $limite)->count();
        $inciertos = (int) DB::table('avisos_externos')->where('uncertain', true)->count();
        $estado = ($atascados > 0 || $inciertos > 0) ? self::ALERTA : self::OK;

        return $this->fila('avisos', 'Avisos externos', $estado, $estado === self::OK
            ? 'Sin avisos atascados ni inciertos.'
            : "{$atascados} atascado(s) más de 30 min y {$inciertos} con entrega incierta.");
    }

    /** @return array{clave: string, etiqueta: string, estado: string, detalle: string} */
    private function disco(): array
    {
        $libre = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        if ($libre === false || $total === false || $total <= 0) {
            return $this->fila('disco', 'Espacio en disco', self::INFO, 'No se pudo medir.');
        }
        $porcentaje = (int) round($libre / $total * 100);
        $estado = $porcentaje < 5 ? self::ERROR : ($porcentaje < 10 ? self::ALERTA : self::OK);

        return $this->fila('disco', 'Espacio en disco', $estado, $porcentaje.' % libre.');
    }

    /** @return array{clave: string, etiqueta: string, estado: string, detalle: string} */
    private function respaldo(): array
    {
        $programado = config('respaldo.programado') === true;
        try {
            $copias = app(RespaldoLocal::class)->listar();
        } catch (Throwable) {
            return $this->fila('respaldo', 'Respaldo local', self::INFO, 'No se pudo consultar el disco de respaldos.');
        }
        if ($copias === []) {
            return $this->fila('respaldo', 'Respaldo local', $programado ? self::ALERTA : self::INFO,
                $programado ? 'Programado, pero todavía no hay ninguna copia.' : 'No hay copias y el respaldo programado está apagado.');
        }
        $edad = max(0, CarbonImmutable::now()->getTimestamp() - $copias[0]['creado']);
        $estado = $programado && $edad > 36 * 3600 ? self::ALERTA : self::OK;

        return $this->fila('respaldo', 'Respaldo local', $estado, 'Última copia hace '.$this->duracion($edad).'.');
    }

    /** @return array{clave: string, etiqueta: string, estado: string, detalle: string} */
    private function fila(string $clave, string $etiqueta, string $estado, string $detalle): array
    {
        return ['clave' => $clave, 'etiqueta' => $etiqueta, 'estado' => $estado, 'detalle' => $detalle];
    }

    private function duracion(int $segundos): string
    {
        return match (true) {
            $segundos < 90 => $segundos.' s',
            $segundos < 5400 => (int) round($segundos / 60).' min',
            $segundos < 129600 => (int) round($segundos / 3600).' h',
            default => (int) round($segundos / 86400).' días',
        };
    }
}
