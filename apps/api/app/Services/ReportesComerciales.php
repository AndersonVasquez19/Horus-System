<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Oportunidad;
use App\Models\Prospecto;
use App\Models\Proyecto;
use App\Models\ProyectoHito;
use App\Models\TicketSoporte;
use App\Models\User;
use App\Support\HoraLocal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reportes comerciales del panel de servicios (Ola 3, docs/ola3-diseno-datos.md §3.6): consultas agregadas sobre los
 * módulos del CRM, sin tablas propias. Una sola implementación sirve a la pantalla y a la exportación, así que las
 * cifras no pueden diferir.
 *
 * El periodo son días completos de Lima (`desde` y `hasta` incluidos). Cada bloque solo se entrega a quien puede ver su
 * módulo. Son totales y estados: no incluyen nombres, contactos ni textos libres de nadie.
 */
final class ReportesComerciales
{
    /** Días máximos del periodo, para acotar el coste de las consultas. */
    public const MAX_DIAS = 366;

    /** Días del periodo por defecto (los últimos 30, hoy incluido). */
    public const DIAS_POR_DEFECTO = 30;

    /**
     * @return array{desde: CarbonImmutable, hasta: CarbonImmutable}
     */
    public static function periodo(?string $desde, ?string $hasta): array
    {
        $fin = $hasta !== null ? CarbonImmutable::parse($hasta, HoraLocal::ZONA) : HoraLocal::ahora();
        $inicio = $desde !== null ? CarbonImmutable::parse($desde, HoraLocal::ZONA) : $fin->subDays(self::DIAS_POR_DEFECTO - 1);

        return ['desde' => $inicio->startOfDay(), 'hasta' => $fin->endOfDay()];
    }

