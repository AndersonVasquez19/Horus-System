<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SystemSetting;
use App\Support\ConfiguracionSistema;
use Illuminate\Database\Seeder;

class SystemSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            'nombre_empresa' => ['value' => ConfiguracionSistema::PREDETERMINADOS['nombre_empresa'], 'description' => 'Nombre visible de la empresa en el panel'],
            'telefono' => ['value' => ConfiguracionSistema::PREDETERMINADOS['telefono'], 'description' => 'Número de contacto'],
            'correo_contacto' => ['value' => ConfiguracionSistema::PREDETERMINADOS['correo_contacto'], 'description' => 'Correo principal'],
            'direccion' => ['value' => ConfiguracionSistema::PREDETERMINADOS['direccion'], 'description' => 'Dirección general'],
            'moneda' => ['value' => ConfiguracionSistema::PREDETERMINADOS['moneda'], 'description' => 'Símbolo monetario'],
            // Los secretos (Resend, OpenAI, JWT) viven en variables de entorno, nunca en esta tabla.
        ];

        // firstOrCreate: repetir el seeder no sobrescribe valores editados desde el panel.
        foreach ($settings as $key => $data) {
            SystemSetting::firstOrCreate(
                ['key' => $key],
                [
                    'value' => $data['value'],
                    'description' => $data['description'],
                ],
            );
        }
    }
}
