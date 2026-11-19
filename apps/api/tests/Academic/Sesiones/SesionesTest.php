<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Aula;
use App\Models\Convocatoria;
use App\Models\Curso;
use App\Models\Docente;
use App\Models\PlataformaVirtual;
use App\Models\Sede;
use App\Models\SesionClase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Horus Academic, Fase 5: sesiones de clase. SQLite en memoria: los CHECK, los bloqueos consultivos por aula/docente
 * (`pg_advisory_xact_lock`) y la carrera entre dos altas simultáneas son de PostgreSQL y NO se prueban aquí; las reglas de cruce se
 * prueban en el servicio, que es la barrera en SQLite y la primera en PostgreSQL.
 */

const ENLACE_PRIVADO = 'https://meet.example.test/privado-abc-defg-hij';

/** @return array{0: Aula, 1: Sede} */
function sesionAula(string $codigo = 'A-1'): array
{
    $sede = Sede::query()->first() ?? tap(new Sede(['nombre' => 'Sede Central', 'direccion' => 'Jr. Ficticio 1', 'ciudad' => 'Cajamarca']), function (Sede $s): void {
        $s->identificador = (string) Str::uuid();
        $s->codigo = 'SC-1';
        $s->estado = 'activo';
        $s->save();
    });
    $aula = new Aula(['nombre' => 'Aula '.$codigo, 'capacidad' => 30]);
    $aula->identificador = (string) Str::uuid();
    $aula->sede_id = $sede->id;
    $aula->codigo = $codigo;
    $aula->estado = 'activo';
    $aula->save();

    return [$aula, $sede];
}

function sesionPlataforma(): PlataformaVirtual
{
    $p = new PlataformaVirtual(['nombre' => 'Meet '.Str::random(4), 'tipo' => 'meet']);
    $p->identificador = (string) Str::uuid();
    $p->estado = 'activo';
    $p->save();

    return $p;
}

function sesionDocente(string $dni = '41000001'): Docente
{
    $d = new Docente(['nombres' => 'Rosa', 'apellidos' => 'Quispe', 'email' => null, 'telefono' => null, 'especialidad' => null]);
    $d->identificador = (string) Str::uuid();
    $d->dni = $dni;
    $d->estado = 'activo';
    $d->save();

    return $d;
}

/** Edición vigente con fechas amplias (de ayer a +60 días). Presencial usa `$aula`; virtual, `$plataforma`. */
function sesionEdicion(string $modalidad = 'presencial', ?Aula $aula = null, ?PlataformaVirtual $plataforma = null, string $estado = 'abierta'): Convocatoria
{
    static $n = 0;
    $n++;
    $curso = Curso::create(['codigo' => 'SES-'.$n, 'nombre' => 'Curso '.$n, 'descripcion' => 'x', 'categoria' => 'tecnologia', 'duracion_horas' => 10,
        'precio' => 100, 'modalidad' => $modalidad, 'horario' => 'Lun', 'cupos_maximos' => 5, 'cupos_disponibles' => 5, 'estado' => 'activo']);
    $c = new Convocatoria(['nombre' => null, 'modalidad' => $modalidad, 'fecha_inicio' => now()->subDay()->toDateString(),
        'fecha_fin' => now()->addDays(60)->toDateString(), 'cupos_maximos' => 20]);
    $c->identificador = (string) Str::uuid();
    $c->codigo = 'CNV-2026-8'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    $c->curso_id = $curso->id;
    $c->estado = $estado;
    $c->cupos_disponibles = 20;
    $c->revision = 1;
    $c->sede_id = $aula?->sede_id;
    $c->aula_id = $aula?->id;
    $c->plataforma_virtual_id = $plataforma?->id;
    $c->save();

    return $c;
}

