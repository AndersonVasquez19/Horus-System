<?php

namespace Database\Seeders;

use App\Models\Estudiante;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class EstudianteSeeder extends Seeder
{
    public function run(): void
    {
        $estudiantes = [
            ['dni' => '70000001', 'nombres' => 'María Fernanda', 'apellidos' => 'Vásquez Rojas', 'email' => 'maria.vasquez@example.com', 'telefono' => '987654321', 'fecha_nacimiento' => Carbon::parse('1998-04-12'), 'direccion' => 'Jr. Amazonas 245, Cajamarca'],
            ['dni' => '70000002', 'nombres' => 'Luis Alberto', 'apellidos' => 'Cabanillas Díaz', 'email' => 'luis.cabanillas@example.com', 'telefono' => '976543210', 'fecha_nacimiento' => Carbon::parse('1995-11-03'), 'direccion' => 'Av. Atahualpa 510, Cajamarca'],
            ['dni' => '70000003', 'nombres' => 'Ana Lucía', 'apellidos' => 'Paredes Silva', 'email' => 'ana.paredes@example.com', 'telefono' => '965432109', 'fecha_nacimiento' => Carbon::parse('2001-07-21'), 'direccion' => 'Jr. El Inca 183, Cajamarca'],
            ['dni' => '70000004', 'nombres' => 'José Manuel', 'apellidos' => 'Rojas Mendoza', 'email' => 'jose.rojas@example.com', 'telefono' => '954321098', 'fecha_nacimiento' => Carbon::parse('1992-02-16'), 'direccion' => 'Psje. San Martín 72, Cajamarca'],
            ['dni' => '70000005', 'nombres' => 'Karla Milagros', 'apellidos' => 'Cruz Huamán', 'email' => 'karla.cruz@example.com', 'telefono' => '943210987', 'fecha_nacimiento' => Carbon::parse('1999-09-08'), 'direccion' => 'Jr. Dos de Mayo 330, Cajamarca'],
            ['dni' => '70000006', 'nombres' => 'Diego Armando', 'apellidos' => 'Fernández León', 'email' => 'diego.fernandez@example.com', 'telefono' => '932109876', 'fecha_nacimiento' => Carbon::parse('1997-06-30'), 'direccion' => 'Av. Independencia 124, Cajamarca'],
            ['dni' => '70000007', 'nombres' => 'Rosa Elena', 'apellidos' => 'Chávez Ortiz', 'email' => 'rosa.chavez@example.com', 'telefono' => '921098765', 'fecha_nacimiento' => Carbon::parse('1994-12-01'), 'direccion' => 'Jr. Junín 418, Cajamarca'],
            ['dni' => '70000008', 'nombres' => 'Carlos Eduardo', 'apellidos' => 'Guevara Torres', 'email' => 'carlos.guevara@example.com', 'telefono' => '910987654', 'fecha_nacimiento' => Carbon::parse('2000-03-19'), 'direccion' => 'Jr. Lima 205, Cajamarca'],
            ['dni' => '70000009', 'nombres' => 'Patricia Isabel', 'apellidos' => 'Mendoza Salazar', 'email' => 'patricia.mendoza@example.com', 'telefono' => '989012345', 'fecha_nacimiento' => Carbon::parse('1996-08-25'), 'direccion' => 'Av. Hoyos Rubio 640, Cajamarca'],
            ['dni' => '70000010', 'nombres' => 'Miguel Ángel', 'apellidos' => 'Sánchez Díaz', 'email' => 'miguel.sanchez@example.com', 'telefono' => '978901234', 'fecha_nacimiento' => Carbon::parse('1993-05-14'), 'direccion' => 'Jr. José Gálvez 158, Cajamarca'],
        ];

        foreach ($estudiantes as $estudiante) {
            Estudiante::updateOrCreate(
                ['dni' => $estudiante['dni']],
                [...$estudiante, 'estado' => 'activo'],
            );
        }
    }
}
