<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Aula;
use App\Models\Convocatoria;
use App\Models\Curso;
use App\Models\PlataformaVirtual;
use App\Models\Sede;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Horus Academic, Fase 3: sedes, aulas, plataformas virtuales y su asignación a las ediciones. SQLite en memoria: los CHECK, la
 * clave foránea compuesta (sede, aula) y los disparadores de PostgreSQL NO se prueban aquí (solo existen en ese motor); las
 * reglas se prueban en el servicio, que es la única barrera en SQLite y la primera en PostgreSQL.
 */

function nuevaSede(string $codigo = 'CAJ-01', array $extra = []): Sede
{
    $sede = new Sede(['nombre' => 'Sede Central '.$codigo, 'direccion' => 'Jr. Ficticio 123', 'ciudad' => 'Cajamarca', ...$extra]);
    $sede->identificador = (string) Str::uuid();
    $sede->codigo = $codigo;
    $sede->estado = $extra['estado'] ?? 'activo';
    $sede->save();

    return $sede;
}

function nuevaAula(Sede $sede, string $codigo = 'A-101', int $capacidad = 30, string $estado = 'activo'): Aula
{
    $aula = new Aula(['nombre' => 'Aula '.$codigo, 'capacidad' => $capacidad]);
    $aula->identificador = (string) Str::uuid();
    $aula->sede_id = $sede->id;
    $aula->codigo = $codigo;
    $aula->estado = $estado;
    $aula->save();

    return $aula;
}

function nuevaPlataforma(string $nombre = 'Google Meet', string $tipo = 'meet', string $estado = 'activo'): PlataformaVirtual
{
    $p = new PlataformaVirtual(['nombre' => $nombre, 'tipo' => $tipo]);
    $p->identificador = (string) Str::uuid();
    $p->estado = $estado;
    $p->save();

    return $p;
}

function nuevaEdicion(string $modalidad = 'presencial', string $estado = 'borrador', int $cupos = 20, bool $exige = false): Convocatoria
{
    static $n = 0;
    $n++;
    $curso = Curso::create([
        'codigo' => 'INF-'.$n, 'nombre' => 'Curso '.$n, 'descripcion' => 'x', 'categoria' => 'tecnologia', 'duracion_horas' => 10,
        'precio' => 100, 'modalidad' => 'virtual', 'horario' => 'Lun', 'cupos_maximos' => 5, 'cupos_disponibles' => 5, 'estado' => 'activo',
    ]);
    $c = new Convocatoria(['nombre' => null, 'modalidad' => $modalidad, 'fecha_inicio' => now()->addDay()->toDateString(),
        'fecha_fin' => now()->addMonth()->toDateString(), 'cupos_maximos' => $cupos]);
    $c->identificador = (string) Str::uuid();
    $c->codigo = 'CNV-2026-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    $c->curso_id = $curso->id;
    $c->estado = $estado;
    $c->cupos_disponibles = $cupos;
    $c->revision = 1;
    $c->exige_infraestructura = $exige;
    $c->save();

    return $c;
}

/** @return array<string, string> cabeceras con la revisión vigente de la edición */
function revisionDe(Convocatoria $c): array
{
    return ['If-Match' => $c->fresh()->etag()];
}

it('crea las tablas y columnas de infraestructura, nulas en las ediciones existentes', function (): void {
    foreach (['sedes', 'aulas', 'plataformas_virtuales'] as $tabla) {
        expect(Schema::hasTable($tabla))->toBeTrue();
    }
    foreach (['sede_id', 'aula_id', 'plataforma_virtual_id', 'exige_infraestructura'] as $columna) {
        expect(Schema::hasColumn('convocatorias', $columna))->toBeTrue();
    }

    $edicion = nuevaEdicion();
    expect($edicion->fresh()->sede_id)->toBeNull()
        ->and($edicion->fresh()->aula_id)->toBeNull()
        ->and($edicion->fresh()->plataforma_virtual_id)->toBeNull()
        ->and($edicion->fresh()->exige_infraestructura)->toBeFalse();

    $unicos = collect(Schema::getIndexes('aulas'))->filter(fn ($i) => $i['unique'])->pluck('columns')->all();
    expect($unicos)->toContain(['sede_id', 'codigo']);
});

