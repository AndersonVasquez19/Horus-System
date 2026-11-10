<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AprobacionInterna;
use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\NotificacionInterna;
use App\Models\User;
use App\Support\HoraLocal;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class DashboardController extends Controller
{
    public function index(): View
    {
        // El «mes actual» es el de Lima: en UTC, desde las 19:00 del último día ya sería el mes siguiente.
        $inicioMes = HoraLocal::ahora()->startOfMonth();
        $finMes = HoraLocal::ahora()->endOfMonth();

        // V-03: los ingresos del mes son exclusivos de Gerencia (rol `admin`). Para los demás roles el
        // agregado financiero ni se calcula ni se envía a la vista (`ingresosMes` = null); solo se cuentan
        // las matrículas. Reportes, Matrículas y Estudiantes quedan pendientes de decisión institucional.
        $verIngresos = auth()->user()?->hasRole('admin') === true;
        $consultaMes = Matricula::query()->whereBetween('fecha_matricula', [$inicioMes, $finMes]);
        $resumenMes = ($verIngresos
            ? $consultaMes->selectRaw('COUNT(*) as total, COALESCE(SUM(CASE WHEN estado = ? THEN monto_pagado ELSE 0 END), 0) as ingresos', ['pagada'])
            : $consultaMes->selectRaw('COUNT(*) as total'))
            ->toBase()->first();
        $matriculasMes = (int) $resumenMes->total;
        $ingresosMes = $verIngresos ? $resumenMes->ingresos : null;

        $primerMes = $inicioMes->copy()->subMonths(5);
        $periodoSql = match (DB::connection()->getDriverName()) {
            'pgsql' => "TO_CHAR(fecha_matricula, 'YYYY-MM')",
            'sqlite' => "strftime('%Y-%m', fecha_matricula)",
            default => throw new \LogicException('Database driver not supported for monthly enrollment totals.'),
        };
        $matriculasPorMes = Matricula::query()
            ->selectRaw("{$periodoSql} as periodo, COUNT(*) as total")
            ->whereBetween('fecha_matricula', [$primerMes, $finMes])
            ->groupBy('periodo')
            ->pluck('total', 'periodo');

        $nombresMeses = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
        $meses = collect(range(0, 5))
            ->map(function (int $offset) use ($primerMes, $matriculasPorMes, $nombresMeses): array {
                $mes = $primerMes->copy()->addMonths($offset);
                $periodo = $mes->format('Y-m');
                $total = (int) ($matriculasPorMes[$periodo] ?? 0);

                return [
                    'etiqueta' => $nombresMeses[(int) $mes->format('n') - 1],
                    'total' => $total,
                ];
            });

        $maximoMes = max(1, (int) $meses->max('total'));
        $meses = $meses->map(function (array $mes) use ($maximoMes): array {
            $proporcion = $mes['total'] / $maximoMes;
            $mes['clase_altura'] = match (true) {
                $mes['total'] === 0 => 'h-1',
                $proporcion >= 0.75 => 'h-36',
                $proporcion >= 0.5 => 'h-28',
                $proporcion >= 0.25 => 'h-20',
                default => 'h-12',
            };

            return $mes;
        });

        // V-03 (minimización): la tabla «Últimas matrículas» solo muestra estudiante (nombres, apellidos),
        // curso (nombre), estado y fecha. Se seleccionan exactamente esas columnas más las claves que necesitan
        // las relaciones; ningún rol recibe `monto_pagado`, `observaciones` ni `curso.precio` en este bloque.
        $ultimasMatriculas = Matricula::query()
            ->select(['id', 'estudiante_id', 'curso_id', 'estado', 'fecha_matricula'])
            ->with(['estudiante:id,nombres,apellidos', 'curso:id,nombre'])
            ->latest('fecha_matricula')
            ->latest('id')
            ->limit(5)
            ->get();

        $ultimosEstudiantes = Estudiante::query()
            ->latest()
            ->limit(5)
            ->get();

        // La actividad de auditoría solo se muestra a quien tiene permiso para consultarla.
        $auditoriasRecientes = auth()->user()->can('auditoria.ver')
            ? AuditLog::query()
                ->with('user')
                ->latest('created_at')
                ->limit(5)
                ->get()
            : collect();

        $aprobacionesPendientes = AprobacionInterna::query()->where('estado', 'pendiente')->count();
        $usuarioActual = auth()->user();
        // Mismo criterio de visibilidad que la bandeja: personales propias y generales explícitas.
        $notificacionesPendientes = $usuarioActual instanceof User
            ? NotificacionInterna::query()->visiblePara($usuarioActual)->where('leida', false)->count()
            : 0;

        $rolActual = auth()->user()->getRoleNames()->first() ?? 'sin-rol';
        $miArea = match ($rolActual) {
            'admin' => 'Administración general',
            'coordinador' => 'Operación académica',
            'secretaria' => 'Atención y soporte',
            default => 'Operación interna',
        };

        return view('admin.dashboard', [
            'estudiantesActivos' => Estudiante::query()->where('estado', 'activo')->count(),
            'cursosActivos' => Curso::query()->where('estado', 'activo')->count(),
            'matriculasMes' => $matriculasMes,
            'ingresosMes' => $ingresosMes,
            'usuariosActivos' => User::query()->count(),
            'rolesActivos' => Role::query()->count(),
            'aprobacionesPendientes' => $aprobacionesPendientes,
            'notificacionesPendientes' => $notificacionesPendientes,
            'miRol' => ucfirst(str_replace('-', ' ', $rolActual)),
            'miArea' => $miArea,
            'meses' => $meses,
            'ultimasMatriculas' => $ultimasMatriculas,
            'ultimosEstudiantes' => $ultimosEstudiantes,
            'auditoriasRecientes' => $auditoriasRecientes,
        ]);
    }
}
