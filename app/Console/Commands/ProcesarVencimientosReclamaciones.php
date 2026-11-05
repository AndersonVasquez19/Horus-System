<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Reclamacion;
use App\Services\GestionReclamaciones;
use App\Support\DiasHabiles;
use App\Support\Reclamaciones;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Vencimientos del Libro de Reclamaciones (Sprint 22).
 *
 * Etapa 1 (Fase 1): cierra las ventanas de propuesta de solución vencidas con
 * {@see GestionReclamaciones::resolverVencimiento()} (transaccional, con bloqueo, relectura e idempotente).
 * Sin margen de gracia: vence cuando hoy (America/Lima) es posterior a `suspension_hasta`.
 *
 * Etapa 2 (Fase 2): avisos internos del plazo legal con {@see GestionReclamaciones::avisarPlazo()}, una sola vez
 * por umbral y por plazo. Corre después de la etapa 1 para que un plazo reanudado use su nueva `fecha_limite`.
 *
 * No envía correos. Con el módulo desactivado termina sin consultar ni escribir. Un fallo en un caso se registra
 * (solo identificador y clase de excepción) y no impide procesar los demás.
 */
class ProcesarVencimientosReclamaciones extends Command
{
    protected $signature = 'horus:reclamaciones-vencimientos
        {--dry-run : Cuenta lo pendiente sin modificar nada}
        {--limit=200 : Máximo de trabajo efectivo por etapa y ejecución: propuestas resueltas o avisos enviados (1 a 1000)}';

    protected $description = 'Reanuda el plazo de las propuestas vencidas y avisa los plazos legales por vencer';

    public function handle(GestionReclamaciones $gestion): int
    {
        if (! Reclamaciones::habilitado()) {
            $this->info('Libro de Reclamaciones desactivado: no hay nada que procesar.');

            return self::SUCCESS;
        }
        $limite = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limite === false) {
            $this->error('El límite debe estar entre 1 y 1000.');

            return self::FAILURE;
        }

        $hoy = CarbonImmutable::now((string) config('reclamaciones.zona_horaria', 'America/Lima'))->startOfDay();
        $propuestas = $this->propuestas($gestion, $hoy->toDateString(), $limite);
        $avisos = $this->avisos($gestion, $hoy, $limite);

        if ($this->option('dry-run')) {
            $this->line('Simulación (dry-run): no se realizó ninguna escritura.');
            $this->line('Los conteos reflejan el estado actual de los datos.');
            $this->line('No se simula la reanudación de las propuestas vencidas: al resolverlas cambian las fechas límite y, con ellas, los avisos.');
        }
        if ($propuestas['truncado'] || $avisos['truncado']) {
            $this->line($this->option('dry-run')
                ? "Límite alcanzado (--limit={$limite}): puede haber más casos de los mostrados."
                : "Límite alcanzado (--limit={$limite}): quedan casos para la siguiente ejecución.");
        }

