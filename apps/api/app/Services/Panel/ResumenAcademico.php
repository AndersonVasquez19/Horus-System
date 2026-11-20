<?php

declare(strict_types=1);

namespace App\Services\Panel;

use App\Models\AprobacionInterna;
use App\Models\AuditLog;
use App\Models\Convocatoria;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use App\Services\GestionAsistencias;
use App\Services\GestionPagos;
use App\Support\Entrada;
use App\Support\HoraLocal;
use App\Support\NotificacionesPendientes;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Indicadores del dashboard académico. Solo datos del dominio académico (sin servicios, cotizaciones ni
 * reclamaciones) y cada bloque únicamente si el usuario tiene el permiso del módulo correspondiente.
 *
 * Mismos criterios que el dashboard Blade (V-03): los ingresos solo para el rol `admin`, y las últimas
 * matrículas minimizadas (sin montos ni observaciones).
 *
 * Tablero de Horus Academic (ocho cifras, cada una SOLO si la persona tiene el permiso de su módulo; el servidor no envía lo que no
 * puede consultar):
 *  - `acciones`  → pagos por confirmar y saldo pendiente (`pagos.ver`), ediciones que requieren acción (`convocatorias.ver`);
 *  - `negocio`   → cobrado del mes y saldo por cobrar (`pagos.ver`); las matrículas del mes y del mes anterior van en `indicadores`;
 *  - `academico` → cupos por edición (`convocatorias.ver`), asistencia por edición en curso (`asistencias.ver`).
 * Los importes salen del libro de pagos (confirmado neto de reversos), nunca de `matriculas.monto_pagado`.
 */
final class ResumenAcademico
{
    private const MESES = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

