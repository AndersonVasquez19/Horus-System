<?php

namespace Database\Seeders;

use App\Models\AprobacionInterna;
use App\Models\User;
use Illuminate\Database\Seeder;

class AprobacionesInternasSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('email', 'admin@horusgroup.com')->first();
        $coordinador = User::where('email', 'coordinador@horusgroup.com')->first();
        $secretaria = User::where('email', 'secretaria@horusgroup.com')->first();

        $solicitudes = [
            [
                'titulo' => 'Aprobación de compra de materiales',
                'tipo' => 'compra',
                'descripcion' => 'Solicitud de adquisición de insumos para mantenimiento y atención del campus.',
                'estado' => 'pendiente',
                'solicitante_id' => $secretaria?->id,
                'aprobador_id' => $admin?->id,
                'comentario' => 'Se requiere revisión del presupuesto operacional.',
                'referencia' => 'OC-2026-041',
            ],
            [
                'titulo' => 'Cambio de horario de coordinación',
                'tipo' => 'operativo',
                'descripcion' => 'Ajuste de horarios para atender la última semana de matriculación.',
                'estado' => 'aprobado',
                'solicitante_id' => $coordinador?->id,
                'aprobador_id' => $admin?->id,
                'comentario' => 'Cambio autorizado para optimizar atención.',
                'referencia' => 'OPS-2026-019',
            ],
        ];

        foreach ($solicitudes as $solicitud) {
            AprobacionInterna::query()->firstOrCreate(
                ['titulo' => $solicitud['titulo']],
                $solicitud,
            );
        }
    }
}