it('los permisos de infraestructura se conceden por rol según la matriz', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    foreach (['admin', 'coordinador'] as $rol) {
        $u = $this->usuarioConRol($rol, "{$rol}@example.invalid");
        expect($u->can('infraestructura.ver'))->toBeTrue()->and($u->can('infraestructura.gestionar'))->toBeTrue();
    }
    $secretaria = $this->usuarioConRol('secretaria', 'sec@example.invalid');
    expect($secretaria->can('infraestructura.ver'))->toBeTrue()->and($secretaria->can('infraestructura.gestionar'))->toBeFalse();
});

it('la migración de permisos concede lo mismo que el seeder sin resincronizar y es reversible', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $coordinador = Role::findByName('coordinador', 'web');
    Permission::query()->whereIn('name', RolesAndPermissionsSeeder::INFRAESTRUCTURA_PERMISSIONS)->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $permisoAjeno = Permission::findOrCreate('permiso.concedido.a.mano', 'web');
    $coordinador->givePermissionTo($permisoAjeno);

    $migracion = require database_path('migrations/2026_11_16_000064_add_infraestructura_permissions.php');
    $migracion->up();
    $migracion->up(); // idempotente

    $coordinador = $coordinador->fresh();
    expect($coordinador->hasPermissionTo('infraestructura.gestionar'))->toBeTrue()
        ->and($coordinador->hasPermissionTo('permiso.concedido.a.mano'))->toBeTrue()
        ->and(Role::findByName('secretaria', 'web')->hasPermissionTo('infraestructura.gestionar'))->toBeFalse();

    $migracion->down();
    expect(Permission::query()->whereIn('name', RolesAndPermissionsSeeder::INFRAESTRUCTURA_PERMISSIONS)->count())->toBe(0);
});

it('aplica la matriz de roles a todas las rutas de infraestructura', function (string $metodo, string $ruta, bool $gestion): void {
    $sede = nuevaSede();
    $ruta = str_replace('{sede}', $sede->identificador, $ruta);

    // sin sesión
    $this->panelComo(null, $metodo, $ruta)->assertUnauthorized();
    // sin rol
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->panelComo(User::factory()->create(), $metodo, $ruta)->assertForbidden();
    // secretaria: solo lectura
    $secretaria = $this->usuarioConRol('secretaria', 'sec@example.invalid');
    $estado = $this->panelComo($secretaria, $metodo, $ruta)->getStatusCode();
    $gestion ? expect($estado)->toBe(403) : expect($estado)->not->toBe(403);
    // coordinador y admin: nunca 403
    foreach (['coordinador', 'admin'] as $rol) {
        expect($this->panelComo($this->usuarioConRol($rol, "{$rol}@example.invalid"), $metodo, $ruta)->getStatusCode())->not->toBe(403);
    }
})->with([
    'listar sedes' => ['GET', 'academico/sedes', false],
    'ver sede' => ['GET', 'academico/sedes/{sede}', false],
    'crear sede' => ['POST', 'academico/sedes', true],
    'editar sede' => ['PUT', 'academico/sedes/{sede}', true],
    'estado sede' => ['POST', 'academico/sedes/{sede}/estado', true],
    'listar aulas' => ['GET', 'academico/aulas', false],
    'crear aula' => ['POST', 'academico/aulas', true],
    'listar plataformas' => ['GET', 'academico/plataformas-virtuales', false],
    'crear plataforma' => ['POST', 'academico/plataformas-virtuales', true],
]);

it('no existe ninguna ruta para eliminar infraestructura: solo se desactiva', function (string $ruta): void {
    $admin = $this->usuarioConRol('admin');
    $sede = nuevaSede();
    $this->panelComo($admin, 'DELETE', str_replace('{sede}', $sede->identificador, $ruta))->assertStatus(405);
    expect(Sede::count())->toBe(1);
})->with(['sede' => ['academico/sedes/{sede}'], 'aulas' => ['academico/aulas/{sede}'], 'plataformas' => ['academico/plataformas-virtuales/{sede}']]);

