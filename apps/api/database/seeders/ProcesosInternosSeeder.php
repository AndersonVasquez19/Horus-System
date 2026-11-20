<?php

namespace Database\Seeders;

use App\Models\NotificacionInterna;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Nombre histórico conservado para los avisos de ejemplo; ya no crea procesos. */
class ProcesosInternosSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('email', 'admin@horusgroup.com')->first();
        $coordinador = User::where('email', 'coordinador@horusgroup.com')->first();

        $notificaciones = [
            [
                'user_id' => $admin?->id,
                'audiencia' => NotificacionInterna::AUDIENCIA_PERSONAL,
                'titulo' => 'Revisión de matrículas',
                'mensaje' => 'Revisión de matrículas pendientes requiere atención hoy.',
                'tipo' => 'warning',
                'vinculo' => '/admin/matriculas',
            ],
            [
                'user_id' => $coordinador?->id,
                'audiencia' => NotificacionInterna::AUDIENCIA_PERSONAL,
                'titulo' => 'Revisión de cupos',
                'mensaje' => 'Se ha iniciado la revisión de cupos por curso.',
                'tipo' => 'info',
                'vinculo' => '/admin/cursos',
            ],
            [
                'user_id' => null,
                'audiencia' => NotificacionInterna::AUDIENCIA_GENERAL,
                'titulo' => 'Cierre mensual',
                'mensaje' => 'El reporte mensual finalizó correctamente.',
                'tipo' => 'success',
                'vinculo' => '/admin/reportes',
            ],
        ];

        foreach ($notificaciones as $notificacion) {
            // Audiencia explícita: una personal sin destinatario se omite; nunca se degrada a general.
            $audiencia = $notificacion['audiencia'];
            if ($audiencia === NotificacionInterna::AUDIENCIA_PERSONAL && $notificacion['user_id'] === null) {
                continue;
            }

            NotificacionInterna::query()->firstOrCreate([
                'titulo' => $notificacion['titulo'],
                'user_id' => $notificacion['user_id'],
                'audiencia' => $audiencia,
            ], $notificacion + ['leida' => false]);
        }
    }
}
