<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Fixtures del dominio académico para las pruebas de /api/panel/v1/academico/*.
 *
 * @mixin PanelApiTestCase
 */
trait DatosAcademicos
{
    /** @param array<string, mixed> $atributos */
    protected function curso(string $codigo, array $atributos = []): Curso
    {
        return Curso::create([
            'codigo' => $codigo, 'nombre' => "Curso {$codigo}", 'descripcion' => 'Descripción', 'categoria' => 'tecnologia',
            'duracion_horas' => 20, 'precio' => 150, 'modalidad' => 'virtual', 'horario' => 'Lun 18:00',
            'cupos_maximos' => 10, 'cupos_disponibles' => 10, 'estado' => 'activo', ...$atributos,
        ]);
    }

    /** @param array<string, mixed> $atributos */
    protected function estudiante(string $dni, array $atributos = []): Estudiante
    {
        return Estudiante::create([
            'dni' => $dni, 'nombres' => 'Nombre'.substr(md5($dni), 0, 6), 'apellidos' => 'Apellido', 'email' => "{$dni}@correo.test",
            'telefono' => '999999999', 'estado' => 'activo', ...$atributos,
        ]);
    }

    /** @param array<string, mixed> $datos */
    protected function como(User $user, string $metodo, string $ruta, array $datos = []): TestResponse
    {
        return $this->actingAs($user)
            ->withHeaders(['X-Horus-Web-Token' => self::TOKEN])
            ->json($metodo, '/api/panel/v1/academico/'.$ruta, $datos);
    }
}