function sesionAsignar(Docente $d, Convocatoria $c, string $rol = 'principal'): void
{
    DB::table('convocatoria_docentes')->insert(['convocatoria_id' => $c->id, 'docente_id' => $d->id, 'rol' => $rol, 'created_at' => now(), 'updated_at' => now()]);
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

it('crea la tabla con número único por edición y sin enlace en claro', function (): void {
    expect(Schema::hasTable('sesiones_clase'))->toBeTrue();
    $unicos = collect(Schema::getIndexes('sesiones_clase'))->filter(fn ($i) => $i['unique'])->pluck('columns')->all();
    expect($unicos)->toContain(['convocatoria_id', 'numero']);
});

it('exige autenticación y el permiso de cada operación', function (): void {
    [$aula] = sesionAula();
    $edicion = sesionEdicion('presencial', $aula);
    $admin = $this->usuarioConRol('admin', 'a@example.invalid');
    $sec = $this->usuarioConRol('secretaria', 's@example.invalid');
    $coord = $this->usuarioConRol('coordinador', 'c@example.invalid');

    $this->panelComo(null, 'GET', "academico/convocatorias/{$edicion->identificador}/sesiones")->assertUnauthorized();
    sesionCrear($this, $sec, $edicion, sesionDatos())->assertForbidden();
    sesionCrear($this, $admin, $edicion, sesionDatos())->assertCreated();
    $id = SesionClase::first()->identificador;

    $this->panelComo($sec, 'GET', "academico/convocatorias/{$edicion->identificador}/sesiones")->assertOk();
    $this->panelComo($sec, 'GET', "academico/sesiones/{$id}")->assertOk();
    $this->panelComo($sec, 'GET', "academico/sesiones/{$id}/enlace")->assertForbidden();
    $this->panelComo($sec, 'POST', "academico/sesiones/{$id}/cancelar", ['motivo' => 'x'])->assertForbidden();
    $this->panelComo($sec, 'POST', "academico/sesiones/{$id}/realizada")->assertForbidden();
    $this->panelComo($sec, 'POST', "academico/convocatorias/{$edicion->identificador}/sesiones/generar")->assertForbidden();
    sesionCrear($this, $coord, $edicion, sesionDatos(6))->assertCreated();
});

it('programa una sesión con el aula de la edición, numeración automática y sin ids internos', function (): void {
    [$aula] = sesionAula();
    $edicion = sesionEdicion('presencial', $aula);
    $admin = $this->usuarioConRol('admin');

    $r = sesionCrear($this, $admin, $edicion, sesionDatos(5))->assertCreated();
    $r->assertJsonPath('data.numero', 1)->assertJsonPath('data.hora_inicio', '18:00')->assertJsonPath('data.hora_fin', '20:00')
        ->assertJsonPath('data.estado', 'programada')->assertJsonPath('data.aula.identificador', $aula->identificador)
        ->assertJsonPath('data.tiene_enlace', false)->assertJsonMissingPath('data.id')->assertJsonMissingPath('data.enlace_virtual');
    expect($r->headers->get('ETag'))->toStartWith('"sesion-');
    sesionCrear($this, $admin, $edicion, sesionDatos(6))->assertJsonPath('data.numero', 2);
});

it('valida fecha, horas y número', function (): void {
    [$aula] = sesionAula();
    $edicion = sesionEdicion('presencial', $aula);
    $admin = $this->usuarioConRol('admin');

    sesionCrear($this, $admin, $edicion, sesionDatos(5, '20:00', '18:00'))->assertStatus(422)->assertJsonValidationErrors('hora_fin');
    sesionCrear($this, $admin, $edicion, sesionDatos(90))->assertStatus(422)->assertJsonValidationErrors('fecha');
    sesionCrear($this, $admin, $edicion, ['fecha' => 'mañana', 'hora_inicio' => '8', 'hora_fin' => '9'])->assertStatus(422)->assertJsonValidationErrors(['fecha', 'hora_inicio', 'hora_fin']);
    sesionCrear($this, $admin, $edicion, sesionDatos(5, extra: ['numero' => 3]))->assertCreated();
    sesionCrear($this, $admin, $edicion, sesionDatos(6, extra: ['numero' => 3]))->assertStatus(422)->assertJsonValidationErrors('numero');
});

it('impide cruces de aula; lo adyacente y lo cancelado no cruzan', function (): void {
    [$aula] = sesionAula();
    $uno = sesionEdicion('presencial', $aula);
    $dos = sesionEdicion('presencial', $aula);
    $admin = $this->usuarioConRol('admin');

    sesionCrear($this, $admin, $uno, sesionDatos(5, '18:00', '20:00'))->assertCreated();
    sesionCrear($this, $admin, $dos, sesionDatos(5, '19:00', '21:00'))->assertStatus(422)->assertJsonValidationErrors('aula');
    sesionCrear($this, $admin, $dos, sesionDatos(5, '20:00', '21:00'))->assertCreated();
    sesionCrear($this, $admin, $dos, sesionDatos(5, '17:00', '18:00'))->assertCreated();

    $id = SesionClase::query()->where('convocatoria_id', $uno->id)->first()->identificador;
    $this->panelComo($admin, 'POST', "academico/sesiones/{$id}/cancelar", ['motivo' => 'Feriado'], ['If-Match' => SesionClase::where('identificador', $id)->first()->etag()])->assertOk();
    sesionCrear($this, $admin, $dos, sesionDatos(5, '18:30', '19:30'))->assertCreated();
});

it('impide cruces de docente entre ediciones y exige que el docente esté asignado y activo', function (): void {
    [$aula] = sesionAula();
    [$otraAula] = sesionAula('A-2');
    $uno = sesionEdicion('presencial', $aula);
    $dos = sesionEdicion('presencial', $otraAula);
    $docente = sesionDocente();
    $admin = $this->usuarioConRol('admin');

    sesionCrear($this, $admin, $uno, sesionDatos(5, extra: ['docente' => $docente->identificador]))->assertStatus(422)->assertJsonValidationErrors('docente');
    sesionAsignar($docente, $uno);
    sesionAsignar($docente, $dos);
    sesionCrear($this, $admin, $uno, sesionDatos(5, extra: ['docente' => $docente->identificador]))->assertCreated()->assertJsonPath('data.docente.apellidos', 'Quispe');
    sesionCrear($this, $admin, $dos, sesionDatos(5, '19:00', '21:00', ['docente' => $docente->identificador]))->assertStatus(422)->assertJsonValidationErrors('docente');
    sesionCrear($this, $admin, $dos, sesionDatos(5, '20:00', '21:00', ['docente' => $docente->identificador]))->assertCreated();

    $docente->forceFill(['estado' => 'inactivo'])->save();
    sesionCrear($this, $admin, $dos, sesionDatos(7, extra: ['docente' => $docente->identificador]))->assertStatus(422)->assertJsonValidationErrors('docente');
});

it('respeta la modalidad: aula solo en presencial, plataforma solo en virtual, y aula de la sede de la edición', function (): void {
    [$aula] = sesionAula();
    $plataforma = sesionPlataforma();
    $presencial = sesionEdicion('presencial', $aula);
    $virtual = sesionEdicion('virtual', null, $plataforma);
    $admin = $this->usuarioConRol('admin');

    sesionCrear($this, $admin, $presencial, sesionDatos(5, extra: ['plataforma_virtual' => $plataforma->identificador]))->assertStatus(422)->assertJsonValidationErrors('plataforma_virtual');
    sesionCrear($this, $admin, $virtual, sesionDatos(5, extra: ['aula' => $aula->identificador]))->assertStatus(422)->assertJsonValidationErrors('aula');
    sesionCrear($this, $admin, $virtual, sesionDatos(5))->assertCreated()->assertJsonPath('data.plataforma_virtual.identificador', $plataforma->identificador)->assertJsonPath('data.aula', null);

    $otraSede = new Sede(['nombre' => 'Otra', 'direccion' => 'x', 'ciudad' => 'Cajamarca']);
    $otraSede->identificador = (string) Str::uuid();
    $otraSede->codigo = 'OS-1';
    $otraSede->estado = 'activo';
    $otraSede->save();
    $ajena = new Aula(['nombre' => 'Ajena', 'capacidad' => 10]);
    $ajena->identificador = (string) Str::uuid();
    $ajena->sede_id = $otraSede->id;
    $ajena->codigo = 'X-1';
    $ajena->estado = 'activo';
    $ajena->save();
    sesionCrear($this, $admin, $presencial, sesionDatos(6, extra: ['aula' => $ajena->identificador]))->assertStatus(422)->assertJsonValidationErrors('aula');
});

it('guarda el enlace virtual cifrado y solo lo entrega con permiso y auditoría, sin filtrarlo a ninguna otra respuesta', function (): void {
    [$aula] = sesionAula();
    $plataforma = sesionPlataforma();
    $presencial = sesionEdicion('presencial', $aula);
    $virtual = sesionEdicion('virtual', null, $plataforma);
    $admin = $this->usuarioConRol('admin', 'a@example.invalid');
    $coord = $this->usuarioConRol('coordinador', 'c@example.invalid');
    $sec = $this->usuarioConRol('secretaria', 's@example.invalid');

    sesionCrear($this, $admin, $presencial, sesionDatos(5, extra: ['enlace_virtual' => ENLACE_PRIVADO]))->assertStatus(422)->assertJsonValidationErrors('enlace_virtual');
    sesionCrear($this, $admin, $virtual, sesionDatos(5, extra: ['enlace_virtual' => 'http://inseguro.example.test/x']))->assertStatus(422)->assertJsonValidationErrors('enlace_virtual');
    sesionCrear($this, $admin, $virtual, sesionDatos(5, extra: ['enlace_virtual' => 'javascript:alert(1)']))->assertStatus(422);

    $crear = sesionCrear($this, $admin, $virtual, sesionDatos(5, extra: ['enlace_virtual' => ENLACE_PRIVADO]))->assertCreated()->assertJsonPath('data.tiene_enlace', true);
    $id = $crear->json('data.identificador');

    // Cifrado en reposo.
    $crudo = DB::table('sesiones_clase')->value('enlace_virtual');
    expect($crudo)->not->toContain('meet.example.test')->and(Crypt::decryptString($crudo))->toBe(ENLACE_PRIVADO);

    // Ninguna respuesta de lectura o escritura lo contiene.
    $respuestas = [
        $crear,
        $this->panelComo($admin, 'GET', "academico/convocatorias/{$virtual->identificador}/sesiones"),
        $this->panelComo($sec, 'GET', "academico/sesiones/{$id}"),
        $this->panelComo($admin, 'PUT', "academico/sesiones/{$id}", ['tema' => 'Nuevo tema'], ['If-Match' => $crear->headers->get('ETag')]),
        $this->panelComo($admin, 'GET', "academico/convocatorias/{$virtual->identificador}"),
    ];
    foreach ($respuestas as $respuesta) {
        expect($respuesta->getContent())->not->toContain('meet.example.test')->and($respuesta->getContent())->not->toContain('privado-abc');
    }

    // Auditoría: sin el enlace en ningún registro.
    expect(json_encode(AuditLog::all()->map->only(['action', 'changes'])->all()))->not->toContain('meet.example.test')->not->toContain('privado-abc');

    $this->panelComo($sec, 'GET', "academico/sesiones/{$id}/enlace")->assertForbidden();
    $antes = AuditLog::where('action', 'sesion_enlace_consultado')->count();
    $r = $this->panelComo($coord, 'GET', "academico/sesiones/{$id}/enlace")->assertOk()->assertJsonPath('data.enlace_virtual', ENLACE_PRIVADO);
    expect($r->headers->get('Cache-Control'))->toContain('no-store')
        ->and(AuditLog::where('action', 'sesion_enlace_consultado')->count())->toBe($antes + 1)
        ->and(json_encode(AuditLog::where('action', 'sesion_enlace_consultado')->first()->changes))->not->toContain('meet');

    // Sin enlace -> 409.
    $sin = sesionCrear($this, $admin, $virtual, sesionDatos(6))->json('data.identificador');
    $this->panelComo($admin, 'GET', "academico/sesiones/{$sin}/enlace")->assertStatus(409);
});

it('edita con revisión: 428 sin If-Match, 412 con revisión vieja, avanza al cambiar y no al repetir', function (): void {
    [$aula] = sesionAula();
    $edicion = sesionEdicion('presencial', $aula);
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

    // Reprogramar conserva el resto y valida cruces contra las demás.
    $this->panelComo($admin, 'PUT', "academico/sesiones/{$id}", ['hora_inicio' => '17:00', 'hora_fin' => '19:00'], ['If-Match' => $v2])->assertOk()->assertJsonPath('data.hora_inicio', '17:00')->assertJsonPath('data.tema', 'Otro');
    expect(AuditLog::where('action', 'sesion_actualizada')->count())->toBe(2);
});

it('cancela con motivo y bloquea editar o marcar una sesión cancelada; una realizada no se cancela; una futura no se marca realizada', function (): void {
    [$aula] = sesionAula();
    $edicion = sesionEdicion('presencial', $aula);
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
    [$aula] = sesionAula();
    $edicion = sesionEdicion('presencial', $aula);
    $admin = $this->usuarioConRol('admin');
    $creada = sesionCrear($this, $admin, $edicion, sesionDatos(5))->assertCreated();
    $edicion->forceFill(['estado' => 'cerrada'])->save();

    sesionCrear($this, $admin, $edicion, sesionDatos(6))->assertStatus(409);
    $this->panelComo($admin, 'PUT', 'academico/sesiones/'.$creada->json('data.identificador'), ['tema' => 'x'], ['If-Match' => $creada->headers->get('ETag')])->assertStatus(409);
    $this->panelComo($admin, 'GET', "academico/convocatorias/{$edicion->identificador}/sesiones")->assertOk()->assertJsonCount(1, 'data');
});

it('filtra y pagina el listado', function (): void {
    [$aula] = sesionAula();
    $edicion = sesionEdicion('presencial', $aula);
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

it('genera sesiones desde el horario semanal, es repetible y omite cruces', function (): void {
    [$aula] = sesionAula();
    $edicion = sesionEdicion('presencial', $aula);
    $docente = sesionDocente();
    sesionAsignar($docente, $edicion);
    $admin = $this->usuarioConRol('admin');
    foreach ([1, 3] as $dia) {
        DB::table('convocatoria_horarios')->insert(['convocatoria_id' => $edicion->id, 'dia_semana' => $dia, 'hora_inicio' => '18:00:00', 'hora_fin' => '20:00:00', 'created_at' => now(), 'updated_at' => now()]);
    }
    $desde = now()->toDateString();
    $hasta = now()->addDays(13)->toDateString();
    $url = "academico/convocatorias/{$edicion->identificador}/sesiones/generar";

    $r = $this->panelComo($admin, 'POST', $url, ['desde' => $desde, 'hasta' => $hasta])->assertOk();
    $r->assertJsonPath('data.creadas', 4)->assertJsonPath('data.omitidas_total', 0);
    expect(SesionClase::count())->toBe(4)->and(SesionClase::pluck('numero')->sort()->values()->all())->toBe([1, 2, 3, 4])
        ->and(SesionClase::whereNotNull('docente_id')->count())->toBe(4);

    $r = $this->panelComo($admin, 'POST', $url, ['desde' => $desde, 'hasta' => $hasta])->assertOk();
    $r->assertJsonPath('data.creadas', 0)->assertJsonPath('data.omitidas_total', 4)->assertJsonPath('data.omitidas.0.motivo', 'ya_existe');
    expect(SesionClase::count())->toBe(4);

    // Otra edición con la misma aula y el mismo horario: todo se omite por cruce de aula.
    $otra = sesionEdicion('presencial', $aula);
    DB::table('convocatoria_horarios')->insert(['convocatoria_id' => $otra->id, 'dia_semana' => 1, 'hora_inicio' => '19:00:00', 'hora_fin' => '21:00:00', 'created_at' => now(), 'updated_at' => now()]);
    $r = $this->panelComo($admin, 'POST', "academico/convocatorias/{$otra->identificador}/sesiones/generar", ['desde' => $desde, 'hasta' => $hasta])->assertOk();
    $r->assertJsonPath('data.creadas', 0)->assertJsonPath('data.omitidas.0.motivo', 'cruce_aula');
});

it('no genera sin infraestructura, sin horario ni con un rango excesivo', function (): void {
    [$aula] = sesionAula();
    $admin = $this->usuarioConRol('admin');
    $sinInfra = sesionEdicion('presencial');
    $sinHorario = sesionEdicion('presencial', $aula);
    $url = fn (Convocatoria $c) => "academico/convocatorias/{$c->identificador}/sesiones/generar";

    $this->panelComo($admin, 'POST', $url($sinInfra))->assertStatus(422)->assertJsonValidationErrors('edicion');
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
