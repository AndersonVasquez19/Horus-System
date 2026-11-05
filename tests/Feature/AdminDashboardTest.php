<?php

namespace Tests\Feature;

use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_displays_current_academic_totals_and_recent_records(): void
    {
        $estudiante = Estudiante::create([
            'dni' => '70123456',
            'nombres' => 'Lucía',
            'apellidos' => 'Pérez Soto',
            'estado' => 'activo',
        ]);

        $curso = Curso::create([
            'codigo' => 'CUR-TEST',
            'nombre' => 'Curso de prueba',
            'descripcion' => 'Descripción del curso de prueba.',
            'categoria' => 'tecnologia',
            'duracion_horas' => 20,
            'precio' => 150,
            'modalidad' => 'virtual',
            'horario' => 'Lunes, 18:00-20:00',
            'cupos_maximos' => 10,
            'cupos_disponibles' => 10,
            'estado' => 'activo',
        ]);

        Matricula::create([
            'estudiante_id' => $estudiante->id,
            'curso_id' => $curso->id,
            'fecha_matricula' => now()->toDateString(),
            'estado' => 'pagada',
            'monto_pagado' => 150,
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Estudiantes activos')
            ->assertSee('Cursos activos')
            ->assertSee('Matrículas del mes')
            ->assertSee('S/ 150.00')
            ->assertSee('Lucía Pérez Soto')
            ->assertSee('Curso de prueba');
    }

    // --- V-03: los ingresos del mes son exclusivos de Gerencia (rol admin) --------------------------------------

    /** Matrícula pagada del mes en curso por S/ 275.50 (importe distinto del precio del curso, S/ 300). */
    private function registrarMatriculaPagadaDelMes(): Matricula
    {
        $estudiante = Estudiante::create(['dni' => '70999888', 'nombres' => 'Rosa', 'apellidos' => 'Vigo Llanos', 'estado' => 'activo']);
        $curso = Curso::create([
            'codigo' => 'CUR-V03', 'nombre' => 'Curso V03', 'descripcion' => 'Curso de prueba V03.', 'categoria' => 'tecnologia',
            'duracion_horas' => 10, 'precio' => 300, 'modalidad' => 'virtual', 'horario' => 'Martes, 18:00-20:00',
            'cupos_maximos' => 10, 'cupos_disponibles' => 10, 'estado' => 'activo',
        ]);

        return Matricula::create([
            'estudiante_id' => $estudiante->id, 'curso_id' => $curso->id, 'fecha_matricula' => now()->toDateString(),
            'estado' => 'pagada', 'monto_pagado' => 275.50, 'observaciones' => 'Observación interna de pago V03',
        ]);
    }

    private function usuarioConRol(string $rol): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $usuario = User::factory()->create();
        $usuario->assignRole($rol);

        return $usuario;
    }

    /**
     * Consultas SQL del Dashboard para el usuario dado.
     *
     * @return list<string>
     */
    private function consultasDelDashboard(User $usuario, ?TestResponse &$respuesta = null): array
    {
        $this->actingAs($usuario);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $respuesta = $this->get(route('admin.dashboard'));
        $consultas = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $consultas;
    }

    public function test_gerencia_recibe_y_visualiza_los_ingresos_del_mes(): void
    {
        $this->registrarMatriculaPagadaDelMes();

        $consultas = $this->consultasDelDashboard($this->usuarioConRol('admin'), $respuesta);

        $respuesta->assertOk()
            ->assertSee('Ingresos del mes')
            ->assertSee('S/ 275.50')
            ->assertViewHas('ingresosMes', fn ($valor) => $valor !== null && (float) $valor === 275.5)
            ->assertViewHas('matriculasMes', 1);
        // El agregado financiero se ejecuta una sola vez y solo para Gerencia.
        $sumas = array_filter($consultas, fn (string $sql) => preg_match('/\bsum\(/i', $sql) === 1 && str_contains($sql, 'monto_pagado'));
        $this->assertCount(1, $sumas);
    }

    /** @return array<string, array{string}> */
    public static function rolesSinAccesoFinanciero(): array
    {
        return ['coordinación' => ['coordinador'], 'secretaría' => ['secretaria']];
    }

    #[DataProvider('rolesSinAccesoFinanciero')]
    public function test_el_rol_accede_al_dashboard_sin_recibir_ni_calcular_ingresos(string $rol): void
    {
        $this->registrarMatriculaPagadaDelMes();

        $consultas = $this->consultasDelDashboard($this->usuarioConRol($rol), $respuesta);

        // Acceso conservado.
        $respuesta->assertOk()->assertViewIs('admin.dashboard');
        // Backend: el dato no se entrega y el agregado financiero no se ejecuta.
        // assertViewHas($clave, null) solo comprueba que la clave existe: se exige la clave Y el valor null estricto.
        $respuesta->assertViewHas('ingresosMes', fn ($valor) => $valor === null);
        $this->assertNull($respuesta->viewData('ingresosMes'));
        $this->assertSame([], array_values(array_filter($consultas, fn (string $sql) => preg_match('/\bsum\(/i', $sql) === 1)), 'No debe ejecutarse ningún SUM.');
        $this->assertSame([], array_values(array_filter($consultas, fn (string $sql) => str_contains($sql, 'monto_pagado'))), 'Ninguna consulta debe nombrar monto_pagado.');
        // Vista: ni la tarjeta ni el importe.
        $respuesta->assertDontSee('Ingresos del mes')
            ->assertDontSee('S/ 275.50')
            ->assertDontSee('275.50')
            ->assertDontSee('Recaudación de matrículas pagadas')
            ->assertDontSee('fa-sack-dollar', false);
        // Regresión: el resto del Dashboard se conserva.
        $respuesta->assertViewHas('matriculasMes', 1)
            ->assertViewHas('estudiantesActivos', 1)
            ->assertViewHas('cursosActivos', 1)
            ->assertSee('Estudiantes activos')
            ->assertSee('Cursos activos')
            ->assertSee('Matrículas del mes')
            ->assertSee('Matrículas de los últimos 6 meses')
            ->assertSee('Rosa Vigo Llanos')
            ->assertSee('Curso V03');
        $this->assertSame(1, $respuesta->viewData('meses')->last()['total'], 'El gráfico conserva la matrícula del mes.');
        $this->assertCount(6, $respuesta->viewData('meses'));
    }

    // --- V-03 (minimización): «Últimas matrículas» sin atributos financieros para ningún rol ----------------------

    /** @return array<string, array{string}> */
    public static function rolesDelPanel(): array
    {
        return ['gerencia' => ['admin'], 'coordinación' => ['coordinador'], 'secretaría' => ['secretaria']];
    }

    #[DataProvider('rolesDelPanel')]
    public function test_las_ultimas_matriculas_solo_cargan_las_columnas_que_muestra_la_vista(string $rol): void
    {
        $matricula = $this->registrarMatriculaPagadaDelMes();

        $consultas = $this->consultasDelDashboard($this->usuarioConRol($rol), $respuesta);
        $respuesta->assertOk();

        $recientes = $respuesta->viewData('ultimasMatriculas');
        $this->assertCount(1, $recientes);
        $reciente = $recientes->first();
        $this->assertInstanceOf(Matricula::class, $reciente);

        // Atributos reales del modelo: exactamente los que usa la tabla y las claves de las relaciones.
        $this->assertEqualsCanonicalizing(['id', 'estudiante_id', 'curso_id', 'estado', 'fecha_matricula'], array_keys($reciente->getAttributes()));
        $this->assertArrayNotHasKey('monto_pagado', $reciente->getAttributes());
        $this->assertArrayNotHasKey('observaciones', $reciente->getAttributes());
        $this->assertNull($reciente->monto_pagado);

        // Relaciones necesarias cargadas (sin consultas diferidas) y reducidas a lo que se muestra.
        $this->assertTrue($reciente->relationLoaded('estudiante'));
        $this->assertTrue($reciente->relationLoaded('curso'));
        $this->assertEqualsCanonicalizing(['id', 'nombres', 'apellidos'], array_keys($reciente->estudiante->getAttributes()));
        $this->assertEqualsCanonicalizing(['id', 'nombre'], array_keys($reciente->curso->getAttributes()));
        $this->assertArrayNotHasKey('precio', $reciente->curso->getAttributes());
        $this->assertNull($reciente->curso->precio);

        // Los valores mostrados siguen siendo correctos.
        $this->assertSame($matricula->id, $reciente->id);
        $this->assertSame('pagada', $reciente->estado);
        $this->assertSame(now()->toDateString(), $reciente->fecha_matricula->toDateString());
        $this->assertSame('Rosa', $reciente->estudiante->nombres);
        $this->assertSame('Curso V03', $reciente->curso->nombre);

        // La serialización (por ejemplo, JSON en una vista futura) tampoco contiene datos financieros.
        $serializado = json_encode($recientes->toArray());
        $this->assertIsString($serializado);
        foreach (['monto_pagado', 'precio', '275.5', '300.00', 'Observación interna de pago V03'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $serializado, "{$rol}: {$prohibido}");
        }

        // SQL: ninguna consulta de la tabla reciente pide «*» ni columnas financieras de matrículas o cursos.
        $deCursos = array_values(array_filter($consultas, fn (string $sql) => str_contains($sql, 'from "cursos"') && ! str_contains($sql, 'count(')));
        $this->assertNotEmpty($deCursos);
        foreach ($deCursos as $sql) {
            $this->assertStringNotContainsString('*', $sql, $sql);
            $this->assertStringNotContainsString('precio', $sql, $sql);
        }
        $recientesSql = array_values(array_filter($consultas, fn (string $sql) => str_contains($sql, 'from "matriculas"') && str_contains($sql, 'order by "fecha_matricula" desc')));
        $this->assertCount(1, $recientesSql);
        $this->assertStringNotContainsString('*', $recientesSql[0]);
        $this->assertStringNotContainsString('monto_pagado', $recientesSql[0]);

        // Agregado financiero: solo Gerencia lo conserva; los demás reciben null estricto.
        if ($rol === 'admin') {
            $respuesta->assertViewHas('ingresosMes', fn ($valor) => $valor !== null && (float) $valor === 275.5)->assertSee('S/ 275.50');
        } else {
            $this->assertNull($respuesta->viewData('ingresosMes'));
            $respuesta->assertDontSee('275.50');
        }
        // La tabla visible no cambia para ningún rol.
        $respuesta->assertSee('Últimas matrículas')->assertSee('Rosa Vigo Llanos')->assertSee('Curso V03')
            ->assertSee(now()->format('d/m/Y'));
    }

    public function test_las_ultimas_matriculas_conservan_cursos_y_estudiantes_eliminados_logicamente(): void
    {
        $matricula = $this->registrarMatriculaPagadaDelMes();
        $matricula->curso->delete();
        $matricula->estudiante->delete();

        $this->actingAs($this->usuarioConRol('secretaria'))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Rosa Vigo Llanos')
            ->assertSee('Curso V03')
            ->assertViewHas('ultimasMatriculas', fn ($recientes) => $recientes->first()->curso->nombre === 'Curso V03'
                && ! array_key_exists('precio', $recientes->first()->curso->getAttributes()));
    }
}
