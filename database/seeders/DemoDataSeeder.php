<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Datos de ejemplo para bases locales desechables. No crea usuarios ni contraseñas.
 * Si existen cuentas con los correos de ejemplo, los procesos y aprobaciones se vinculan a ellas.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('DemoDataSeeder no se ejecuta en producción.');
        }

        $this->call([
            RolesAndPermissionsSeeder::class,
            ProcesosInternosSeeder::class,
            AprobacionesInternasSeeder::class,
            CursoSeeder::class,
            EstudianteSeeder::class,
            MatriculaSeeder::class,
        ]);
    }
}