describe('sedes', function (): void {
    it('crea, lista, edita y desactiva una sede, sin exponer el id interno', function (): void {
        $admin = $this->usuarioConRol('admin');

        $creada = $this->panelComo($admin, 'POST', 'academico/sedes', ['codigo' => 'caj-01', 'nombre' => ' Sede Central ', 'direccion' => 'Jr. Ficticio 123'])
            ->assertCreated()->assertJsonPath('data.codigo', 'CAJ-01')->assertJsonPath('data.nombre', 'Sede Central')
            ->assertJsonPath('data.ciudad', 'Cajamarca')->assertJsonPath('data.estado', 'activo');
        $id = $creada->json('data.identificador');
        expect($creada->json('data'))->not->toHaveKey('id');

        $this->panelComo($admin, 'GET', 'academico/sedes')->assertOk()->assertJsonPath('meta.total', 1);
        $this->panelComo($admin, 'PUT', "academico/sedes/{$id}", ['nombre' => 'Sede Principal', 'direccion' => 'Av. Nueva 45', 'ciudad' => 'Celendín'])
            ->assertOk()->assertJsonPath('data.nombre', 'Sede Principal')->assertJsonPath('data.ciudad', 'Celendín');
        $this->panelComo($admin, 'POST', "academico/sedes/{$id}/estado", ['estado' => 'inactivo'])->assertOk()->assertJsonPath('data.estado', 'inactivo');
        $this->panelComo($admin, 'GET', 'academico/sedes?estado=activo')->assertOk()->assertJsonPath('meta.total', 0);
        expect(AuditLog::query()->whereIn('action', ['sede_creada', 'sede_actualizada', 'sede_estado_cambiado'])->count())->toBe(3);
    });

    it('valida el código, rechaza duplicados y campos que fija el servidor', function (): void {
        $admin = $this->usuarioConRol('admin');
        nuevaSede('CAJ-01');

        $this->panelComo($admin, 'POST', 'academico/sedes', ['codigo' => 'CAJ-01', 'nombre' => 'Otra', 'direccion' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('codigo');
        $this->panelComo($admin, 'POST', 'academico/sedes', ['codigo' => 'a b', 'nombre' => 'Otra', 'direccion' => 'x'])->assertStatus(422)->assertJsonValidationErrors('codigo');
        $this->panelComo($admin, 'POST', 'academico/sedes', ['codigo' => 'NUEVA', 'nombre' => '<b>x</b>', 'direccion' => 'x'])->assertStatus(422)->assertJsonValidationErrors('nombre');
        $this->panelComo($admin, 'POST', 'academico/sedes', ['codigo' => 'NUEVA', 'nombre' => 'Ok', 'direccion' => 'x', 'estado' => 'inactivo', 'identificador' => (string) Str::uuid()])
            ->assertStatus(422)->assertJsonValidationErrors(['estado', 'identificador']);
        $id = Sede::first()->identificador;
        $this->panelComo($admin, 'PUT', "academico/sedes/{$id}", ['codigo' => 'CAMBIO', 'nombre' => 'Ok', 'direccion' => 'x'])->assertStatus(422)->assertJsonValidationErrors('codigo');
    });

    it('no desactiva una sede asignada a ediciones vigentes', function (): void {
        $admin = $this->usuarioConRol('admin');
        $sede = nuevaSede();
        $aula = nuevaAula($sede);
        $edicion = nuevaEdicion('presencial', 'abierta');
        $edicion->forceFill(['sede_id' => $sede->id, 'aula_id' => $aula->id])->save();

        $this->panelComo($admin, 'POST', "academico/sedes/{$sede->identificador}/estado", ['estado' => 'inactivo'])->assertStatus(409);
        expect($sede->fresh()->estado)->toBe('activo');

        $edicion->forceFill(['estado' => 'cerrada'])->save();
        $this->panelComo($admin, 'POST', "academico/sedes/{$sede->identificador}/estado", ['estado' => 'inactivo'])->assertOk();
    });

    it('responde 404 ante un identificador mal formado o inexistente', function (): void {
        $admin = $this->usuarioConRol('admin');

        $this->panelComo($admin, 'GET', 'academico/sedes/no-es-uuid')->assertNotFound();
        $this->panelComo($admin, 'GET', 'academico/sedes/'.Str::uuid())->assertNotFound();
    });
});

describe('aulas', function (): void {
    it('crea un aula en una sede activa y la sede no cambia después', function (): void {
        $admin = $this->usuarioConRol('admin');
        $sede = nuevaSede();
        $otra = nuevaSede('CAJ-02');

        $r = $this->panelComo($admin, 'POST', 'academico/aulas', ['sede' => $sede->identificador, 'codigo' => 'a-101', 'nombre' => 'Aula 101', 'capacidad' => 30])
            ->assertCreated()->assertJsonPath('data.codigo', 'A-101')->assertJsonPath('data.sede.codigo', 'CAJ-01')->assertJsonPath('data.capacidad', 30);
        expect($r->json('data'))->not->toHaveKey('id')->not->toHaveKey('sede_id');

        $id = $r->json('data.identificador');
        $this->panelComo($admin, 'PUT', "academico/aulas/{$id}", ['nombre' => 'Aula 101-B', 'capacidad' => 35])->assertOk()->assertJsonPath('data.capacidad', 35);
        $this->panelComo($admin, 'PUT', "academico/aulas/{$id}", ['sede' => $otra->identificador, 'nombre' => 'x', 'capacidad' => 35])
            ->assertStatus(422)->assertJsonValidationErrors('sede');
        $this->panelComo($admin, 'GET', 'academico/aulas?sede='.$sede->identificador)->assertOk()->assertJsonPath('meta.total', 1);
        $this->panelComo($admin, 'GET', 'academico/aulas?sede='.$otra->identificador)->assertOk()->assertJsonPath('meta.total', 0);
    });

    it('rechaza sede inactiva o inexistente, códigos repetidos en la sede y capacidades inválidas', function (): void {
        $admin = $this->usuarioConRol('admin');
        $sede = nuevaSede();
        $inactiva = nuevaSede('INACT', ['estado' => 'inactivo']);
        nuevaAula($sede, 'A-101');

        $base = ['codigo' => 'B-1', 'nombre' => 'Aula', 'capacidad' => 10];
        $this->panelComo($admin, 'POST', 'academico/aulas', ['sede' => $inactiva->identificador, ...$base])->assertStatus(422)->assertJsonValidationErrors('sede');
        $this->panelComo($admin, 'POST', 'academico/aulas', ['sede' => (string) Str::uuid(), ...$base])->assertNotFound();
        $this->panelComo($admin, 'POST', 'academico/aulas', ['sede' => $sede->identificador, ...$base, 'codigo' => 'a-101'])->assertStatus(422)->assertJsonValidationErrors('codigo');
        foreach ([0, -5, 5001, 'diez', null] as $capacidad) {
            $this->panelComo($admin, 'POST', 'academico/aulas', ['sede' => $sede->identificador, ...$base, 'capacidad' => $capacidad])->assertStatus(422)->assertJsonValidationErrors('capacidad');
        }
        // El mismo código sí puede repetirse en otra sede.
        $otra = nuevaSede('CAJ-02');
        $this->panelComo($admin, 'POST', 'academico/aulas', ['sede' => $otra->identificador, ...$base, 'codigo' => 'A-101'])->assertCreated();
    });

    it('no reduce la capacidad por debajo de los cupos de ediciones vigentes ni desactiva un aula en uso', function (): void {
        $admin = $this->usuarioConRol('admin');
        $sede = nuevaSede();
        $aula = nuevaAula($sede, 'A-101', 40);
        $edicion = nuevaEdicion('presencial', 'abierta', 35);
        $edicion->forceFill(['sede_id' => $sede->id, 'aula_id' => $aula->id])->save();

        $this->panelComo($admin, 'PUT', "academico/aulas/{$aula->identificador}", ['nombre' => 'Aula', 'capacidad' => 30])->assertStatus(422)->assertJsonValidationErrors('capacidad');
        $this->panelComo($admin, 'PUT', "academico/aulas/{$aula->identificador}", ['nombre' => 'Aula', 'capacidad' => 35])->assertOk();
        $this->panelComo($admin, 'POST', "academico/aulas/{$aula->identificador}/estado", ['estado' => 'inactivo'])->assertStatus(409);
    });

    it('no reactiva un aula de una sede inactiva', function (): void {
        $admin = $this->usuarioConRol('admin');
        $sede = nuevaSede('INACT', ['estado' => 'inactivo']);
        $aula = nuevaAula($sede, 'A-1', 10, 'inactivo');

        $this->panelComo($admin, 'POST', "academico/aulas/{$aula->identificador}/estado", ['estado' => 'activo'])->assertStatus(422)->assertJsonValidationErrors('estado');
    });
});

describe('plataformas virtuales', function (): void {
    it('cataloga una plataforma sin credenciales ni enlaces y evita nombres repetidos', function (): void {
        $admin = $this->usuarioConRol('admin');

        $r = $this->panelComo($admin, 'POST', 'academico/plataformas-virtuales', ['nombre' => 'Google Meet', 'tipo' => 'MEET'])
            ->assertCreated()->assertJsonPath('data.tipo', 'meet')->assertJsonPath('data.estado', 'activo');
        expect(array_keys($r->json('data')))->toBe(['identificador', 'nombre', 'tipo', 'estado', 'creado_en']);

        $this->panelComo($admin, 'POST', 'academico/plataformas-virtuales', ['nombre' => 'google meet', 'tipo' => 'meet'])->assertStatus(422)->assertJsonValidationErrors('nombre');
        $this->panelComo($admin, 'POST', 'academico/plataformas-virtuales', ['nombre' => 'Otra', 'tipo' => 'skype'])->assertStatus(422)->assertJsonValidationErrors('tipo');
        // Cualquier intento de guardar credenciales o enlaces se ignora: no hay campos para ellos.
        $this->panelComo($admin, 'POST', 'academico/plataformas-virtuales', ['nombre' => 'Zoom', 'tipo' => 'zoom', 'token' => 'secreto', 'enlace' => 'https://zoom.us/j/1'])->assertCreated();
        expect(Schema::hasColumn('plataformas_virtuales', 'token'))->toBeFalse()->and(Schema::hasColumn('plataformas_virtuales', 'enlace'))->toBeFalse();
    });

    it('no desactiva una plataforma asignada a ediciones vigentes', function (): void {
        $admin = $this->usuarioConRol('admin');
        $plataforma = nuevaPlataforma();
        $edicion = nuevaEdicion('virtual', 'abierta');
        $edicion->forceFill(['plataforma_virtual_id' => $plataforma->id])->save();

        $this->panelComo($admin, 'POST', "academico/plataformas-virtuales/{$plataforma->identificador}/estado", ['estado' => 'inactivo'])->assertStatus(409);
    });
});

describe('asignación a una edición', function (): void {
    it('asigna sede y aula a una edición presencial y lo muestra en el detalle', function (): void {
        $admin = $this->usuarioConRol('admin');
        $sede = nuevaSede();
        $aula = nuevaAula($sede, 'A-101', 30);
        $edicion = nuevaEdicion('presencial', 'borrador', 25);

        $this->panelComo($admin, 'PUT', "academico/convocatorias/{$edicion->identificador}/infraestructura", ['sede' => $sede->identificador, 'aula' => $aula->identificador], revisionDe($edicion))
            ->assertOk()
            ->assertJsonPath('data.infraestructura.sede.codigo', 'CAJ-01')
            ->assertJsonPath('data.infraestructura.aula.capacidad', 30)
            ->assertJsonPath('data.infraestructura.plataforma_virtual', null)
            ->assertJsonPath('data.infraestructura.completa', true);

        expect($edicion->fresh()->revision)->toBe(2)
            ->and(AuditLog::query()->where('action', 'convocatoria_infraestructura_asignada')->count())->toBe(1);
    });

    it('asigna una plataforma a una edición virtual', function (): void {
        $admin = $this->usuarioConRol('admin');
        $plataforma = nuevaPlataforma();
        $edicion = nuevaEdicion('virtual');

        $this->panelComo($admin, 'PUT', "academico/convocatorias/{$edicion->identificador}/infraestructura", ['plataforma_virtual' => $plataforma->identificador], revisionDe($edicion))
            ->assertOk()->assertJsonPath('data.infraestructura.plataforma_virtual.nombre', 'Google Meet');
    });

    it('rechaza combinaciones incoherentes con la modalidad', function (array $datosFn): void {
        $admin = $this->usuarioConRol('admin');
        $sede = nuevaSede();
        $otraSede = nuevaSede('CAJ-02');
        $aula = nuevaAula($sede, 'A-101', 30);
        $aulaPequena = nuevaAula($sede, 'A-102', 10);
        $aulaOtraSede = nuevaAula($otraSede, 'B-1', 30);
        $aulaInactiva = nuevaAula($sede, 'A-103', 30, 'inactivo');
        $plataforma = nuevaPlataforma();
        $inactiva = nuevaPlataforma('Zoom viejo', 'zoom', 'inactivo');
        $contexto = compact('sede', 'aula', 'aulaPequena', 'aulaOtraSede', 'aulaInactiva', 'plataforma', 'inactiva');
        $edicion = nuevaEdicion($datosFn['modalidad'], 'borrador', 20);

        $cuerpo = array_map(fn ($valor) => is_callable($valor) ? $valor($contexto) : $valor, $datosFn['cuerpo']);
        $this->panelComo($admin, 'PUT', "academico/convocatorias/{$edicion->identificador}/infraestructura", $cuerpo, revisionDe($edicion))
            ->assertStatus(422)->assertJsonValidationErrors($datosFn['campo']);

        expect($edicion->fresh()->sede_id)->toBeNull()->and($edicion->fresh()->revision)->toBe(1);
    })->with([
        'presencial sin aula' => [['modalidad' => 'presencial', 'campo' => 'sede', 'cuerpo' => ['sede' => fn ($c) => $c['sede']->identificador]]],
        'presencial sin nada' => [['modalidad' => 'presencial', 'campo' => 'sede', 'cuerpo' => []]],
        'presencial con plataforma' => [['modalidad' => 'presencial', 'campo' => 'plataforma_virtual', 'cuerpo' => ['sede' => fn ($c) => $c['sede']->identificador, 'aula' => fn ($c) => $c['aula']->identificador, 'plataforma_virtual' => fn ($c) => $c['plataforma']->identificador]]],
        'aula de otra sede' => [['modalidad' => 'presencial', 'campo' => 'aula', 'cuerpo' => ['sede' => fn ($c) => $c['sede']->identificador, 'aula' => fn ($c) => $c['aulaOtraSede']->identificador]]],
        'aula con capacidad menor a los cupos' => [['modalidad' => 'presencial', 'campo' => 'aula', 'cuerpo' => ['sede' => fn ($c) => $c['sede']->identificador, 'aula' => fn ($c) => $c['aulaPequena']->identificador]]],
        'aula inactiva' => [['modalidad' => 'presencial', 'campo' => 'aula', 'cuerpo' => ['sede' => fn ($c) => $c['sede']->identificador, 'aula' => fn ($c) => $c['aulaInactiva']->identificador]]],
        'virtual sin plataforma' => [['modalidad' => 'virtual', 'campo' => 'plataforma_virtual', 'cuerpo' => []]],
        'virtual con sede' => [['modalidad' => 'virtual', 'campo' => 'sede', 'cuerpo' => ['sede' => fn ($c) => $c['sede']->identificador, 'plataforma_virtual' => fn ($c) => $c['plataforma']->identificador]]],
        'virtual con plataforma inactiva' => [['modalidad' => 'virtual', 'campo' => 'plataforma_virtual', 'cuerpo' => ['plataforma_virtual' => fn ($c) => $c['inactiva']->identificador]]],
        'modalidad histórica' => [['modalidad' => 'hibrido', 'campo' => 'modalidad', 'cuerpo' => ['plataforma_virtual' => fn ($c) => $c['plataforma']->identificador]]],
    ]);

    it('exige la revisión, rechaza una obsoleta y no admite ediciones cerradas', function (): void {
        $admin = $this->usuarioConRol('admin');
        $plataforma = nuevaPlataforma();
        $edicion = nuevaEdicion('virtual');
        $ruta = "academico/convocatorias/{$edicion->identificador}/infraestructura";

        $this->panelComo($admin, 'PUT', $ruta, ['plataforma_virtual' => $plataforma->identificador])->assertStatus(428);
        $this->panelComo($admin, 'PUT', $ruta, ['plataforma_virtual' => $plataforma->identificador], ['If-Match' => '"convocatoria-'.$edicion->identificador.'-v9"'])->assertStatus(412);
        $edicion->forceFill(['estado' => 'cerrada'])->save();
        $this->panelComo($admin, 'PUT', $ruta, ['plataforma_virtual' => $plataforma->identificador], revisionDe($edicion))->assertStatus(409);
    });

    it('repetir la misma asignación no avanza la revisión ni audita de nuevo', function (): void {
        $admin = $this->usuarioConRol('admin');
        $plataforma = nuevaPlataforma();
        $edicion = nuevaEdicion('virtual');
        $ruta = "academico/convocatorias/{$edicion->identificador}/infraestructura";

        $this->panelComo($admin, 'PUT', $ruta, ['plataforma_virtual' => $plataforma->identificador], revisionDe($edicion))->assertOk();
        $this->panelComo($admin, 'PUT', $ruta, ['plataforma_virtual' => $plataforma->identificador], revisionDe($edicion))->assertOk();

        expect($edicion->fresh()->revision)->toBe(2)->and(AuditLog::where('action', 'convocatoria_infraestructura_asignada')->count())->toBe(1);
    });

    it('exige convocatorias.editar: la secretaria no asigna', function (): void {
        $secretaria = $this->usuarioConRol('secretaria');
        $edicion = nuevaEdicion('virtual');

        $this->panelComo($secretaria, 'PUT', "academico/convocatorias/{$edicion->identificador}/infraestructura", [], revisionDe($edicion))->assertForbidden();
    });
});

describe('reglas de las ediciones con infraestructura', function (): void {
    it('las ediciones nuevas solo pueden ser presenciales o virtuales; las históricas se conservan', function (): void {
        $admin = $this->usuarioConRol('admin');
        $curso = Curso::create(['codigo' => 'NUEVO-1', 'nombre' => 'Curso', 'descripcion' => 'x', 'categoria' => 'tecnologia', 'duracion_horas' => 10,
            'precio' => 100, 'modalidad' => 'virtual', 'horario' => 'Lun', 'cupos_maximos' => 5, 'cupos_disponibles' => 5, 'estado' => 'activo']);
        $cuerpo = ['curso_id' => $curso->id, 'fecha_inicio' => now()->addDay()->toDateString(), 'fecha_fin' => now()->addMonth()->toDateString(), 'cupos_maximos' => 10];

        foreach (['hibrido', 'semipresencial'] as $historica) {
            $this->panelComo($admin, 'POST', 'academico/convocatorias', [...$cuerpo, 'modalidad' => $historica])->assertStatus(422)->assertJsonValidationErrors('modalidad');
        }
        $r = $this->panelComo($admin, 'POST', 'academico/convocatorias', [...$cuerpo, 'modalidad' => 'presencial'])->assertCreated();
        expect(Convocatoria::where('identificador', $r->json('data.identificador'))->value('exige_infraestructura'))->toBeTruthy();

        // Una edición histórica existente sigue editándose y abriéndose sin infraestructura.
        $historica = nuevaEdicion('hibrido', 'borrador');
        $this->panelComo($admin, 'POST', "academico/convocatorias/{$historica->identificador}/estado", ['estado' => 'abierta'], revisionDe($historica))->assertOk();
    });

    it('una edición nueva no se abre sin su infraestructura y sí con ella', function (string $modalidad): void {
        $admin = $this->usuarioConRol('admin');
        $edicion = nuevaEdicion($modalidad, 'borrador', 20, exige: true);
        $ruta = "academico/convocatorias/{$edicion->identificador}/estado";

        $this->panelComo($admin, 'POST', $ruta, ['estado' => 'abierta'], revisionDe($edicion))->assertStatus(422)->assertJsonValidationErrors('estado');
        expect($edicion->fresh()->estado)->toBe('borrador');

        if ($modalidad === 'presencial') {
            $sede = nuevaSede();
            $datos = ['sede' => $sede->identificador, 'aula' => nuevaAula($sede, 'A-1', 30)->identificador];
        } else {
            $datos = ['plataforma_virtual' => nuevaPlataforma()->identificador];
        }
        $this->panelComo($admin, 'PUT', "academico/convocatorias/{$edicion->identificador}/infraestructura", $datos, revisionDe($edicion))->assertOk();
        $this->panelComo($admin, 'POST', $ruta, ['estado' => 'abierta'], revisionDe($edicion))->assertOk()->assertJsonPath('data.estado', 'abierta');
    })->with(['presencial', 'virtual']);

    it('no permite cupos mayores que la capacidad del aula ni cambiar de modalidad con infraestructura incompatible', function (): void {
        $admin = $this->usuarioConRol('admin');
        $sede = nuevaSede();
        $aula = nuevaAula($sede, 'A-1', 25);
        $edicion = nuevaEdicion('presencial', 'borrador', 20);
        $edicion->forceFill(['sede_id' => $sede->id, 'aula_id' => $aula->id])->save();
        $base = ['fecha_inicio' => $edicion->fecha_inicio->toDateString(), 'fecha_fin' => $edicion->fecha_fin->toDateString()];
        $ruta = "academico/convocatorias/{$edicion->identificador}";

        $this->panelComo($admin, 'PUT', $ruta, [...$base, 'modalidad' => 'presencial', 'cupos_maximos' => 26], revisionDe($edicion))->assertStatus(422)->assertJsonValidationErrors('cupos_maximos');
        $this->panelComo($admin, 'PUT', $ruta, [...$base, 'modalidad' => 'presencial', 'cupos_maximos' => 25], revisionDe($edicion))->assertOk();
        $this->panelComo($admin, 'PUT', $ruta, [...$base, 'modalidad' => 'virtual', 'cupos_maximos' => 25], revisionDe($edicion))->assertStatus(422)->assertJsonValidationErrors('modalidad');
        $this->panelComo($admin, 'PUT', $ruta, [...$base, 'modalidad' => 'hibrido', 'cupos_maximos' => 25], revisionDe($edicion))->assertStatus(422)->assertJsonValidationErrors('modalidad');
        expect($edicion->fresh()->modalidad)->toBe('presencial');
    });

    it('conserva el comportamiento de las ediciones existentes: se editan, abren y matriculan sin infraestructura', function (): void {
        $admin = $this->usuarioConRol('admin');
        $edicion = nuevaEdicion('presencial', 'borrador', 20);
        $base = ['modalidad' => 'presencial', 'fecha_inicio' => $edicion->fecha_inicio->toDateString(), 'fecha_fin' => $edicion->fecha_fin->toDateString()];

        $this->panelComo($admin, 'PUT', "academico/convocatorias/{$edicion->identificador}", [...$base, 'cupos_maximos' => 40], revisionDe($edicion))->assertOk();
        $this->panelComo($admin, 'POST', "academico/convocatorias/{$edicion->identificador}/estado", ['estado' => 'abierta'], revisionDe($edicion))->assertOk();
    });
});
