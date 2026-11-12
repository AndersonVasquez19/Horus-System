<?php

declare(strict_types=1);

namespace App\Services\Panel;

use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use App\Support\HoraLocal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Indicadores del dashboard académico. Solo datos del dominio académico (sin servicios, cotizaciones ni
 * reclamaciones) y cada bloque únicamente si el usuario tiene el permiso del módulo correspondiente.
 *
 * Mismos criterios que el dashboard Blade (V-03): los ingresos solo para el rol `admin`, y las últimas
 * matrículas minimizadas (sin montos ni observaciones).
 */
final class ResumenAcademico
{
    private const MESES = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

    /** @return array<string, mixed> */
    public function para(User $user): array
    {
        $indicadores = [];
        $bloques = [];

        if ($user->can('estudiantes.ver')) {
            $indicadores['estudiantes_activos'] = Estudiante::query()->where('estado', 'activo')->count();
        }

        if ($user->can('cursos.ver')) {
            $activos = Curso::query()->where('estado', 'activo');
            $indicadores['cursos_activos'] = (clone $activos)->count();
            $indicadores['cupos_disponibles'] = (int) (clone $activos)->sum('cupos_disponibles');
            $indicadores['cupos_maximos'] = (int) (clone $activos)->sum('cupos_maximos');
        }

        if ($user->can('matriculas.ver')) {
            $inicioMes = HoraLocal::ahora()->startOfMonth();
            $finMes = HoraLocal::ahora()->endOfMonth();
            $verIngresos = $user->hasRole('admin');

            $consulta = Matricula::query()->whereBetween('fecha_matricula', [$inicioMes, $finMes]);
            $resumen = ($verIngresos
                ? $consulta->selectRaw('COUNT(*) as total, COALESCE(SUM(CASE WHEN estado = ? THEN monto_pagado ELSE 0 END), 0) as ingresos', ['pagada'])
                : $consulta->selectRaw('COUNT(*) as total'))
                ->toBase()->first();

            $indicadores['matriculas_mes'] = (int) $resumen->total;
            if ($verIngresos) {
                $indicadores['ingresos_mes'] = number_format((float) $resumen->ingresos, 2, '.', '');
            }
            $indicadores['matriculas_pendientes'] = Matricula::query()->where('estado', 'pendiente')->count();

            $bloques['matriculas_por_mes'] = $this->matriculasPorMes($inicioMes->copy()->subMonths(5), $finMes);
            $bloques['ultimas_matriculas'] = Matricula::query()
                ->select(['id', 'estudiante_id', 'curso_id', 'estado', 'fecha_matricula'])
                ->with(['estudiante:id,nombres,apellidos', 'curso:id,nombre'])
                ->latest('fecha_matricula')
                ->latest('id')
                ->limit(6)
                ->get()
                ->map(fn (Matricula $matricula): array => [
                    'id' => $matricula->id,
                    'estudiante' => $matricula->estudiante
                        ? trim($matricula->estudiante->nombres.' '.$matricula->estudiante->apellidos)
                        : null,
                    'curso' => $matricula->curso?->nombre,
                    'estado' => $matricula->estado,
                    'fecha' => $matricula->fecha_matricula?->toDateString(),
                ])
                ->all();
        }

        return ['indicadores' => $indicadores, 'bloques' => $bloques];
    }

    /** @return list<array{periodo: string, etiqueta: string, total: int}> */
    private function matriculasPorMes(CarbonInterface $desde, CarbonInterface $hasta): array
    {
        $periodoSql = match (DB::connection()->getDriverName()) {
            'pgsql' => "TO_CHAR(fecha_matricula, 'YYYY-MM')",
            'sqlite' => "strftime('%Y-%m', fecha_matricula)",
            default => throw new LogicException('Motor de base de datos no admitido para totales mensuales.'),
        };

        $totales = Matricula::query()
            ->selectRaw("{$periodoSql} as periodo, COUNT(*) as total")
            ->whereBetween('fecha_matricula', [$desde, $hasta])
            ->groupBy('periodo')
            ->pluck('total', 'periodo');

        return collect(range(0, 5))->map(function (int $desplazamiento) use ($desde, $totales): array {
            $mes = $desde->copy()->addMonths($desplazamiento);
            $periodo = $mes->format('Y-m');

            return [
                'periodo' => $periodo,
                'etiqueta' => self::MESES[(int) $mes->format('n') - 1],
                'total' => (int) ($totales[$periodo] ?? 0),
            ];
        })->all();
    }
}