    /** @return array<string, mixed> */
    public function para(User $user): array
    {
        $indicadores = [];
        $bloques = [];
        $acciones = [];
        $negocio = [];
        $academico = [];
        $seguimiento = [];

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

            $indicadores['matriculas_mes'] = Matricula::query()->whereBetween('fecha_matricula', [$inicioMes, $finMes])->count();
            $indicadores['matriculas_mes_anterior'] = Matricula::query()
                ->whereBetween('fecha_matricula', [$inicioMes->subMonthNoOverflow()->startOfMonth(), $inicioMes->subMonthNoOverflow()->endOfMonth()])->count();
            if ($verIngresos) {
                $indicadores['ingresos_mes'] = GestionPagos::formato($this->cobradoDelMes());
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
                    'fecha' => $matricula->fecha_matricula->toDateString(),
                ])
                ->all();
        }

        if ($user->can('pagos.ver')) {
            $pendientes = DB::table('transacciones_pago')->where('tipo', 'pago')->where('estado', 'pendiente')->pluck('monto');
            $acciones['pagos_por_confirmar'] = ['cantidad' => $pendientes->count(), 'monto' => GestionPagos::formato($pendientes->sum(fn ($m): int => GestionPagos::leer($m)))];

            [$conSaldo, $saldo] = $this->saldoPorCobrar();
            $acciones['matriculas_con_saldo'] = ['cantidad' => $conSaldo, 'monto' => GestionPagos::formato($saldo)];
            $acciones['cuotas'] = $this->cuotasPendientes();

            $negocio['cobrado_mes'] = GestionPagos::formato($this->cobradoDelMes());
            $negocio['saldo_por_cobrar'] = GestionPagos::formato($saldo);
        }

        if ($user->can('convocatorias.ver')) {
            $hoy = HoraLocal::hoy();
            $porCerrar = Convocatoria::query()->with('curso:id,nombre')->where('estado', 'en_curso')->whereDate('fecha_fin', '<', $hoy);
            $acciones['ediciones'] = [
                'por_iniciar' => Convocatoria::query()->where('estado', 'abierta')->whereDate('fecha_inicio', '<=', $hoy)->count(),
                'por_cerrar' => (clone $porCerrar)->count(),
                'lista_por_cerrar' => (clone $porCerrar)->orderBy('fecha_fin')->limit(5)->get()->map(fn (Convocatoria $c): array => [
                    'identificador' => $c->identificador, 'codigo' => $c->codigo, 'curso' => $c->curso?->nombre,
                ])->all(),
            ];

            $academico['cupos'] = Convocatoria::query()->with('curso:id,nombre')->whereIn('estado', ['abierta', 'en_curso'])
                ->orderBy('cupos_disponibles')->orderBy('id')->limit(8)->get()->map(fn (Convocatoria $c): array => [
                    'identificador' => $c->identificador, 'codigo' => $c->codigo, 'curso' => $c->curso?->nombre, 'maximo' => $c->cupos_maximos,
                    'ocupados' => $c->cupos_maximos - $c->cupos_disponibles, 'libres' => $c->cupos_disponibles,
                ])->all();
        }

        if ($user->can('asistencias.ver')) {
            $minimo = Entrada::entero(config('academico.asistencia_minima', 80));
            $academico['asistencia_minima'] = $minimo;
            $academico['asistencia'] = Convocatoria::query()->with('curso:id,nombre')->where('estado', 'en_curso')->orderBy('fecha_fin')->limit(8)->get()
                ->map(function (Convocatoria $c) use ($minimo): array {
                    $r = app(GestionAsistencias::class)->resumen($c);
                    $valores = collect($r['matriculas'])->pluck('porcentaje')->filter(fn ($p): bool => $p !== null);
                    $promedio = $valores->isEmpty() ? null : round((float) $valores->avg(), 1);

                    return [
                        'identificador' => $c->identificador, 'codigo' => $c->codigo, 'curso' => $c->curso?->nombre, 'sesiones_realizadas' => $r['sesiones_realizadas'],
                        'promedio' => $promedio, 'bajo_minimo' => $promedio !== null && $promedio < $minimo,
                    ];
                })->all();
        }


        // Seguimiento (equivale a los bloques de pendientes y actividad del tablero Blade): cada bloque solo con su permiso.
        if ($user->can('aprobaciones.ver')) {
            $seguimiento['aprobaciones_pendientes'] = AprobacionInterna::query()->where('estado', 'pendiente')->count();
        }
        $seguimiento['notificaciones_sin_leer'] = NotificacionesPendientes::para($user);
        if ($user->can('auditoria.ver')) {
            $seguimiento['auditoria_reciente'] = AuditLog::query()->with('user:id,name')->latest('created_at')->limit(5)->get()
                ->map(fn (AuditLog $a): array => [
                    'id' => $a->id,
                    'fecha_local' => HoraLocal::formato($a->created_at, 'd/m/Y H:i'),
                    'usuario' => $a->user?->name,
                    'accion' => $a->action,
                    'accion_etiqueta' => \App\Support\Panel\PresentadorAuditoria::etiquetaAccion((string) $a->action),
                ])->all();
        }

        return ['indicadores' => $indicadores, 'bloques' => $bloques, 'acciones' => $acciones, 'negocio' => $negocio, 'academico' => $academico, 'seguimiento' => $seguimiento];
    }

    /**
     * Matrículas con obligación vigente que aún deben algo (lo confirmado neto de reversos es menor que el total) y el saldo total, en
     * céntimos.
     *
     * @return array{0: int, 1: int}
     */
    private function saldoPorCobrar(): array
    {
        $filas = DB::table('obligaciones_pago as o')
            ->leftJoin('transacciones_pago as t', fn ($j) => $j->on('t.obligacion_pago_id', '=', 'o.id')->where('t.estado', 'confirmada'))
            ->where('o.estado', 'vigente')
            ->groupBy('o.id', 'o.monto_total')
            ->selectRaw("o.monto_total, COALESCE(SUM(CASE WHEN t.tipo = 'pago' THEN t.monto ELSE -t.monto END), 0) as confirmado")
            ->get();

        $cantidad = 0;
        $saldo = 0;
        foreach ($filas as $f) {
            $debe = GestionPagos::leer($f->monto_total) - GestionPagos::leer($f->confirmado);
            if ($debe > 0) {
                $cantidad++;
                $saldo += $debe;
            }
        }

        return [$cantidad, $saldo];
    }

    /**
     * Cuotas con saldo pendiente de obligaciones vigentes, separadas en vencidas (`vence < hoy`) y por vencer en los próximos 7 días.
     *
     * @return array{vencidas: int, monto_vencido: string, por_vencer: int, monto_por_vencer: string}
     */
    private function cuotasPendientes(): array
    {
        $hoy = HoraLocal::hoy();
        $limite = HoraLocal::ahora()->addDays(7)->toDateString();

        $filas = DB::table('cuotas_obligacion as c')
            ->join('obligaciones_pago as o', 'o.id', '=', 'c.obligacion_pago_id')
            ->leftJoin('asignaciones_pago as a', 'a.cuota_obligacion_id', '=', 'c.id')
            ->where('o.estado', 'vigente')
            ->whereDate('c.vence', '<=', $limite)
            ->groupBy('c.id', 'c.monto', 'c.vence')
            ->selectRaw('c.monto, c.vence, COALESCE(SUM(a.monto), 0) as pagado')
            ->get();

        $vencidas = 0;
        $montoVencido = 0;
        $porVencer = 0;
        $montoPorVencer = 0;

        foreach ($filas as $f) {
            $saldo = GestionPagos::leer($f->monto) - GestionPagos::leer($f->pagado);
            if ($saldo <= 0) {
                continue;
            }
            $vence = substr((string) $f->vence, 0, 10);
            if ($vence < $hoy) {
                $vencidas++;
                $montoVencido += $saldo;
            } else {
                $porVencer++;
                $montoPorVencer += $saldo;
            }
        }

        return [
            'vencidas' => $vencidas,
            'monto_vencido' => GestionPagos::formato($montoVencido),
            'por_vencer' => $porVencer,
            'monto_por_vencer' => GestionPagos::formato($montoPorVencer),
        ];
    }

    /** Lo cobrado en el mes (Lima) según el libro: pagos confirmados menos reversos confirmados, por fecha de pago. En céntimos. */
    private function cobradoDelMes(): int
    {
        $ahora = HoraLocal::ahora();
        $filas = DB::table('transacciones_pago')->where('estado', 'confirmada')
            ->whereBetween('fecha_pago', [$ahora->startOfMonth()->toDateString(), $ahora->endOfMonth()->toDateString()])
            ->get(['tipo', 'monto']);

        return (int) $filas->sum(fn ($t): int => $t->tipo === 'pago' ? GestionPagos::leer($t->monto) : -GestionPagos::leer($t->monto));
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

        return array_values(collect(range(0, 5))->map(function (int $desplazamiento) use ($desde, $totales): array {
            $mes = $desde->copy()->addMonths($desplazamiento);
            $periodo = $mes->format('Y-m');

            return [
                'periodo' => $periodo,
                'etiqueta' => self::MESES[(int) $mes->format('n') - 1],
                'total' => Entrada::entero(($totales[$periodo] ?? 0)),
            ];
        })->all());
    }
}
