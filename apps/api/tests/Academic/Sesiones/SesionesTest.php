<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Convocatoria;
use App\Models\Curso;
use App\Models\SesionClase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Horus Academic, Fase 5: sesiones de clase. SQLite en memoria: los CHECK de PostgreSQL no se prueban aquí. Una sesión solo lleva
 * fecha, horas y tema: docente, aula, plataforma y enlace se retiraron con los módulos Docentes e Infraestructura.
 */

/** Edición vigente con fechas amplias (de ayer a +60 días). */
function sesionEdicion(string $modalidad = 'presencial', string $estado = 'abierta'): Convocatoria
{
    static $n = 0;
    $n++;
    $curso = Curso::create(['codigo' => 'SES-'.$n, 'nombre' => 'Curso '.$n, 'descripcion' => 'x', 'categoria' => 'tecnologia', 'duracion_horas' => 10,
        'precio' => 100, 'modalidad' => $modalidad, 'horario' => 'Lun', 'cupos_maximos' => 5, 'cupos_disponibles' => 5, 'estado' => 'activo']);
    $c = new Convocatoria(['nombre' => null, 'modalidad' => $modalidad, 'fecha_inicio' => now()->subDay()->toDateString(),
        'fecha_fin' => now()->addDays(60)->toDateString(), 'cupos_maximos' => 10]);
    $c->identificador = (string) Str::uuid();
    $c->codigo = 'CNV-2026-8'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    $c->curso_id = $curso->id;
    $c->estado = $estado;
    $c->cupos_disponibles = 10;
    $c->revision = 1;
    $c->save();

    return $c;
}

/** @return array<string, mixed> */
function sesionDatos(int $dias = 5, string $inicio = '18:00', string $fin = '20:00', array $extra = []): array
{
    return ['fecha' => now()->addDays($dias)->toDateString(), 'hora_inicio' => $inicio, 'hora_fin' => $fin, 'tema' => 'Introducción', ...$extra];
}

function sesionCrear(object $test, $usuario, Convocatoria $c, array $datos): Illuminate\Testing\TestResponse
{
    return (fn () => $this->panelComo($usuario, 'POST', "academico/convocatorias/{$c->identificador}/sesiones", $datos))->call($test);
}

it('crea la tabla con número único por edición y sin docente, lugar ni enlace', function (): void {
    expect(Schema::hasTable('sesiones_clase'))->toBeTrue();
    $unicos = collect(Schema::getIndexes('sesiones_clase'))->filter(fn ($i) => $i['unique'])->pluck('columns')->all();
    expect($unicos)->toContain(['convocatoria_id', 'numero']);
    foreach (['docente_id', 'aula_id', 'plataforma_virtual_id', 'enlace_virtual'] as $columna) {
        expect(Schema::hasColumn('sesiones_clase', $columna))->toBeFalse();
    }
    foreach (['docentes', 'convocatoria_docentes', 'sedes', 'aulas', 'plataformas_virtuales'] as $tabla) {
        expect(Schema::hasTable($tabla))->toBeFalse();
    }
});

it('exige autenticación y el permiso de cada operación, y ya no hay enlace privado', function (): void {
    $edicion = sesionEdicion();
    $admin = $this->usuarioConRol('admin', 'a@example.invalid');
    $sec = $this->usuarioConRol('secretaria', 's@example.invalid');
    $coord = $this->usuarioConRol('coordinador', 'c@example.invalid');

    $this->panelComo(null, 'GET', "academico/convocatorias/{$edicion->identificador}/sesiones")->assertUnauthorized();
    sesionCrear($this, $sec, $edicion, sesionDatos())->assertForbidden();
    sesionCrear($this, $admin, $edicion, sesionDatos())->assertCreated();
    $id = SesionClase::first()->identificador;

    $this->panelComo($sec, 'GET', "academico/convocatorias/{$edicion->identificador}/sesiones")->assertOk();
    $this->panelComo($sec, 'GET', "academico/sesiones/{$id}")->assertOk();
    $this->panelComo($admin, 'GET', "academico/sesiones/{$id}/enlace")->assertNotFound();
    $this->panelComo($sec, 'POST', "academico/sesiones/{$id}/cancelar", ['motivo' => 'x'])->assertForbidden();
    $this->panelComo($sec, 'POST', "academico/sesiones/{$id}/realizada")->assertForbidden();
    $this->panelComo($sec, 'POST', "academico/convocatorias/{$edicion->identificador}/sesiones/generar")->assertForbidden();
    sesionCrear($this, $coord, $edicion, sesionDatos(6))->assertCreated();
});

