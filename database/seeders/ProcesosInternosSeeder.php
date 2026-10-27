<?php

namespace Database\Seeders;

use App\Models\NotificacionInterna;
use App\Models\ProcesoInterno;
use App\Models\User;
use Illuminate\Database\Seeder;

class ProcesosInternosSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('email', 'admin@horusgroup.com')->first();
        $coordinador = User::where('email', 'coordinador@horusgroup.com')->first();
        $secretaria = User::where('email', 'secretaria@horusgroup.com')->first();

        $procesos = [
            [
                'titulo' => 'Revisión de matrículas pendientes',
                'descripcion' => 'Validar estudiantes con pagos pendientes para cerrar la última semana operativa.',
                'responsable_id' => $secretaria?->id,
                'estado' => 'pendiente',
                'prioridad' => 'alta',
                'fecha_vencimiento' => now()->addDays(3)->toDateString(),
                'created_by' => $admin?->id,
                'observaciones' => 'Requiere revisión cruzada con caja y coordinación.',
            ],
            [
                'titulo' => 'Actualización de cupos por curso',
                'descripcion' => 'Confirmar disponibilidad real de cupos y ajustar sesiones de atención.',
                'responsable_id' => $coordinador?->id,
                'estado' => 'en_proceso',
                'prioridad' => 'media',
                'fecha_vencimiento' => now()->addDays(7)->toDateString(),
                'created_by' => $admin?->id,
                'observaciones' => 'Se necesita información del área académica.',
            ],
            [
                'titulo' => 'Cierre de reportes mensuales',
                'descripcion' => 'Preparar resumen ejecutivo de ingresos, alumnos y cursos activos.',
                'responsable_id' => $admin?->id,
                'estado' => 'completado',
                'prioridad' => 'alta',
                'fecha_vencimiento' => now()->subDay()->toDateString(),
                'created_by' => $admin?->id,
                'observaciones' => 'Reporte listo para revisión final.',
            ],
        ];

        foreach ($procesos as $proceso) {
            ProcesoInterno::query()->firstOrCreate([
                'titulo' => $proceso['titulo'],
            ], $proceso);
        }

        $notificaciones = [
            [
                'user_id' => $admin?->id,
                'titulo' => 'Nuevo proceso asignado',
                'mensaje' => 'Revisión de matrículas pendientes requiere atención hoy.',
                'tipo' => 'warning',
                'vinculo' => '/admin/procesos',
            ],
            [
                'user_id' => $coordinador?->id,
                'titulo' => 'Revisión de cupos',
                'mensaje' => 'Se ha iniciado la revisión de cupos por curso.',
                'tipo' => 'info',
                'vinculo' => '/admin/procesos',
            ],
            [
                'user_id' => null,
                'titulo' => 'Cierre mensual',
                'mensaje' => 'El reporte mensual finalizó correctamente.',
                'tipo' => 'success',
                'vinculo' => '/admin/reportes',
            ],
        ];

        foreach ($notificaciones as $notificacion) {
            NotificacionInterna::query()->firstOrCreate([
                'titulo' => $notificacion['titulo'],
                'user_id' => $notificacion['user_id'],
            ], $notificacion + ['leida' => false]);
        }
    }
}
