<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AprobacionInterna;
use App\Models\AuditLog;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\NotificacionInterna;
use App\Models\ProcesoInterno;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class DashboardController extends Controller
{
    public function index(): View
    {
        $inicioMes = now()->startOfMonth();
        $finMes = now()->endOfMonth();

        $matriculasMes = Matricula::query()
            ->whereBetween('fecha_matricula', [$inicioMes, $finMes])
            ->count();

        $ingresosMes = Matricula::query()
            ->where('estado', 'pagada')
            ->whereBetween('fecha_matricula', [$inicioMes, $finMes])
            ->sum('monto_pagado');

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

        $ultimasMatriculas = Matricula::query()
            ->with(['estudiante', 'curso'])
            ->latest('fecha_matricula')
            ->latest('id')
            ->limit(5)
            ->get();

        $ultimosEstudiantes = Estudiante::query()
            ->latest()
            ->limit(5)
            ->get();

        $auditoriasRecientes = AuditLog::query()
            ->with('user')
            ->latest('created_at')
            ->limit(5)
            ->get();

        $procesosPendientes = ProcesoInterno::query()->where('estado', 'pendiente')->count();
        $aprobacionesPendientes = AprobacionInterna::query()->where('estado', 'pendiente')->count();
        $notificacionesPendientes = NotificacionInterna::query()
            ->where(function ($query): void {
                $query->where('user_id', auth()->id())
                    ->orWhereNull('user_id');
            })
            ->where('leida', false)
            ->count();

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
            'procesosPendientes' => $procesosPendientes,
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