it('programa una sesión con numeración automática, sin ids internos ni docente, aula o enlace', function (): void {
    $edicion = sesionEdicion();
    $admin = $this->usuarioConRol('admin');

    $r = sesionCrear($this, $admin, $edicion, sesionDatos(5))->assertCreated();
    $r->assertJsonPath('data.numero', 1)->assertJsonPath('data.hora_inicio', '18:00')->assertJsonPath('data.hora_fin', '20:00')
        ->assertJsonPath('data.estado', 'programada')->assertJsonMissingPath('data.id')->assertJsonMissingPath('data.docente')
        ->assertJsonMissingPath('data.aula')->assertJsonMissingPath('data.plataforma_virtual')->assertJsonMissingPath('data.enlace_virtual');
    expect($r->headers->get('ETag'))->toStartWith('"sesion-');
    sesionCrear($this, $admin, $edicion, sesionDatos(6))->assertJsonPath('data.numero', 2);
});

it('valida fecha, horas y número; lo que ya no existe se ignora', function (): void {
    $edicion = sesionEdicion();
    $admin = $this->usuarioConRol('admin');

    sesionCrear($this, $admin, $edicion, sesionDatos(5, '20:00', '18:00'))->assertStatus(422)->assertJsonValidationErrors('hora_fin');
    sesionCrear($this, $admin, $edicion, sesionDatos(90))->assertStatus(422)->assertJsonValidationErrors('fecha');
    sesionCrear($this, $admin, $edicion, ['fecha' => 'mañana', 'hora_inicio' => '8', 'hora_fin' => '9'])->assertStatus(422)->assertJsonValidationErrors(['fecha', 'hora_inicio', 'hora_fin']);
    sesionCrear($this, $admin, $edicion, sesionDatos(5, extra: ['numero' => 3]))->assertCreated();
    sesionCrear($this, $admin, $edicion, sesionDatos(6, extra: ['numero' => 3]))->assertStatus(422)->assertJsonValidationErrors('numero');
    // Dos sesiones a la misma hora ya no se rechazan: no hay aula ni docente que se crucen.
    sesionCrear($this, $admin, $edicion, sesionDatos(5, extra: ['numero' => 4]))->assertCreated();
    // Docente, aula y enlace se descartan sin guardarse.
    $r = sesionCrear($this, $admin, $edicion, sesionDatos(7, extra: ['docente' => (string) Str::uuid(), 'aula' => (string) Str::uuid(), 'enlace_virtual' => 'https://meet.example.test/x']))->assertCreated();
    expect($r->getContent())->not->toContain('meet.example.test');
});

it('edita con revisión: 428 sin If-Match, 412 con revisión vieja, avanza al cambiar y no al repetir', function (): void {
    $edicion = sesionEdicion();
    $admin = $this->usuarioConRol('admin');
    $crear = sesionCrear($this, $admin, $edicion, sesionDatos(5))->assertCreated();
    $id = $crear->json('data.identificador');
    $v1 = $crear->headers->get('ETag');

    $this->panelComo($admin, 'PUT', "academico/sesiones/{$id}", ['tema' => 'Otro'])->assertStatus(428);
    $this->panelComo($admin, 'PUT', "academico/sesiones/{$id}", ['tema' => 'Otro'], ['If-Match' => '"sesion-'.$id.'-v9"'])->assertStatus(412);
    $r = $this->panelComo($admin, 'PUT', "academico/sesiones/{$id}", ['tema' => 'Otro'], ['If-Match' => $v1])->assertOk()->assertJsonPath('data.tema', 'Otro');
    $v2 = $r->headers->get('ETag');
    expect($v2)->not->toBe($v1);

    $r = $this->panelComo($admin, 'PUT', "academico/sesiones/{$id}", ['tema' => 'Otro'], ['If-Match' => $v2])->assertOk();
    expect($r->headers->get('ETag'))->toBe($v2);

    // Reprogramar conserva el resto.
    $this->panelComo($admin, 'PUT', "academico/sesiones/{$id}", ['hora_inicio' => '17:00', 'hora_fin' => '19:00'], ['If-Match' => $v2])->assertOk()->assertJsonPath('data.hora_inicio', '17:00')->assertJsonPath('data.tema', 'Otro');
    expect(AuditLog::where('action', 'sesion_actualizada')->count())->toBe(2);
});

