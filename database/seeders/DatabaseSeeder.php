<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class,
            RolesAndPermissionsSeeder::class,
            SystemSettingsSeeder::class,
            ProcesosInternosSeeder::class,
            AprobacionesInternasSeeder::class,
            ChatIntentSeeder::class,
            CursoSeeder::class,
            EstudianteSeeder::class,
            MatriculaSeeder::class,
        ]);
    }
}
