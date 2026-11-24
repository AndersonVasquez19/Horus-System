<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Convocatoria;
use App\Models\Curso;
use App\Models\Estudiante;
use App\Models\Matricula;
use App\Models\ObligacionPago;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Plan de mejora del panel, Fase 3.8: matrícula masiva por convenio desde Excel, con vista previa y confirmación.
 */

function masEdicion(int $libres = 10): Convocatoria
{
    $curso = Curso::query()->firstOrCreate(['codigo' => 'MAS-1'], ['nombre' => 'Curso convenio', 'descripcion' => 'x', 'categoria' => 'tecnologia', 'duracion_horas' => 20,
        'precio' => 300, 'modalidad' => 'virtual', 'horario' => 'Lun', 'cupos_maximos' => 50, 'cupos_disponibles' => 50, 'estado' => 'activo']);
    $c = new Convocatoria(['nombre' => null, 'modalidad' => 'virtual', 'fecha_inicio' => now()->addDays(10)->toDateString(),
        'fecha_fin' => now()->addDays(40)->toDateString(), 'cupos_maximos' => 10]);
    $c->identificador = (string) Str::uuid();
    $c->codigo = 'CNV-2026-'.random_int(1000, 9999);
    $c->curso_id = $curso->id;
    $c->estado = 'abierta';
    $c->cupos_disponibles = $libres;
    $c->save();

    return $c;
}