it('cancela con motivo y bloquea editar o marcar una sesión cancelada; una realizada no se cancela; una futura no se marca realizada', function (): void {
    $edicion = sesionEdicion();
    $admin = $this->usuarioConRol('admin');
    $futura = sesionCrear($this, $admin, $edicion, sesionDatos(5))->assertCreated();
    $pasada = sesionCrear($this, $admin, $edicion, ['fecha' => now('America/Lima')->toDateString(), 'hora_inicio' => '06:00', 'hora_fin' => '07:00'])->assertCreated();
    $idF = $futura->json('data.identificador');
    $idP = $pasada->json('data.identificador');

    $this->panelComo($admin, 'POST', "academico/sesiones/{$idF}/realizada")->assertStatus(409);
    $this->panelComo($admin, 'POST', "academico/sesiones/{$idP}/realizada")->assertOk()->assertJsonPath('data.estado', 'realizada');
    $this->panelComo($admin, 'POST', "academico/sesiones/{$idP}/realizada")->assertOk();
    $this->panelComo($admin, 'POST', "academico/sesiones/{$idP}/cancelar", ['motivo' => 'x'], ['If-Match' => SesionClase::where('identificador', $idP)->first()->etag()])->assertStatus(409);

    $this->panelComo($admin, 'POST', "academico/sesiones/{$idF}/cancelar", [], ['If-Match' => $futura->headers->get('ETag')])->assertStatus(422)->assertJsonValidationErrors('motivo');
    $r = $this->panelComo($admin, 'POST', "academico/sesiones/{$idF}/cancelar", ['motivo' => 'Feriado local'], ['If-Match' => $futura->headers->get('ETag')])->assertOk();
    $r->assertJsonPath('data.estado', 'cancelada')->assertJsonPath('data.motivo_cancelacion', 'Feriado local');
    $this->panelComo($admin, 'PUT', "academico/sesiones/{$idF}", ['tema' => 'x'], ['If-Match' => $r->headers->get('ETag')])->assertStatus(409);
    $this->panelComo($admin, 'POST', "academico/sesiones/{$idF}/realizada")->assertStatus(409);
    $this->panelComo($admin, 'POST', "academico/sesiones/{$idF}/cancelar", ['motivo' => 'otra vez'])->assertOk();
});

it('no admite cambios en sesiones de una edición cerrada o cancelada', function (): void {
    $edicion = sesionEdicion();
    $admin = $this->usuarioConRol('admin');
    $creada = sesionCrear($this, $admin, $edicion, sesionDatos(5))->assertCreated();
    $edicion->forceFill(['estado' => 'cerrada'])->save();

    sesionCrear($this, $admin, $edicion, sesionDatos(6))->assertStatus(409);
    $this->panelComo($admin, 'PUT', 'academico/sesiones/'.$creada->json('data.identificador'), ['tema' => 'x'], ['If-Match' => $creada->headers->get('ETag')])->assertStatus(409);
    $this->panelComo($admin, 'GET', "academico/convocatorias/{$edicion->identificador}/sesiones")->assertOk()->assertJsonCount(1, 'data');
});

