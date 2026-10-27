<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

class SystemSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            'nombre_empresa' => ['value' => 'Horus Group SRL', 'description' => 'Nombre visible de la empresa en el panel'],
            'telefono' => ['value' => '+51 987 654 321', 'description' => 'Número de contacto'],
            'correo_contacto' => ['value' => 'contacto@horusgroup.com', 'description' => 'Correo principal'],
            'direccion' => ['value' => 'Lima, Perú', 'description' => 'Dirección general'],
            'moneda' => ['value' => 'S/', 'description' => 'Símbolo monetario'],
            'api_resend' => ['value' => 'No configurada', 'description' => 'Clave API de Resend'],
        ];

        foreach ($settings as $key => $data) {
            SystemSetting::updateOrCreate(
                ['key' => $key],
                [
                    'value' => $data['value'],
                    'description' => $data['description'],
                ],
            );
        }
    }
}
