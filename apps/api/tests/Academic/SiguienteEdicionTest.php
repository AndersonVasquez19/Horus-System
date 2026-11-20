<?php

declare(strict_types=1);

use App\Models\Convocatoria;
use App\Models\ConvocatoriaHorario;
use App\Models\Curso;
use App\Models\User;
use App\Support\CodigoSecuencial;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['app.url' => 'http://localhost', 'horus-web.service_token' => str_repeat('a', 32)]);
});

it('crea la siguiente edición copiando curso, modalidad, cupos y horarios', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $curso = Curso::create([
        'codigo' => 'CUR-SIG-01',
        'nombre' => 'Curso de Prueba',
        'categoria' => 'Tecnología',
        'descripcion' => 'Descripción de prueba',
        'modalidad' => 'virtual',
        'horario' => 'Por definir',
        'duracion_horas' => 24,
        'precio' => 250,
        'cupos_maximos' => 30,
        'cupos_disponibles' => 30,
        'estado' => 'activo',
    ]);

    $origen = new Convocatoria([
        'nombre' => 'Edición Marzo',
        'modalidad' => 'virtual',
        'fecha_inicio' => '2026-03-01',
        'fecha_fin' => '2026-03-31',
        'cupos_maximos' => 30,
    ]);
    $origen->identificador = (string) Str::uuid();
    $origen->codigo = CodigoSecuencial::siguiente('CNV');
    $origen->curso_id = $curso->id;
    $origen->estado = 'cerrada';
    $origen->cupos_disponibles = 5;
    $origen->revision = 1;
    $origen->save();

    $h1 = new ConvocatoriaHorario(['dia_semana' => 2, 'hora_inicio' => '19:00', 'hora_fin' => '21:00']);
    $h1->convocatoria_id = $origen->id;
    $h1->save();

    $h2 = new ConvocatoriaHorario(['dia_semana' => 4, 'hora_inicio' => '19:00', 'hora_fin' => '21:00']);
    $h2->convocatoria_id = $origen->id;
    $h2->save();

    $resp = $this->actingAs($admin)
        ->withHeaders(['X-Horus-Web-Token' => str_repeat('a', 32)])
        ->postJson("/api/panel/v1/academico/convocatorias/{$origen->identificador}/siguiente", [
            'fecha_inicio' => '2099-04-01',
            'fecha_fin' => '2099-04-30',
            'nombre' => 'Edición Abril',
            'abrir' => true,
        ]);

    $resp->assertStatus(201)
        ->assertJsonPath('data.nombre', 'Edición Abril')
        ->assertJsonPath('data.estado', 'abierta')
        ->assertJsonPath('data.modalidad', 'virtual')
        ->assertJsonPath('data.cupos.maximos', 30)
        ->assertJsonPath('data.cupos.disponibles', 30)
        ->assertJsonCount(2, 'data.horarios');
});

it('rechaza abrir siguiente edición sin permiso convocatorias.crear', function (): void {
    $lector = User::factory()->create();
    $lector->givePermissionTo(['panel.academico.acceder', 'convocatorias.ver']);

    $curso = Curso::create([
        'codigo' => 'CUR-SIG-02',
        'nombre' => 'Curso de Prueba 2',
        'categoria' => 'Tecnología',
        'descripcion' => 'Descripción de prueba',
        'modalidad' => 'presencial',
        'horario' => 'Por definir',
        'duracion_horas' => 24,
        'precio' => 250,
        'cupos_maximos' => 20,
        'cupos_disponibles' => 20,
        'estado' => 'activo',
    ]);

    $origen = new Convocatoria([
        'modalidad' => 'presencial',
        'fecha_inicio' => '2099-03-01',
        'fecha_fin' => '2099-03-31',
        'cupos_maximos' => 20,
    ]);
    $origen->identificador = (string) Str::uuid();
    $origen->codigo = 'CNV-2026-0002';
    $origen->curso_id = $curso->id;
    $origen->estado = 'abierta';
    $origen->cupos_disponibles = 20;
    $origen->revision = 1;
    $origen->save();

    $this->actingAs($lector)
        ->withHeaders(['X-Horus-Web-Token' => str_repeat('a', 32)])
        ->postJson("/api/panel/v1/academico/convocatorias/{$origen->identificador}/siguiente", [
            'fecha_inicio' => '2099-05-01',
            'fecha_fin' => '2099-05-31',
        ])
        ->assertStatus(403);
});
