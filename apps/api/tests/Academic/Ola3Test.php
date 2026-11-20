<?php

declare(strict_types=1);

use App\Models\Convocatoria;
use App\Models\ConvocatoriaHorario;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\MetodoPago;
use App\Models\ObligacionPago;
use App\Models\SesionClase;
use App\Models\TransaccionPago;
use App\Models\User;
use App\Support\CodigoSecuencial;
use App\Support\HoraLocal;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['app.url' => 'http://localhost', 'horus-web.service_token' => str_repeat('c', 32)]);
});

it('genera constancia PDF con QR, caja diaria, calendario .ics, lista aula, contactos CSV e historial PDF', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $est = Estudiante::create([
        'dni' => '71223344',
        'nombres' => 'María',
        'apellidos' => 'Paredes',
        'telefono' => '999888777',
        'estado' => 'activo',
    ]);
    $curso = Curso::create([
        'codigo' => 'CUR-OLA3-01',
        'nombre' => 'SQL Server',
        'categoria' => 'Datos',
        'descripcion' => 'Curso prueba',
        'modalidad' => 'presencial',
        'horario' => 'Sábados',
        'duracion_horas' => 24,
        'precio' => 280,
        'cupos_maximos' => 25,
        'cupos_disponibles' => 24,
        'estado' => 'activo',
    ]);
    $conv = new Convocatoria([
        'modalidad' => 'presencial',
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

    $h = new ConvocatoriaHorario(['dia_semana' => 6, 'hora_inicio' => '09:00', 'hora_fin' => '12:00']);
    $h->convocatoria_id = $conv->id;
    $h->save();

    $sesion = new SesionClase();
    $sesion->identificador = (string) Str::uuid();
    $sesion->convocatoria_id = $conv->id;
    $sesion->numero = 1;
    $sesion->fecha = '2026-11-21';
    $sesion->hora_inicio = '09:00:00';
    $sesion->hora_fin = '12:00:00';
    $sesion->tema = 'Consultas básicas';
    $sesion->estado = 'programada';
    $sesion->revision = 1;
    $sesion->save();

    $mat = Matricula::create([
        'estudiante_id' => $est->id,
        'curso_id' => $curso->id,
        'convocatoria_id' => $conv->id,
        'estado' => 'pagada',
        'fecha_matricula' => HoraLocal::hoy(),
    ]);
    $metodo = MetodoPago::firstOrCreate(['codigo' => 'yape'], ['nombre' => 'Yape', 'requiere_referencia' => true, 'requiere_comprobante' => true, 'activo' => true]);
    $ob = ObligacionPago::create([
        'identificador' => (string) Str::uuid(),
        'matricula_id' => $mat->id,
        'concepto' => 'SQL Server',
        'plan' => 'completo',
        'estado' => 'vigente',
        'moneda' => 'PEN',
        'monto_total' => 280,
        'creado_por' => $admin->id,
    ]);
    TransaccionPago::create([
        'identificador' => (string) Str::uuid(),
        'obligacion_pago_id' => $ob->id,
        'metodo_pago_id' => $metodo->id,
        'tipo' => 'pago',
        'estado' => 'confirmada',
        'monto' => 280,
        'fecha_pago' => HoraLocal::hoy(),
        'referencia' => 'YAPE-OLA3',
        'registrado_por' => $admin->id,
    ]);

    $headers = ['X-Horus-Web-Token' => str_repeat('c', 32)];

    $this->actingAs($admin)->withHeaders($headers)
        ->get("/api/panel/v1/academico/matriculas/{$mat->id}/constancia")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $this->actingAs($admin)->withHeaders($headers)
        ->getJson('/api/panel/v1/academico/pagos/caja-diaria/resumen')
        ->assertOk()
        ->assertJsonPath('data.cantidad', 1)
        ->assertJsonPath('data.total', '280.00');

    $this->actingAs($admin)->withHeaders($headers)
        ->post('/api/panel/v1/academico/pagos/caja-diaria/pdf')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $ics = $this->actingAs($admin)->withHeaders($headers)
        ->get("/api/panel/v1/academico/convocatorias/{$conv->identificador}/calendario.ics")
        ->assertOk();
    expect($ics->getContent())->toContain('BEGIN:VCALENDAR', 'Consultas básicas');

    $this->actingAs($admin)->withHeaders($headers)
        ->post("/api/panel/v1/academico/convocatorias/{$conv->identificador}/lista-aula/pdf")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $csv = $this->actingAs($admin)->withHeaders($headers)
        ->post("/api/panel/v1/academico/convocatorias/{$conv->identificador}/contactos/csv")
        ->assertOk();
    expect($csv->streamedContent())->toContain('+51999888777');

    $this->actingAs($admin)->withHeaders($headers)
        ->post("/api/panel/v1/academico/estudiantes/{$est->id}/historial/pdf")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});