it('filtra y pagina el listado', function (): void {
    $edicion = sesionEdicion();
    $admin = $this->usuarioConRol('admin');
    foreach ([3, 4, 5] as $dias) {
        sesionCrear($this, $admin, $edicion, sesionDatos($dias))->assertCreated();
    }
    $id = SesionClase::query()->where('numero', 2)->first()->identificador;
    $this->panelComo($admin, 'POST', "academico/sesiones/{$id}/cancelar", ['motivo' => 'x'], ['If-Match' => SesionClase::where('identificador', $id)->first()->etag()])->assertOk();

    $base = "academico/convocatorias/{$edicion->identificador}/sesiones";
    $this->panelComo($admin, 'GET', $base.'?estado=cancelada')->assertJsonCount(1, 'data');
    $this->panelComo($admin, 'GET', $base.'?desde='.now()->addDays(4)->toDateString())->assertJsonCount(2, 'data');
    $this->panelComo($admin, 'GET', $base.'?per_page=2&sort=-numero')->assertJsonCount(2, 'data')->assertJsonPath('data.0.numero', 3)->assertJsonPath('meta.total', 3);
});

it('genera sesiones desde el horario semanal y es repetible', function (): void {
    $edicion = sesionEdicion();
    $admin = $this->usuarioConRol('admin');
    foreach ([1, 3] as $dia) {
        DB::table('convocatoria_horarios')->insert(['convocatoria_id' => $edicion->id, 'dia_semana' => $dia, 'hora_inicio' => '18:00:00', 'hora_fin' => '20:00:00', 'created_at' => now(), 'updated_at' => now()]);
    }
    $desde = now()->toDateString();
    $hasta = now()->addDays(13)->toDateString();
    $url = "academico/convocatorias/{$edicion->identificador}/sesiones/generar";

    $r = $this->panelComo($admin, 'POST', $url, ['desde' => $desde, 'hasta' => $hasta])->assertOk();
    $r->assertJsonPath('data.creadas', 4)->assertJsonPath('data.omitidas_total', 0);
    expect(SesionClase::count())->toBe(4)->and(SesionClase::pluck('numero')->sort()->values()->all())->toBe([1, 2, 3, 4]);

    $r = $this->panelComo($admin, 'POST', $url, ['desde' => $desde, 'hasta' => $hasta])->assertOk();
    $r->assertJsonPath('data.creadas', 0)->assertJsonPath('data.omitidas_total', 4)->assertJsonPath('data.omitidas.0.motivo', 'ya_existe');
    expect(SesionClase::count())->toBe(4);
});

it('no genera sin horario ni con un rango excesivo; una edición sin lugar ni docente sí puede generar', function (): void {
    $admin = $this->usuarioConRol('admin');
    $sinHorario = sesionEdicion();
    $url = fn (Convocatoria $c) => "academico/convocatorias/{$c->identificador}/sesiones/generar";

    $this->panelComo($admin, 'POST', $url($sinHorario))->assertStatus(422)->assertJsonValidationErrors('edicion');

    foreach (range(1, 7) as $dia) {
        DB::table('convocatoria_horarios')->insert(['convocatoria_id' => $sinHorario->id, 'dia_semana' => $dia, 'hora_inicio' => '08:00:00', 'hora_fin' => '09:00:00', 'created_at' => now(), 'updated_at' => now()]);
    }
    $sinHorario->forceFill(['fecha_fin' => now()->addDays(400)->toDateString()])->save();
    $this->panelComo($admin, 'POST', $url($sinHorario))->assertStatus(422)->assertJsonValidationErrors('hasta');
    expect(SesionClase::count())->toBe(0);
});

it('responde 404 ante identificadores mal formados', function (): void {
    $admin = $this->usuarioConRol('admin');
    $this->panelComo($admin, 'GET', 'academico/sesiones/no-es-uuid')->assertNotFound();
    $this->panelComo($admin, 'GET', 'academico/convocatorias/no-es-uuid/sesiones')->assertNotFound();
    $this->panelComo($admin, 'GET', 'academico/sesiones/'.Str::uuid())->assertNotFound();
});