/** @param list<list<string|int|null>> $filas incluye la fila de encabezados */
function masPlanilla(array $filas, string $nombre = 'convenio.xlsx'): UploadedFile
{
    $libro = new Spreadsheet;
    $hoja = $libro->getActiveSheet();
    foreach ($filas as $i => $fila) {
        foreach ($fila as $j => $valor) {
            if ($valor === null) {
                continue;
            }
            $coordenada = chr(65 + $j).($i + 1);
            is_int($valor)
                ? $hoja->setCellValueExplicit($coordenada, $valor, DataType::TYPE_NUMERIC)
                : $hoja->setCellValueExplicit($coordenada, $valor, DataType::TYPE_STRING);
        }
    }
    $ruta = tempnam(sys_get_temp_dir(), 'mas').'.xlsx';
    (new Xlsx($libro))->save($ruta);

    return new UploadedFile($ruta, $nombre, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

function masEnviar(object $t, ?User $u, string $ruta, array $cuerpo)
{
    config(['horus-web.service_token' => 'token-de-servicio-del-bff-para-pruebas-0123456789']);
    $cabeceras = ['X-Horus-Web-Token' => 'token-de-servicio-del-bff-para-pruebas-0123456789', 'Accept' => 'application/json'];

    return (fn () => ($u === null ? $this : $this->actingAs($u))->post('/api/panel/v1/'.$ruta, $cuerpo, $cabeceras))->call($t);
}

const MAS_CAB = ['dni', 'nombres', 'apellidos', 'telefono', 'email'];

it('la vista previa clasifica cada fila y no escribe nada', function (): void {
    $c = masEdicion(10);
    $admin = $this->usuarioConRol('admin');
    $existente = Estudiante::create(['dni' => '70000001', 'nombres' => 'Ya', 'apellidos' => 'Existe', 'estado' => 'activo']);
    $inactivo = Estudiante::create(['dni' => '70000002', 'nombres' => 'In', 'apellidos' => 'Activo', 'estado' => 'inactivo']);
    $yaMat = Estudiante::create(['dni' => '70000003', 'nombres' => 'Ya', 'apellidos' => 'Matriculado', 'estado' => 'activo']);
    Matricula::create(['estudiante_id' => $yaMat->id, 'curso_id' => $c->curso_id, 'convocatoria_id' => $c->id, 'fecha_matricula' => now()->toDateString(), 'estado' => 'pendiente', 'monto_pagado' => 0]);

    $archivo = masPlanilla([
        MAS_CAB,
        ['70000001', null, null, null, null],                       // existente → matricular
        ['70000002', null, null, null, null],                       // inactivo → error
        ['70000003', null, null, null, null],                       // ya matriculado → omitir
        ['71111111', 'Nuevo', 'Alumno', '987654321', 'nuevo@example.test'], // nuevo → crear y matricular
        ['123', 'Corto', 'Dni', null, null],                        // DNI inválido → error
        ['71111111', 'Repetido', 'Alumno', null, null],             // repetido → error
        ['72222222', null, null, null, null],                       // nuevo sin nombres → error
        ['00000000', 'Cero', 'Dni', null, null],                    // cero → error
        [null, null, null, null, null],                             // fila vacía, se ignora
    ]);

    $antes = [Estudiante::query()->count(), Matricula::query()->count()];
    $r = masEnviar($this, $admin, "academico/convocatorias/{$c->identificador}/matricula-masiva/vista-previa", ['archivo' => $archivo])->assertOk();

    expect($r->json('data.resumen'))->toBe(['matricular' => 1, 'crear_y_matricular' => 1, 'omitir' => 1, 'sin_cupo' => 0, 'error' => 5]);
    expect(collect($r->json('data.filas'))->pluck('accion')->all())->toBe(['matricular', 'error', 'omitir', 'crear_y_matricular', 'error', 'error', 'error', 'error']);
    expect([Estudiante::query()->count(), Matricula::query()->count()])->toBe($antes);
    expect($existente->fresh()->nombres)->toBe('Ya');
});

it('confirmar matricula y crea estudiantes, deja constancia sin DNIs y repetir no duplica', function (): void {
    $c = masEdicion(10);
    $admin = $this->usuarioConRol('admin');
    Estudiante::create(['dni' => '70000001', 'nombres' => 'Ya', 'apellidos' => 'Existe', 'estado' => 'activo']);
    $filas = [MAS_CAB, ['70000001', null, null, null, null], ['71111111', 'Nuevo', 'Alumno', '987 654 321', 'Nuevo@Example.test']];
    $url = "academico/convocatorias/{$c->identificador}/matricula-masiva";

    $r = masEnviar($this, $admin, $url, ['archivo' => masPlanilla($filas)])->assertOk();
    expect($r->json('data.resumen.matriculadas'))->toBe(2)->and($r->json('data.resumen.estudiantes_creados'))->toBe(1);
    $nuevo = Estudiante::query()->where('dni', '71111111')->firstOrFail();
    expect($nuevo->email)->toBe('nuevo@example.test')->and($nuevo->estado)->toBe('activo');
    expect(Matricula::query()->where('convocatoria_id', $c->id)->where('estado', 'pendiente')->count())->toBe(2)
        ->and($c->fresh()->cupos_disponibles)->toBe(8)
        ->and(ObligacionPago::query()->count())->toBe(0);
    $auditoria = AuditLog::query()->where('action', 'matricula_masiva')->firstOrFail();
    expect(json_encode($auditoria->changes))->not->toContain('70000001')->not->toContain('71111111');

    $otra = masEnviar($this, $admin, $url, ['archivo' => masPlanilla($filas)])->assertOk();
    expect($otra->json('data.resumen.matriculadas'))->toBe(0)->and($otra->json('data.resumen.omitir'))->toBe(2);
    expect(Matricula::query()->where('convocatoria_id', $c->id)->count())->toBe(2)->and($c->fresh()->cupos_disponibles)->toBe(8);
});

it('respeta los cupos en el orden del archivo', function (): void {
    $c = masEdicion(2);
    $admin = $this->usuarioConRol('admin');
    $filas = [MAS_CAB, ['71000001', 'Uno', 'Alumno', null, null], ['71000002', 'Dos', 'Alumno', null, null], ['71000003', 'Tres', 'Alumno', null, null]];

    $previa = masEnviar($this, $admin, "academico/convocatorias/{$c->identificador}/matricula-masiva/vista-previa", ['archivo' => masPlanilla($filas)])->assertOk();
    expect(collect($previa->json('data.filas'))->pluck('accion')->all())->toBe(['crear_y_matricular', 'crear_y_matricular', 'sin_cupo']);

    $r = masEnviar($this, $admin, "academico/convocatorias/{$c->identificador}/matricula-masiva", ['archivo' => masPlanilla($filas)])->assertOk();
    expect($r->json('data.resumen.matriculadas'))->toBe(2)->and($r->json('data.resumen.sin_cupo'))->toBe(1);
    expect(Estudiante::query()->where('dni', '71000003')->exists())->toBeFalse()->and($c->fresh()->cupos_disponibles)->toBe(0);
});

it('un DNI guardado como número recupera los ceros de la izquierda', function (): void {
    $c = masEdicion();
    Estudiante::create(['dni' => '01234567', 'nombres' => 'Cero', 'apellidos' => 'Inicial', 'estado' => 'activo']);

    $r = masEnviar($this, $this->usuarioConRol('admin'), "academico/convocatorias/{$c->identificador}/matricula-masiva/vista-previa", ['archivo' => masPlanilla([MAS_CAB, [1234567]])])->assertOk();

    expect($r->json('data.filas.0.accion'))->toBe('matricular')->and($r->json('data.filas.0.dni'))->toBe('01234567');
});

it('la obligación solo se crea si se pide y se tiene permiso', function (): void {
    $c = masEdicion();
    $admin = $this->usuarioConRol('admin');
    Estudiante::create(['dni' => '70000001', 'nombres' => 'Ya', 'apellidos' => 'Existe', 'estado' => 'activo']);

    masEnviar($this, $admin, "academico/convocatorias/{$c->identificador}/matricula-masiva", ['archivo' => masPlanilla([MAS_CAB, ['70000001']]), 'crear_obligacion' => '1'])->assertOk();
    expect(ObligacionPago::query()->count())->toBe(1);

    $secretaria = $this->usuarioConRol('secretaria', 'sec@example.test');
    $otra = masEdicion();
    Estudiante::create(['dni' => '70000009', 'nombres' => 'Otro', 'apellidos' => 'Alumno', 'estado' => 'activo']);
    masEnviar($this, $secretaria, "academico/convocatorias/{$otra->identificador}/matricula-masiva", ['archivo' => masPlanilla([MAS_CAB, ['70000009']]), 'crear_obligacion' => '1'])->assertForbidden();
});

it('sin permiso de crear estudiantes, los DNI nuevos quedan como error', function (): void {
    $c = masEdicion();
    $u = $this->usuarioConRol('secretaria');
    $rol = Role::findByName('secretaria');
    $rol->revokePermissionTo('estudiantes.crear');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $r = masEnviar($this, $u->fresh(), "academico/convocatorias/{$c->identificador}/matricula-masiva/vista-previa", ['archivo' => masPlanilla([MAS_CAB, ['71000001', 'Nuevo', 'Alumno']])])->assertOk();

    expect($r->json('data.puede_crear_estudiantes'))->toBeFalse()->and($r->json('data.filas.0.accion'))->toBe('error');
});

it('rechaza archivos que no son una planilla válida, sin encabezado dni o con demasiadas filas', function (): void {
    $c = masEdicion();
    $admin = $this->usuarioConRol('admin');
    $url = "academico/convocatorias/{$c->identificador}/matricula-masiva/vista-previa";

    masEnviar($this, $admin, $url, ['archivo' => UploadedFile::fake()->createWithContent('falso.xlsx', 'no soy un zip')])->assertStatus(422)->assertJsonValidationErrors('archivo');
    masEnviar($this, $admin, $url, ['archivo' => UploadedFile::fake()->create('datos.csv', 1, 'text/csv')])->assertStatus(422)->assertJsonValidationErrors('archivo');
    masEnviar($this, $admin, $url, [])->assertStatus(422)->assertJsonValidationErrors('archivo');
    masEnviar($this, $admin, $url, ['archivo' => masPlanilla([['nombres', 'apellidos'], ['A', 'B']])])->assertStatus(422)->assertJsonValidationErrors('archivo');
    masEnviar($this, $admin, $url, ['archivo' => masPlanilla([MAS_CAB])])->assertStatus(422)->assertJsonValidationErrors('archivo');

    $muchas = [MAS_CAB];
    for ($i = 0; $i < 201; $i++) {
        $muchas[] = [sprintf('%08d', 60000000 + $i)];
    }
    masEnviar($this, $admin, $url, ['archivo' => masPlanilla($muchas)])->assertStatus(422)->assertJsonValidationErrors('archivo');
});

it('la edición debe admitir matrículas y el acceso exige matriculas.crear', function (): void {
    $c = masEdicion();
    $url = "academico/convocatorias/{$c->identificador}/matricula-masiva/vista-previa";
    $archivo = fn () => masPlanilla([MAS_CAB, ['70000001']]);

    masEnviar($this, null, $url, ['archivo' => $archivo()])->assertUnauthorized();
    $c->forceFill(['estado' => 'cerrada'])->save();
    masEnviar($this, $this->usuarioConRol('admin'), $url, ['archivo' => $archivo()])->assertStatus(409);

    $this->panelComo($this->usuarioConRol('admin', 'otro@example.test'), 'GET', 'academico/matriculas/masiva/plantilla')->assertOk();
});