    /**
     * @return array<string, mixed>
     */
    public function generar(User $usuario, CarbonImmutable $desde, CarbonImmutable $hasta, ?int $responsableId): array
    {
        // Los instantes se comparan en la zona de la aplicación (UTC), que es en la que se guardan.
        $inicio = $desde->setTimezone((string) config('app.timezone'));
        $fin = $hasta->setTimezone((string) config('app.timezone'));

        $bloques = [];
        if ($usuario->can('prospectos.ver')) {
            $bloques['prospectos'] = $this->prospectos($inicio, $fin, $responsableId);
        }
        if ($usuario->can('pipeline.ver')) {
            $bloques['oportunidades'] = $this->oportunidades($inicio, $fin, $responsableId);
        }
        if ($usuario->can('soporte.ver')) {
            $bloques['soporte'] = $this->soporte($inicio, $fin, $responsableId);
        }
        if ($usuario->can('proyectos.ver')) {
            $bloques['proyectos'] = $this->proyectos($inicio, $fin, $responsableId);
        }

        return [
            'periodo' => ['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString()],
            'bloques' => $bloques,
        ];
    }

    /** @return array<string, mixed> */
    private function prospectos(CarbonImmutable $inicio, CarbonImmutable $fin, ?int $responsable): array
    {
        $base = Prospecto::query()->whereBetween('created_at', [$inicio, $fin])
            ->when($responsable !== null, fn (Builder $q) => $q->where('responsable_id', $responsable));
        $porEstado = (clone $base)->selectRaw('estado, COUNT(*) as total')->groupBy('estado')->pluck('total', 'estado');
        $creados = (int) $porEstado->sum();
        $convertidos = (int) ($porEstado['convertido'] ?? 0);

        return [
            'creados' => $creados,
            'por_estado' => $this->completar($porEstado->all(), Prospecto::ESTADOS),
            'convertidos' => $convertidos,
            // Cohorte: de los prospectos creados en el periodo, cuántos ya son clientes.
            'tasa_conversion' => $creados === 0 ? null : round($convertidos * 100 / $creados, 1),
        ];
    }

    /** @return array<string, mixed> */
    private function oportunidades(CarbonImmutable $inicio, CarbonImmutable $fin, ?int $responsable): array
    {
        $propias = fn (): Builder => Oportunidad::query()->when($responsable !== null, fn (Builder $q) => $q->where('responsable_id', $responsable));

        // Valor abierto: instantánea de hoy (no depende del periodo), ponderada por la probabilidad de cada una.
        $abiertas = $propias()->whereIn('etapa', Oportunidad::ABIERTAS)->get(['monto_estimado', 'probabilidad']);
        $ponderado = $abiertas->sum(fn (Oportunidad $o): float => (float) $o->monto_estimado * $o->probabilidad / 100);

        $creadas = $propias()->whereBetween('created_at', [$inicio, $fin])
            ->selectRaw('etapa, COUNT(*) as total')->groupBy('etapa')->pluck('total', 'etapa');
        $ganadas = $this->cerradas($propias(), 'ganada', $inicio, $fin);
        $perdidas = $this->cerradas($propias(), 'perdida', $inicio, $fin);
        $decididas = $ganadas['total'] + $perdidas['total'];

        return [
            'abiertas' => [
                'total' => $abiertas->count(),
                'monto' => $this->dinero($abiertas->sum(fn (Oportunidad $o): float => (float) $o->monto_estimado)),
                'monto_ponderado' => $this->dinero($ponderado),
            ],
            'creadas' => (int) $creadas->sum(),
            'creadas_por_etapa' => $this->completar($creadas->all(), Oportunidad::ETAPAS),
            'ganadas' => $ganadas,
            'perdidas' => $perdidas,
            // Entre las decididas del periodo (ganadas + perdidas), la proporción ganada.
            'tasa_exito' => $decididas === 0 ? null : round($ganadas['total'] * 100 / $decididas, 1),
        ];
    }

    /**
     * @param  Builder<Oportunidad>  $base
     * @return array{total: int, monto: string}
     */
    private function cerradas(Builder $base, string $etapa, CarbonImmutable $inicio, CarbonImmutable $fin): array
    {
        $consulta = $base->where('etapa', $etapa)->whereBetween('cerrada_at', [$inicio, $fin]);

        return ['total' => (int) (clone $consulta)->count(), 'monto' => $this->dinero((clone $consulta)->sum('monto_estimado'))];
    }

    /** @return array<string, mixed> */
    private function soporte(CarbonImmutable $inicio, CarbonImmutable $fin, ?int $responsable): array
    {
        $propios = fn (): Builder => TicketSoporte::query()->when($responsable !== null, fn (Builder $q) => $q->where('asignado_a', $responsable));
        $creados = $propios()->whereBetween('created_at', [$inicio, $fin]);

        $porEstado = (clone $creados)->selectRaw('estado, COUNT(*) as total')->groupBy('estado')->pluck('total', 'estado');
        $porPrioridad = (clone $creados)->selectRaw('prioridad, COUNT(*) as total')->groupBy('prioridad')->pluck('total', 'prioridad');

        // Tiempo medio de resolución de los resueltos en el periodo: de la apertura a la resolución, en horas.
        $horas = $propios()->whereBetween('resuelto_at', [$inicio, $fin])->get(['created_at', 'resuelto_at'])
            ->map(fn (TicketSoporte $t): float => max(0, $t->created_at->diffInSeconds($t->resuelto_at, true)) / 3600);

        return [
            'creados' => (int) $porEstado->sum(),
            'por_estado' => $this->completar($porEstado->all(), TicketSoporte::ESTADOS),
            'por_prioridad' => $this->completar($porPrioridad->all(), TicketSoporte::PRIORIDADES),
            'resueltos' => $horas->count(),
            'tiempo_medio_resolucion_horas' => $horas->isEmpty() ? null : round($horas->avg(), 1),
            // Instantánea de hoy: abiertos que ya pasaron el límite del SLA.
            'vencidos' => $propios()->whereIn('estado', ['abierto', 'en_progreso', 'en_espera_cliente'])->where('vence_at', '<', now())->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function proyectos(CarbonImmutable $inicio, CarbonImmutable $fin, ?int $responsable): array
    {
        $propios = fn (): Builder => Proyecto::query()->when($responsable !== null, fn (Builder $q) => $q->where('responsable_id', $responsable));
        $porEstado = $propios()->selectRaw('estado, COUNT(*) as total')->groupBy('estado')->pluck('total', 'estado');

        $hitosVencidos = ProyectoHito::query()
            ->whereNull('completado_at')->whereNotNull('fecha_objetivo')->whereDate('fecha_objetivo', '<', HoraLocal::hoy())
            ->whereHas('proyecto', fn (Builder $q) => $q->whereIn('estado', Proyecto::ACTIVOS)
                ->when($responsable !== null, fn (Builder $r) => $r->where('responsable_id', $responsable)))
            ->count();

        return [
            // Instantánea de hoy por estado; los creados sí dependen del periodo.
            'por_estado' => $this->completar($porEstado->all(), Proyecto::ESTADOS),
            'creados' => $propios()->whereBetween('created_at', [$inicio, $fin])->count(),
            'completados' => $propios()->where('estado', 'completado')->whereDate('fecha_fin_real', '>=', $inicio->setTimezone(HoraLocal::ZONA)->toDateString())->whereDate('fecha_fin_real', '<=', $fin->setTimezone(HoraLocal::ZONA)->toDateString())->count(),
            'hitos_vencidos' => $hitosVencidos,
        ];
    }

    /**
     * Todas las claves esperadas, con cero cuando no hay filas (las gráficas y la exportación no deben cambiar de forma).
     *
     * @param  array<string, mixed>  $datos
     * @param  list<string>  $claves
     * @return array<string, int>
     */
    private function completar(array $datos, array $claves): array
    {
        $resultado = [];
        foreach ($claves as $clave) {
            $resultado[$clave] = (int) ($datos[$clave] ?? 0);
        }

        return $resultado;
    }

    private function dinero(mixed $valor): string
    {
        return number_format((float) $valor, 2, '.', '');
    }
}
