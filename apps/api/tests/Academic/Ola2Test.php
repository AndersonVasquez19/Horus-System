<?php

declare(strict_types=1);

use App\Models\Convocatoria;
use App\Models\CuotaObligacion;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\MetodoPago;
use App\Models\ObligacionPago;
use App\Models\TransaccionPago;
use App\Models\User;
use App\Services\Panel\BusquedaAcademica;
use App\Services\Panel\ResumenAcademico;
use App\Support\CodigoSecuencial;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['app.url' => 'http://localhost', 'horus-web.service_token' => str_repeat('b', 32)]);
});

it('busca estudiantes por teléfono y pagos por referencia Yape', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $est = Estudiante::create([
        'dni' => '70112233',
        'nombres' => 'Lucía',
        'apellidos' => 'Quispe',
        'telefono' => '987654321',
        'estado' => 'activo',
    ]);
    $curso = Curso::create([
        'codigo' => 'CUR-OLA2-01',
        'nombre' => 'Excel Avanzado',
        'categoria' => 'Ofimática',
        'descripcion' => 'Curso prueba',
        'modalidad' => 'virtual',
        'horario' => 'Lun y Mie',
        'duracion_horas' => 20,
        'precio' => 200,
        'cupos_maximos' => 20,
        'cupos_disponibles' => 19,
        'estado' => 'activo',
    ]);
    $mat = Matricula::create([
        'estudiante_id' => $est->id,
        'curso_id' => $curso->id,
        'estado' => 'pagada',
        'fecha_matricula' => '2026-11-10',
    ]);
    $metodo = MetodoPago::firstOrCreate(['codigo' => 'yape'], ['nombre' => 'Yape', 'requiere_referencia' => true, 'requiere_comprobante' => true, 'activo' => true]);
    $ob = ObligacionPago::create([
        'identificador' => (string) Str::uuid(),
        'matricula_id' => $mat->id,
        'concepto' => 'Matrícula Excel',
        'plan' => 'completo',
        'estado' => 'vigente',
        'moneda' => 'PEN',
        'monto_total' => 200,
        'creado_por' => $admin->id,
    ]);
    TransaccionPago::create([
        'identificador' => (string) Str::uuid(),
        'obligacion_pago_id' => $ob->id,
        'metodo_pago_id' => $metodo->id,
        'tipo' => 'pago',
        'estado' => 'confirmada',
        'monto' => 200,
        'fecha_pago' => '2026-11-10',
        'referencia' => 'YAPE-998877',
        'registrado_por' => $admin->id,
    ]);

    $busqueda = app(BusquedaAcademica::class);
    $porTel = $busqueda->buscar($admin, '987654321');
    expect($porTel['estudiantes'])->toHaveCount(1);

    $porRef = $busqueda->buscar($admin, '998877');
    expect($porRef['pagos'])->toHaveCount(1);
    expect($porRef['pagos'][0]['identificador'])->toBe($ob->identificador);

    $this->actingAs($admin)
        ->withHeaders(['X-Horus-Web-Token' => str_repeat('b', 32)])
        ->getJson('/api/panel/v1/academico/pagos/verificar-referencia?referencia=yape-998877')
        ->assertOk()
        ->assertJsonPath('data.duplicada', true)
        ->assertJsonCount(1, 'data.coincidencias');

    $this->actingAs($admin)
        ->withHeaders(['X-Horus-Web-Token' => str_repeat('b', 32)])
        ->getJson('/api/panel/v1/academico/pagos/verificar-referencia?referencia=NO-EXISTE')
        ->assertOk()
        ->assertJsonPath('data.duplicada', false);
});

it('incluye salud_grupo en ResumenAcademico con cuotas vencidas y faltas seguidas', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $est = Estudiante::create([
        'dni' => '70998877',
        'nombres' => 'Carlos',
        'apellidos' => 'Mendoza',
        'telefono' => '912345678',
        'estado' => 'activo',
    ]);
    $curso = Curso::create([
        'codigo' => 'CUR-OLA2-02',
        'nombre' => 'Power BI',
        'categoria' => 'Datos',
        'descripcion' => 'Curso prueba',
        'modalidad' => 'virtual',
        'horario' => 'Mar y Jue',
        'duracion_horas' => 20,
        'precio' => 300,
        'cupos_maximos' => 20,
        'cupos_disponibles' => 19,
        'estado' => 'activo',
    ]);
    $conv = new Convocatoria([
        'modalidad' => 'virtual',
        'fecha_inicio' => '2026-10-01',
        'fecha_fin' => '2099-12-31',
        'cupos_maximos' => 20,
    ]);
    $conv->identificador = (string) Str::uuid();
    $conv->codigo = CodigoSecuencial::siguiente('CNV');
    $conv->curso_id = $curso->id;
    $conv->estado = 'en_curso';
    $conv->cupos_disponibles = 19;
    $conv->revision = 1;
    $conv->save();

    $mat = Matricula::create([
        'estudiante_id' => $est->id,
        'curso_id' => $curso->id,
        'convocatoria_id' => $conv->id,
        'estado' => 'pendiente',
        'fecha_matricula' => '2026-10-05',
    ]);

    $ob = ObligacionPago::create([
        'identificador' => (string) Str::uuid(),
        'matricula_id' => $mat->id,
        'concepto' => 'Power BI',
        'plan' => 'fraccionado',
        'estado' => 'vigente',
        'moneda' => 'PEN',
        'monto_total' => 300,
        'creado_por' => $admin->id,
    ]);
    CuotaObligacion::create([
        'obligacion_pago_id' => $ob->id,
        'numero' => 1,
        'monto' => 150,
        'vence' => '2020-01-15',
    ]);

    $resumen = app(ResumenAcademico::class)->para($admin);
    expect($resumen['salud_grupo']['cuotas_vencidas'])->toHaveCount(1);
    expect($resumen['salud_grupo'])->not->toHaveKey('inasistencias');
});
