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
use Closure;
use Illuminate\Support\Facades\Cache;
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
    private const VERSION = 'panel:resumen:version';

    private ?int $cobradoMes = null;

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
        $saludGrupo = [];

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

            $bloques['matriculas_por_mes'] = $this->recordar('matriculas_por_mes', fn (): array => $this->matriculasPorMes($inicioMes->copy()->subMonths(5), $finMes));
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
            [$porConfirmar, $montoPorConfirmar] = $this->recordar('pagos_por_confirmar', fn (): array => $this->pagosPorConfirmar());
            $acciones['pagos_por_confirmar'] = ['cantidad' => $porConfirmar, 'monto' => GestionPagos::formato($montoPorConfirmar)];

            [$conSaldo, $saldo] = $this->recordar('saldo_por_cobrar', fn (): array => $this->saldoPorCobrar());
            $acciones['matriculas_con_saldo'] = ['cantidad' => $conSaldo, 'monto' => GestionPagos::formato($saldo)];
            $acciones['cuotas'] = $this->recordar('cuotas', fn (): array => $this->cuotasPendientes());

            $negocio['cobrado_mes'] = GestionPagos::formato($this->cobradoDelMes());
            $negocio['saldo_por_cobrar'] = GestionPagos::formato($saldo);
        }

        // Cada bloque se calcula una sola vez por petición: el dinero del mes lo piden `indicadores` y `negocio`.

        // Semáforo de plazos del Libro de Reclamaciones (plan de mejora 3.12): conteos, sin datos personales.
        if ($user->can('reclamaciones.ver')) {
            $acciones['reclamaciones'] = \App\Support\ConsultaReclamaciones::plazos();
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

            $academico['satisfaccion'] = $this->recordar('satisfaccion', fn (): array => app(\App\Services\SatisfaccionEncuestas::class)->resumen(null, \App\Services\SatisfaccionEncuestas::DIAS_KPI));
            $academico['cupos'] =Convocatoria::query()->with('curso:id,nombre')->whereIn('estado', ['abierta', 'en_curso'])
                ->orderBy('cupos_disponibles')->orderBy('id')->limit(8)->get()->map(fn (Convocatoria $c): array => [
                    'identificador' => $c->identificador, 'codigo' => $c->codigo, 'curso' => $c->curso?->nombre, 'maximo' => $c->cupos_maximos,
                    'ocupados' => $c->cupos_maximos - $c->cupos_disponibles, 'libres' => $c->cupos_disponibles,
                ])->all();
        }

        if ($user->can('asistencias.ver')) {
            $minimo = Entrada::entero(config('academico.asistencia_minima', 80));
            $academico['asistencia_minima'] = $minimo;
            $enCurso = Convocatoria::query()->with('curso:id,nombre')->where('estado', 'en_curso')->orderBy('fecha_fin')->limit(8)->get();
            // Tres consultas para todas las ediciones (antes, varias por edición).
            $ids = $enCurso->map(fn (Convocatoria $c): int => (int) $c->id)->all();
            $promedios = $this->recordar('promedios:'.implode(',', $ids), fn (): array => app(GestionAsistencias::class)->promediosPorEdicion($ids));
            $academico['asistencia'] = $enCurso->map(function (Convocatoria $c) use ($minimo, $promedios): array {
                $r = $promedios[(int) $c->id];

                return [
                    'identificador' => $c->identificador, 'codigo' => $c->codigo, 'curso' => $c->curso?->nombre, 'sesiones_realizadas' => $r['sesiones_realizadas'],
                    'promedio' => $r['promedio'], 'bajo_minimo' => $r['promedio'] !== null && $r['promedio'] < $minimo,
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

        if ($user->can('pagos.ver')) {
            $saludGrupo['cuotas_vencidas'] = $this->recordar('cuotas_vencidas', fn (): array => $this->cuotasVencidasDetalle());
        }
        if ($user->can('asistencias.ver')) {
            $saludGrupo['inasistencias'] = $this->recordar('inasistencias', fn (): array => $this->inasistenciasReiteradas());
        }

        return ['indicadores' => $indicadores, 'bloques' => $bloques, 'acciones' => $acciones, 'negocio' => $negocio, 'academico' => $academico, 'seguimiento' => $seguimiento, 'salud_grupo' => $saludGrupo];
    }

    /**
     * Cuántos pagos esperan confirmación y su importe total, en céntimos.
     *
     * @return array{0: int, 1: int}
     */
    private function pagosPorConfirmar(): array
    {
        $fila = DB::table('transacciones_pago')->where('tipo', 'pago')->where('estado', 'pendiente')
            ->selectRaw('COUNT(*) as cantidad, COALESCE(SUM('.self::centimos('monto').'), 0) as total')->first();

        return [Entrada::entero($fila->cantidad ?? 0), Entrada::entero($fila->total ?? 0)];
    }

    /**
     * Matrículas con obligación vigente que aún deben algo (lo confirmado neto de reversos es menor que el total) y el saldo total, en
     * céntimos. Se calcula en SQL con enteros (céntimos): sin sumas en PHP ni coma flotante.
     *
     * @return array{0: int, 1: int}
     */
    private function saldoPorCobrar(): array
    {
        $confirmado = self::centimos('t.monto');
        $porObligacion = DB::table('obligaciones_pago as o')
            ->leftJoin('transacciones_pago as t', fn ($j) => $j->on('t.obligacion_pago_id', '=', 'o.id')->where('t.estado', 'confirmada'))
            ->where('o.estado', 'vigente')
            ->groupBy('o.id', 'o.monto_total')
            ->selectRaw('o.id, '.self::centimos('o.monto_total')." - COALESCE(SUM(CASE WHEN t.tipo = 'pago' THEN {$confirmado} ELSE -{$confirmado} END), 0) as debe");

        $fila = DB::query()->fromSub($porObligacion, 'x')->where('debe', '>', 0)
            ->selectRaw('COUNT(*) as cantidad, COALESCE(SUM(debe), 0) as total')->first();

        return [Entrada::entero($fila->cantidad ?? 0), Entrada::entero($fila->total ?? 0)];
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
        $vence = self::fechaSql('c.vence');

        $porCuota = DB::table('cuotas_obligacion as c')
            ->join('obligaciones_pago as o', 'o.id', '=', 'c.obligacion_pago_id')
            ->leftJoin('asignaciones_pago as a', 'a.cuota_obligacion_id', '=', 'c.id')
            ->where('o.estado', 'vigente')
            ->whereDate('c.vence', '<=', $limite)
            ->groupBy('c.id', 'c.monto', 'c.vence')
            ->selectRaw("{$vence} as vence, ".self::centimos('c.monto').' - COALESCE(SUM('.self::centimos('a.monto').'), 0) as saldo');

        $fila = DB::query()->fromSub($porCuota, 'x')->where('saldo', '>', 0)->selectRaw(
            'COALESCE(SUM(CASE WHEN vence < ? THEN 1 ELSE 0 END), 0) as vencidas, '
            .'COALESCE(SUM(CASE WHEN vence < ? THEN saldo ELSE 0 END), 0) as monto_vencido, '
            .'COALESCE(SUM(CASE WHEN vence >= ? THEN 1 ELSE 0 END), 0) as por_vencer, '
            .'COALESCE(SUM(CASE WHEN vence >= ? THEN saldo ELSE 0 END), 0) as monto_por_vencer',
            [$hoy, $hoy, $hoy, $hoy],
        )->first();

        return [
            'vencidas' => Entrada::entero($fila->vencidas ?? 0),
            'monto_vencido' => GestionPagos::formato(Entrada::entero($fila->monto_vencido ?? 0)),
            'por_vencer' => Entrada::entero($fila->por_vencer ?? 0),
            'monto_por_vencer' => GestionPagos::formato(Entrada::entero($fila->monto_por_vencer ?? 0)),
        ];
    }

    /**
     * Lo cobrado en el mes (Lima) según el libro: pagos confirmados menos reversos confirmados, por fecha de pago. En céntimos.
     * Se calcula una sola vez por petición (lo piden `indicadores` y `negocio`), con una consulta SQL agregada.
     */
    private function cobradoDelMes(): int
    {
        return $this->cobradoMes ??= $this->recordar('cobrado_mes', function (): int {
            $ahora = HoraLocal::ahora();
            $c = self::centimos('monto');
            $fila = DB::table('transacciones_pago')->where('estado', 'confirmada')
                ->whereBetween('fecha_pago', [$ahora->startOfMonth()->toDateString(), $ahora->endOfMonth()->toDateString()])
                ->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'pago' THEN {$c} ELSE -{$c} END), 0) as total")->first();

            return Entrada::entero($fila->total ?? 0);
        });
    }

    /** Importe en céntimos enteros de una columna decimal, exacto en SQLite y PostgreSQL. */
    private static function centimos(string $columna): string
    {
        return "CAST(ROUND({$columna} * 100) AS BIGINT)";
    }

    /** Parte de fecha (AAAA-MM-DD) de una columna, según el motor. */
    private static function fechaSql(string $columna): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "CAST({$columna} AS DATE)",
            'sqlite' => "date({$columna})",
            default => throw new LogicException('Motor de base de datos no admitido para fechas.'),
        };
    }

    /**
     * Guarda un bloque costoso unos segundos (`panel.resumen_cache_segundos`, 0 = sin caché). La clave lleva una versión que
     * {@see self::invalidar()} cambia en cada escritura de los modelos de los que depende el tablero, y el día de Lima: un pago
     * confirmado o una matrícula nueva se ven al recargar, no 30 s después. Solo se guardan bloques sin datos por usuario.
     *
     * @template T
     *
     * @param  Closure(): T  $calculo
     * @return T
     */
    private function recordar(string $clave, Closure $calculo): mixed
    {
        $segundos = Entrada::entero(config('panel.resumen_cache_segundos', 30));
        if ($segundos <= 0) {
            return $calculo();
        }
        $version = Entrada::texto(Cache::get(self::VERSION, '0'));

        return Cache::remember('panel:resumen:'.$version.':'.HoraLocal::hoy().':'.$clave, $segundos, $calculo);
    }

    /** Descarta los bloques guardados del tablero (se llama tras escribir un modelo del que dependen). */
    public static function invalidar(): void
    {
        Cache::forever(self::VERSION, (string) hrtime(true));
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

    /** @return list<array{obligacion: string, estudiante: string, telefono: string|null, concepto: string, vence: string, saldo: string}> */
    private function cuotasVencidasDetalle(): array
    {
        $hoy = HoraLocal::hoy();

        $filas = DB::table('cuotas_obligacion as c')
            ->join('obligaciones_pago as o', 'o.id', '=', 'c.obligacion_pago_id')
            ->join('matriculas as m', 'm.id', '=', 'o.matricula_id')
            ->join('estudiantes as e', 'e.id', '=', 'm.estudiante_id')
            ->leftJoin('asignaciones_pago as a', 'a.cuota_obligacion_id', '=', 'c.id')
            ->where('o.estado', 'vigente')
            ->whereNull('m.deleted_at')
            ->where('m.estado', '!=', 'anulada')
            ->whereDate('c.vence', '<', $hoy)
            ->groupBy('c.id', 'o.identificador', 'o.concepto', 'e.nombres', 'e.apellidos', 'e.telefono', 'c.monto', 'c.vence')
            ->selectRaw('o.identificador as obligacion, o.concepto, e.nombres, e.apellidos, e.telefono, c.monto, c.vence, COALESCE(SUM(a.monto), 0) as pagado')
            ->orderBy('c.vence')
            ->limit(25)
            ->get();

        $resultado = [];
        foreach ($filas as $f) {
            $saldo = GestionPagos::leer($f->monto) - GestionPagos::leer($f->pagado);
            if ($saldo <= 0) {
                continue;
            }
            $tel = Entrada::texto($f->telefono ?? '');
            $resultado[] = [
                'obligacion' => Entrada::texto($f->obligacion),
                'estudiante' => trim(Entrada::texto($f->nombres).' '.Entrada::texto($f->apellidos)),
                'telefono' => $tel !== '' ? $tel : null,
                'concepto' => Entrada::texto($f->concepto),
                'vence' => substr(Entrada::texto($f->vence), 0, 10),
                'saldo' => GestionPagos::formato($saldo),
            ];
            if (count($resultado) >= 6) {
                break;
            }
        }

        return $resultado;
    }

    /** @return list<array{matricula_id: int, estudiante: string, telefono: string|null, edicion: string, faltas_seguidas: int}> */
    private function inasistenciasReiteradas(): array
    {
        $filas = DB::table('asistencias as a')
            ->join('sesiones_clase as s', 's.id', '=', 'a.sesion_clase_id')
            ->join('matriculas as m', 'm.id', '=', 'a.matricula_id')
            ->join('convocatorias as c', 'c.id', '=', 'm.convocatoria_id')
            ->join('estudiantes as e', 'e.id', '=', 'm.estudiante_id')
            ->where('c.estado', 'en_curso')
            ->whereNull('m.deleted_at')
            ->where('m.estado', '!=', 'anulada')
            ->whereIn('s.estado', ['programada', 'realizada'])
            ->orderBy('m.id')
            ->orderByDesc('s.fecha')
            ->orderByDesc('s.numero')
            ->select(['m.id as matricula_id', 'e.nombres', 'e.apellidos', 'e.telefono', 'c.codigo as edicion', 'a.estado'])
            ->get();

        $agrupadas = [];
        foreach ($filas as $f) {
            $id = Entrada::entero($f->matricula_id);
            if (! isset($agrupadas[$id])) {
                $tel = Entrada::texto($f->telefono ?? '');
                $agrupadas[$id] = [
                    'matricula_id' => $id,
                    'estudiante' => trim(Entrada::texto($f->nombres).' '.Entrada::texto($f->apellidos)),
                    'telefono' => $tel !== '' ? $tel : null,
                    'edicion' => Entrada::texto($f->edicion),
                    'faltas_seguidas' => 0,
                    'cortado' => false,
                ];
            }
            if ($agrupadas[$id]['cortado']) {
                continue;
            }
            if (Entrada::texto($f->estado) === 'ausente') {
                $agrupadas[$id]['faltas_seguidas']++;
            } else {
                $agrupadas[$id]['cortado'] = true;
            }
        }

        $alertas = [];
        foreach ($agrupadas as $item) {
            if ($item['faltas_seguidas'] >= 2) {
                $alertas[] = [
                    'matricula_id' => $item['matricula_id'],
                    'estudiante' => $item['estudiante'],
                    'telefono' => $item['telefono'],
                    'edicion' => $item['edicion'],
                    'faltas_seguidas' => $item['faltas_seguidas'],
                ];
            }
        }
        usort($alertas, static fn (array $a, array $b): int => $b['faltas_seguidas'] <=> $a['faltas_seguidas']);

        return array_slice($alertas, 0, 6);
    }
}
