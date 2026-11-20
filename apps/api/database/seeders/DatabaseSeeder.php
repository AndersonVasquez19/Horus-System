<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Inicialización base y repetible: roles, permisos, configuración e intenciones del chat.
     * No crea usuarios ni contraseñas. Las cuentas del panel se crean con `php artisan horus:crear-usuario`
     * y los datos de ejemplo con `php artisan db:seed --class=DemoDataSeeder` (solo bases locales desechables).
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            SystemSettingsSeeder::class,
            ChatIntentSeeder::class,
        ]);
    }
}