        return $propuestas['errores'] + $avisos['errores'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Garantía de progreso (sin estado nuevo): `--limit` cuenta solo el trabajo efectivo (propuestas resueltas o
     * avisos enviados). Los casos que fallan se intentan una vez por ejecución, sin consumir el tope, y el
     * recorrido continúa con los siguientes; siguen siendo candidatas y se reintentan en la próxima ejecución.
     *
     * @return array{errores: int, truncado: bool}
     */
    private function propuestas(GestionReclamaciones $gestion, string $hoy, int $limite): array
    {
        $dry = (bool) $this->option('dry-run');
        $candidatas = Reclamacion::query()
            ->where('estado', 'abierto')
            ->whereNotNull('suspension_desde')
            ->whereDate('suspension_hasta', '<', $hoy)
            ->lazyById(100);

        $trabajo = $intentadas = $omitidas = $errores = 0;
        $truncado = false;
        foreach ($candidatas as $reclamacion) {
            if ($trabajo >= $limite) {
                $truncado = true;
                break;
            }
            if ($dry) {
                $trabajo++;

                continue;
            }
            $intentadas++;
            try {
                $gestion->resolverVencimiento($reclamacion->identificador) ? $trabajo++ : $omitidas++;
            } catch (Throwable $e) {
                $errores++;
                // Solo el identificador y la clase: el mensaje de la excepción puede contener datos personales.
                Log::error('reclamaciones.vencimiento_fallido', ['identificador' => $reclamacion->identificador, 'excepcion' => $e::class]);
            }
        }

        if ($dry) {
            $this->info("Propuestas vencidas: {$trabajo} (dry-run, sin cambios).");

            return ['errores' => 0, 'truncado' => $truncado];
        }
        Log::info('reclamaciones.vencimientos_procesados', ['candidatas' => $intentadas, 'resueltas' => $trabajo, 'omitidas' => $omitidas, 'errores' => $errores]);
        $this->info("Candidatas: {$intentadas}. Resueltas: {$trabajo}. Omitidas: {$omitidas}. {$errores} con error.");

        return ['errores' => $errores, 'truncado' => $truncado];
    }

    /**
     * Misma garantía de progreso que {@see self::propuestas()}: errores, casos sin destinatarios y omitidos no
     * consumen el tope (no escriben y siguen pendientes). La evaluación previa también está aislada por caso.
     *
     * @return array{errores: int, truncado: bool}
     */
    private function avisos(GestionReclamaciones $gestion, CarbonImmutable $hoy, int $limite): array
    {
        $dry = (bool) $this->option('dry-run');
        // Cota superior segura: lo que está a más de N días hábiles de hoy no tiene aviso que dar.
        $techo = DiasHabiles::sumar($hoy, (int) config('reclamaciones.aviso_dias_habiles', 3))->toDateString();
        $candidatas = Reclamacion::query()
            ->where('estado', 'abierto')
            ->whereNull('suspension_desde')
            ->whereDate('fecha_limite', '<=', $techo)
            ->lazyById(100);

        $trabajo = $enviados = $porVencer = $vence = $sinDestinatarios = $omitidos = $errores = 0;
        $truncado = false;
        foreach ($candidatas as $reclamacion) {
            try {
                // Lectura sin bloqueo: descarta lo ya avisado. Lo decisivo se relee bajo bloqueo en avisarPlazo().
                if ($gestion->avisoPendiente($reclamacion) === null) {
                    continue;
                }
                if ($trabajo >= $limite) {
                    $truncado = true;
                    break;
                }
                if ($dry) {
                    $trabajo++;

                    continue;
                }
                $resultado = $gestion->avisarPlazo($reclamacion->identificador);
                match ($resultado) {
                    GestionReclamaciones::AVISO_POR_VENCER => [$trabajo++, $enviados++, $porVencer++],
                    GestionReclamaciones::AVISO_VENCE => [$trabajo++, $enviados++, $vence++],
                    GestionReclamaciones::SIN_DESTINATARIOS => $sinDestinatarios++,
                    default => $omitidos++,
                };
            } catch (Throwable $e) {
                $errores++;
                Log::error('reclamaciones.aviso_fallido', ['identificador' => $reclamacion->identificador, 'excepcion' => $e::class]);
            }
        }

        if ($dry) {
            $this->info("Avisos de plazo pendientes: {$trabajo} (dry-run, sin cambios).");

            return ['errores' => 0, 'truncado' => $truncado];
        }
        Log::info('reclamaciones.avisos_plazo_procesados', ['enviados' => $enviados, 'por_vencer' => $porVencer, 'vence' => $vence, 'sin_destinatarios' => $sinDestinatarios, 'omitidos' => $omitidos, 'errores' => $errores]);
        $this->info("Avisos de plazo: {$enviados} enviados ({$porVencer} por vencer, {$vence} vencimiento). Sin destinatarios: {$sinDestinatarios}. Omitidos: {$omitidos}. {$errores} con error.");

        return ['errores' => $errores, 'truncado' => $truncado];
    }
}
