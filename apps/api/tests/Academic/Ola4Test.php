<?php

declare(strict_types=1);

use App\Models\Convocatoria;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\SesionClase;
use App\Models\User;
use App\Support\CodigoSecuencial;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['app.url' => 'http://localhost', 'horus-web.service_token' => str_repeat('d', 32)]);
});

it('gestiona etiquetas en matrícula, cronograma público sin PII y encuesta post-clase', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $est = Estudiante::create([
        'dni' => '72334455',
        'nombres' => 'Sofía',
        'apellidos' => 'Vargas',
        'telefono' => '988776655',
        'estado' => 'activo',
    ]);
    $curso = Curso::create([
        'codigo' => 'CUR-OLA4-01',
        'nombre' => 'Python para Datos',
        'categoria' => 'Datos',
        'descripcion' => 'Curso prueba',
        'modalidad' => 'virtual',
        'horario' => 'Sábados',
        'duracion_horas' => 24,
        'precio' => 300,
        'cupos_maximos' => 25,
        'cupos_disponibles' => 24,
        'estado' => 'activo',
    ]);
    $conv = new Convocatoria([
        'modalidad' => 'virtual',
        'fecha_inicio' => '2026-11-01',
        'fecha_fin' => '2099-12-31',
        'cupos_maximos' => 25,
    ]);
    $conv->identificador = (string) Str::uuid();
    $conv->codigo = CodigoSecuencial::siguiente('CNV');
    $conv->curso_id = $curso->id;
    $conv->estado = 'en_curso';
    $conv->cupos_disponibles = 24;
    $conv->revision = 1;
    $conv->save();

    $sesion = new SesionClase();
    $sesion->identificador = (string) Str::uuid();
    $sesion->convocatoria_id = $conv->id;
    $sesion->numero = 1;
    $sesion->fecha = '2026-11-22';
    $sesion->hora_inicio = '10:00:00';
    $sesion->hora_fin = '12:00:00';
    $sesion->tema = 'Introducción';
    $sesion->estado = 'programada';
    $sesion->revision = 1;
    $sesion->save();

    $mat = Matricula::create([
        'estudiante_id' => $est->id,
        'curso_id' => $curso->id,
        'convocatoria_id' => $conv->id,
        'estado' => 'pagada',
        'fecha_matricula' => '2026-11-10',
    ]);

    $headers = ['X-Horus-Web-Token' => str_repeat('d', 32)];

    $this->actingAs($admin)->withHeaders($headers)
        ->postJson("/api/panel/v1/academico/matriculas/{$mat->id}/etiqueta", ['etiqueta' => 'factura'])
        ->assertOk()
        ->assertJsonPath('data.etiqueta', 'factura');

    $cronograma = $this->withHeaders($headers)
        ->getJson("/api/public/v1/ediciones/{$conv->codigo}/cronograma")
        ->assertOk()
        ->assertJsonPath('data.codigo', $conv->codigo)
        ->assertJsonCount(1, 'data.sesiones');
    expect($cronograma->getContent())->not->toContain('Sofía', '72334455');

    $enc = $this->actingAs($admin)->withHeaders($headers)
        ->postJson("/api/panel/v1/academico/matriculas/{$mat->id}/encuesta")
        ->assertOk();
    $token = $enc->json('data.identificador');

    $this->withHeaders($headers)
        ->getJson("/api/public/v1/encuestas/{$token}")
        ->assertOk()
        ->assertJsonPath('data.respondida', false);

    $this->withHeaders($headers)
        ->postJson("/api/public/v1/encuestas/{$token}", [
            'calificacion' => 3,
            'sugerencia_curso' => 'Machine Learning',
        ])
        ->assertOk();

    $this->withHeaders($headers)
        ->postJson("/api/public/v1/encuestas/{$token}", ['calificacion' => 2])
        ->assertStatus(409);

    $this->actingAs($admin)->withHeaders($headers)
        ->getJson("/api/panel/v1/academico/convocatorias/{$conv->identificador}/encuestas")
        ->assertOk()
        ->assertJsonPath('data.total_respondidas', 1)
        ->assertJsonPath('data.feliz', 1)
        ->assertJsonPath('data.sugerencias.0', 'Machine Learning');
});
