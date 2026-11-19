<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Convocatoria;
use App\Models\Curso;
use App\Models\Docente;
use App\Models\Estudiante;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Horus Academic, Fase 4: docentes (solo DNI peruano) y su asignación a ediciones. SQLite en memoria: el CHECK del DNI, el bloqueo
 * consultivo por DNI (`pg_advisory_xact_lock`) y las carreras entre altas simultáneas son de PostgreSQL y NO se prueban aquí;
 * las reglas se prueban en el servicio, que es la barrera en SQLite y la primera en PostgreSQL.
 */

function nuevoDocente(string $dni = '40000001', array $extra = []): Docente
{
    $d = new Docente(['nombres' => 'Rosa', 'apellidos' => 'Quispe Soto', 'email' => null, 'telefono' => null, 'especialidad' => 'Redes', ...$extra]);
    $d->identificador = (string) Str::uuid();
    $d->dni = $dni;
    $d->estado = $extra['estado'] ?? 'activo';
    $d->save();

    return $d;
}

function edicionParaDocentes(string $estado = 'borrador'): Convocatoria
{
    static $n = 0;
    $n++;
    $curso = Curso::create(['codigo' => 'DOC-'.$n, 'nombre' => 'Curso '.$n, 'descripcion' => 'x', 'categoria' => 'tecnologia', 'duracion_horas' => 10,
        'precio' => 100, 'modalidad' => 'virtual', 'horario' => 'Lun', 'cupos_maximos' => 5, 'cupos_disponibles' => 5, 'estado' => 'activo']);
    $c = new Convocatoria(['nombre' => null, 'modalidad' => 'virtual', 'fecha_inicio' => now()->addDay()->toDateString(),
        'fecha_fin' => now()->addMonth()->toDateString(), 'cupos_maximos' => 20]);
    $c->identificador = (string) Str::uuid();
    $c->codigo = 'CNV-2026-9'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    $c->curso_id = $curso->id;
    $c->estado = $estado;
    $c->cupos_disponibles = 20;
    $c->revision = 1;
    $c->save();

    return $c;
}

/** @return array<string, string> */
function revisionDocentes(Convocatoria $c): array
{
    return ['If-Match' => $c->fresh()->etag()];
}

function asignar(Docente $d, Convocatoria $c, string $rol = 'principal'): void
{
    DB::table('convocatoria_docentes')->insert(['convocatoria_id' => $c->id, 'docente_id' => $d->id, 'rol' => $rol, 'created_at' => now(), 'updated_at' => now()]);
}

it('crea las tablas con DNI único y a lo sumo un principal por edición', function (): void {
    expect(Schema::hasTable('docentes'))->toBeTrue()->and(Schema::hasTable('convocatoria_docentes'))->toBeTrue();
    $unicos = collect(Schema::getIndexes('docentes'))->filter(fn ($i) => $i['unique'])->pluck('columns')->all();
    expect($unicos)->toContain(['dni'])->toContain(['email'])->toContain(['user_id']);

    $edicion = edicionParaDocentes();
    $a = nuevoDocente('40000001');
    $b = nuevoDocente('40000002');
    asignar($a, $edicion, 'principal');
    // En un SAVEPOINT: en PostgreSQL un error aborta la transacción de la prueba si no se aísla.
    expect(fn () => DB::transaction(fn () => asignar($b, $edicion, 'principal')))->toThrow(QueryException::class);
    asignar($b, $edicion, 'apoyo'); // un apoyo sí
    expect(DB::table('convocatoria_docentes')->where('convocatoria_id', $edicion->id)->count())->toBe(2);
});

it('los permisos se conceden por rol y la migración equivale al seeder sin resincronizar', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = $this->usuarioConRol('admin', 'a@example.invalid');
    $coord = $this->usuarioConRol('coordinador', 'c@example.invalid');
    $sec = $this->usuarioConRol('secretaria', 's@example.invalid');
    foreach (RolesAndPermissionsSeeder::DOCENTES_PERMISSIONS as $p) {
        expect($admin->can($p))->toBeTrue();
    }
    expect($coord->can('docentes.crear'))->toBeTrue()->and($coord->can('docentes.editar'))->toBeTrue()
        ->and($coord->can('docentes.eliminar'))->toBeFalse()->and($coord->can('docentes.restaurar'))->toBeFalse()
        ->and($sec->can('docentes.ver'))->toBeTrue()->and($sec->can('docentes.crear'))->toBeFalse();

    Permission::query()->whereIn('name', RolesAndPermissionsSeeder::DOCENTES_PERMISSIONS)->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $ajeno = Permission::findOrCreate('permiso.concedido.a.mano', 'web');
    Role::findByName('coordinador', 'web')->givePermissionTo($ajeno);

    $migracion = require database_path('migrations/2026_11_16_000067_add_docentes_permissions.php');
    $migracion->up();
    $migracion->up();

    $coordinador = Role::findByName('coordinador', 'web')->fresh();
    expect($coordinador->hasPermissionTo('docentes.editar'))->toBeTrue()->and($coordinador->hasPermissionTo('docentes.eliminar'))->toBeFalse()
        ->and($coordinador->hasPermissionTo('permiso.concedido.a.mano'))->toBeTrue();
    $migracion->down();
    expect(Permission::query()->whereIn('name', RolesAndPermissionsSeeder::DOCENTES_PERMISSIONS)->count())->toBe(0);
});

it('aplica la matriz de roles a las rutas de docentes', function (string $metodo, string $ruta, string $permiso): void {
    $docente = nuevoDocente();
    $ruta = str_replace('{docente}', $docente->identificador, $ruta);
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->panelComo(null, $metodo, $ruta)->assertUnauthorized();
    $this->panelComo(User::factory()->create(), $metodo, $ruta)->assertForbidden();
    foreach (['secretaria' => RolesAndPermissionsSeeder::SECRETARIA_PERMISSIONS, 'coordinador' => RolesAndPermissionsSeeder::COORDINADOR_PERMISSIONS, 'admin' => RolesAndPermissionsSeeder::PERMISSIONS] as $rol => $permisos) {
        $estado = $this->panelComo($this->usuarioConRol($rol, "{$rol}-".md5($metodo.$ruta).'@example.invalid'), $metodo, $ruta)->getStatusCode();
        in_array($permiso, $permisos, true) ? expect($estado)->not->toBe(403) : expect($estado)->toBe(403);
    }
})->with([
    'listar' => ['GET', 'academico/docentes', 'docentes.ver'],
    'ver' => ['GET', 'academico/docentes/{docente}', 'docentes.ver'],
    'crear' => ['POST', 'academico/docentes', 'docentes.crear'],
    'editar' => ['PUT', 'academico/docentes/{docente}', 'docentes.editar'],
    'restaurar' => ['POST', 'academico/docentes/{docente}/restaurar', 'docentes.restaurar'],
    'eliminar' => ['DELETE', 'academico/docentes/{docente}', 'docentes.eliminar'],
]);

describe('alta y edición', function (): void {
    it('registra un docente solo con DNI, normaliza datos y no expone ids internos', function (): void {
        $admin = $this->usuarioConRol('admin');

        $r = $this->panelComo($admin, 'POST', 'academico/docentes', [
            'dni' => '27427864', 'nombres' => ' Ana María ', 'apellidos' => 'Quispe Soto', 'email' => ' ANA@Example.INVALID ', 'telefono' => '+51 976 543 210', 'especialidad' => 'Contabilidad',
        ])->assertCreated()->assertJsonPath('data.dni', '27427864')->assertJsonPath('data.nombres', 'Ana María')
            ->assertJsonPath('data.email', 'ana@example.invalid')->assertJsonPath('data.estado', 'activo')->assertJsonPath('data.eliminado', false);

        expect($r->json('data'))->not->toHaveKey('id')->not->toHaveKey('user_id');
        expect(Docente::query()->first()->user_id)->toBeNull();
        expect(AuditLog::where('action', 'docente_creado')->count())->toBe(1);
    });

    it('exige un DNI de exactamente 8 dígitos como texto y no admite otros documentos', function (mixed $dni): void {
        $admin = $this->usuarioConRol('admin');

        $this->panelComo($admin, 'POST', 'academico/docentes', ['dni' => $dni, 'nombres' => 'Ana', 'apellidos' => 'Quispe'])
            ->assertStatus(422)->assertJsonValidationErrors('dni');
        expect(Docente::count())->toBe(0);
    })->with(['corto' => ['1234567'], 'largo' => ['123456789'], 'letras' => ['1234567A'], 'espacios' => [' 2742786'], 'vacío' => [''], 'nulo' => [null], 'numérico' => [27427864], 'arreglo' => [['27427864']], 'pasaporte' => ['AB123456'], 'carné de extranjería' => ['000123456789']]);

    it('rechaza campos que fija el servidor y un DNI repetido o ya usado por un estudiante', function (): void {
        $admin = $this->usuarioConRol('admin');
        $base = ['dni' => '40000001', 'nombres' => 'Rosa', 'apellidos' => 'Quispe'];
        $this->panelComo($admin, 'POST', 'academico/docentes', $base)->assertCreated();

        $this->panelComo($admin, 'POST', 'academico/docentes', $base)->assertStatus(422)->assertJsonValidationErrors('dni');
        $this->panelComo($admin, 'POST', 'academico/docentes', [...$base, 'dni' => '40000002', 'estado' => 'inactivo', 'identificador' => (string) Str::uuid(), 'user_id' => $admin->id])
            ->assertStatus(422)->assertJsonValidationErrors(['estado', 'identificador', 'user_id']);

        // Un estudiante (incluso eliminado) ocupa el DNI: no puede haber la misma persona en las dos tablas.
        Estudiante::create(['dni' => '40000003', 'nombres' => 'Luis', 'apellidos' => 'Pérez', 'estado' => 'activo']);
        Estudiante::create(['dni' => '40000004', 'nombres' => 'Eva', 'apellidos' => 'Mora', 'estado' => 'activo'])->delete();
        foreach (['40000003', '40000004'] as $dni) {
            $this->panelComo($admin, 'POST', 'academico/docentes', [...$base, 'dni' => $dni])
                ->assertStatus(422)->assertJsonValidationErrors('dni')->assertJsonPath('errors.dni.0', 'Este DNI ya está registrado como estudiante.');
        }
        expect(Docente::count())->toBe(1);
    });

    it('impide registrar o cambiar a estudiante un DNI que ya es de un docente', function (): void {
        $admin = $this->usuarioConRol('admin');
        nuevoDocente('40000001')->delete();   // un docente eliminado también ocupa el DNI
        nuevoDocente('40000002');

        foreach (['40000001', '40000002'] as $dni) {
            $this->panelComo($admin, 'POST', 'academico/estudiantes', ['dni' => $dni, 'nombres' => 'Ana', 'apellidos' => 'Quispe', 'estado' => 'activo'])
                ->assertStatus(422)->assertJsonValidationErrors('dni')->assertJsonPath('errors.dni.0', 'Este DNI ya está registrado como docente.');
        }
        $estudiante = Estudiante::create(['dni' => '40000009', 'nombres' => 'Ana', 'apellidos' => 'Quispe', 'estado' => 'activo']);
        $this->panelComo($admin, 'PUT', 'academico/estudiantes/'.$estudiante->id, ['dni' => '40000002', 'nombres' => 'Ana', 'apellidos' => 'Quispe', 'estado' => 'activo'])
            ->assertStatus(422)->assertJsonPath('errors.dni.0', 'Este DNI ya está registrado como docente.');
        expect($estudiante->fresh()->dni)->toBe('40000009');

        // Lo normal sigue funcionando.
        $this->panelComo($admin, 'POST', 'academico/estudiantes', ['dni' => '40000010', 'nombres' => 'Ana', 'apellidos' => 'Quispe', 'estado' => 'activo'])->assertSuccessful();
    });

    it('edita datos pero el DNI es inmutable, y valida el correo sin distinguir mayúsculas', function (): void {
        $admin = $this->usuarioConRol('admin');
        $a = nuevoDocente('40000001', ['email' => 'rosa@example.invalid']);
        $b = nuevoDocente('40000002');
        $ruta = 'academico/docentes/'.$b->identificador;
        $base = ['nombres' => 'Luis', 'apellidos' => 'Pérez'];

        $this->panelComo($admin, 'PUT', $ruta, [...$base, 'dni' => '40000099'])->assertStatus(422)->assertJsonValidationErrors('dni');
        $this->panelComo($admin, 'PUT', $ruta, [...$base, 'email' => 'ROSA@example.invalid'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->panelComo($admin, 'PUT', $ruta, [...$base, 'email' => 'luis@example.invalid', 'especialidad' => 'Redes', 'estado' => 'activo'])
            ->assertOk()->assertJsonPath('data.nombres', 'Luis')->assertJsonPath('data.dni', '40000002');
        expect($b->fresh()->dni)->toBe('40000002')->and($a->fresh()->email)->toBe('rosa@example.invalid');
    });

    it('el listado minimiza datos y el detalle los incluye', function (): void {
        $admin = $this->usuarioConRol('admin');
        $d = nuevoDocente('40000001', ['email' => 'rosa@example.invalid', 'telefono' => '999888777']);

        $lista = $this->panelComo($admin, 'GET', 'academico/docentes')->assertOk()->assertJsonPath('meta.total', 1);
        expect((string) $lista->getContent())->not->toContain('rosa@example.invalid')->not->toContain('999888777');
        $this->panelComo($admin, 'GET', 'academico/docentes/'.$d->identificador)->assertOk()->assertJsonPath('data.email', 'rosa@example.invalid')->assertJsonPath('data.telefono', '999888777');
        $this->panelComo($admin, 'GET', 'academico/docentes?busqueda=40000001')->assertJsonPath('meta.total', 1);
        $this->panelComo($admin, 'GET', 'academico/docentes?busqueda=zzz')->assertJsonPath('meta.total', 0);
        $this->panelComo($admin, 'GET', 'academico/docentes/no-es-uuid')->assertNotFound();
    });

    it('no guarda DNI, correo, teléfono ni nombres en la auditoría', function (): void {
        $admin = $this->usuarioConRol('admin');
        $r = $this->panelComo($admin, 'POST', 'academico/docentes', ['dni' => '27427864', 'nombres' => 'Ana María', 'apellidos' => 'Quispe', 'email' => 'ana@example.invalid', 'telefono' => '999888777'])->assertCreated();
        $this->panelComo($admin, 'PUT', 'academico/docentes/'.$r->json('data.identificador'), ['nombres' => 'Ana Luisa', 'apellidos' => 'Quispe', 'email' => 'ana2@example.invalid', 'telefono' => '999888777'])->assertOk();

        $audit = (string) json_encode(AuditLog::query()->whereIn('action', ['docente_creado', 'docente_actualizado'])->get()->map->getAttributes()->all());
        foreach (['27427864', 'Ana María', 'Ana Luisa', 'ana@example.invalid', 'ana2@example.invalid', '999888777'] as $dato) {
            expect($audit)->not->toContain($dato);
        }
        expect(AuditLog::where('action', 'docente_actualizado')->count())->toBe(1);
    });
});

describe('baja y restauración', function (): void {
    it('elimina lógicamente, conserva el DNI y solo el admin restaura', function (): void {
        $admin = $this->usuarioConRol('admin');
        $coord = $this->usuarioConRol('coordinador', 'coord@example.invalid');
        $d = nuevoDocente();
        $ruta = 'academico/docentes/'.$d->identificador;

        $this->panelComo($coord, 'DELETE', $ruta)->assertForbidden();
        $this->panelComo($admin, 'DELETE', $ruta)->assertOk()->assertJsonPath('data.eliminado', true);
        expect(Docente::withTrashed()->count())->toBe(1)->and(Docente::count())->toBe(0);

        // El coordinador ya no lo ve; el admin sí (puede restaurarlo).
        $this->panelComo($coord, 'GET', $ruta)->assertNotFound();
        $this->panelComo($admin, 'GET', 'academico/docentes?eliminados=1')->assertJsonPath('meta.total', 1);
        $this->panelComo($coord, 'GET', 'academico/docentes?eliminados=1')->assertJsonPath('meta.total', 0);
        $this->panelComo($admin, 'PUT', $ruta, ['nombres' => 'X', 'apellidos' => 'Y'])->assertStatus(409);
        // Su DNI sigue ocupado: no se vuelve a registrar, se restaura.
        $this->panelComo($admin, 'POST', 'academico/docentes', ['dni' => '40000001', 'nombres' => 'Otra', 'apellidos' => 'Persona'])->assertStatus(422)->assertJsonValidationErrors('dni');
        $this->panelComo($coord, 'POST', $ruta.'/restaurar')->assertForbidden();
        $this->panelComo($admin, 'POST', $ruta.'/restaurar')->assertOk()->assertJsonPath('data.eliminado', false);
        expect(Docente::count())->toBe(1);
    });

    it('no elimina ni inactiva a un docente asignado a ediciones vigentes', function (): void {
        $admin = $this->usuarioConRol('admin');
        $d = nuevoDocente();
        $edicion = edicionParaDocentes('abierta');
        asignar($d, $edicion);
        $ruta = 'academico/docentes/'.$d->identificador;

        $this->panelComo($admin, 'DELETE', $ruta)->assertStatus(409);
        $this->panelComo($admin, 'PUT', $ruta, ['nombres' => 'Rosa', 'apellidos' => 'Quispe', 'estado' => 'inactivo'])->assertStatus(409);
        expect($d->fresh()->estado)->toBe('activo')->and($d->fresh()->trashed())->toBeFalse();

        $edicion->forceFill(['estado' => 'cerrada'])->save();
        $this->panelComo($admin, 'PUT', $ruta, ['nombres' => 'Rosa', 'apellidos' => 'Quispe', 'estado' => 'inactivo'])->assertOk();
        $this->panelComo($admin, 'DELETE', $ruta)->assertOk();
    });
});

describe('asignación a una edición', function (): void {
    it('reemplaza los docentes con un principal y apoyos, y lo muestra', function (): void {
        $admin = $this->usuarioConRol('admin');
        [$a, $b, $c] = [nuevoDocente('40000001'), nuevoDocente('40000002'), nuevoDocente('40000003')];
        $edicion = edicionParaDocentes();
        $ruta = "academico/convocatorias/{$edicion->identificador}/docentes";

        $this->panelComo($admin, 'PUT', $ruta, ['docentes' => [
            ['docente' => $a->identificador, 'rol' => 'principal'], ['docente' => $b->identificador, 'rol' => 'apoyo'],
        ]], revisionDocentes($edicion))->assertOk()->assertJsonCount(2, 'data.docentes')->assertJsonPath('data.docentes.0.rol', 'apoyo');
        expect($edicion->fresh()->revision)->toBe(2);

        $this->panelComo($admin, 'GET', $ruta)->assertOk()->assertJsonCount(2, 'data.docentes');
        // Reemplazo completo: queda solo c.
        $this->panelComo($admin, 'PUT', $ruta, ['docentes' => [['docente' => $c->identificador, 'rol' => 'principal']]], revisionDocentes($edicion))->assertOk()->assertJsonCount(1, 'data.docentes');
        // Vaciar la lista es válido.
        $this->panelComo($admin, 'PUT', $ruta, ['docentes' => []], revisionDocentes($edicion))->assertOk()->assertJsonCount(0, 'data.docentes');
        expect(AuditLog::where('action', 'convocatoria_docentes_asignados')->count())->toBe(3);
    });

    it('rechaza dos principales, repetidos, roles inválidos, inactivos, eliminados e inexistentes', function (array $caso): void {
        $admin = $this->usuarioConRol('admin');
        $a = nuevoDocente('40000001');
        $b = nuevoDocente('40000002');
        $inactivo = nuevoDocente('40000003', ['estado' => 'inactivo']);
        $eliminado = nuevoDocente('40000004');
        $eliminado->delete();
        $edicion = edicionParaDocentes();
        $ctx = compact('a', 'b', 'inactivo', 'eliminado');

        $cuerpo = ['docentes' => array_map(fn (array $f) => ['docente' => $f[0]($ctx), 'rol' => $f[1]], $caso['filas'])];
        $this->panelComo($admin, 'PUT', "academico/convocatorias/{$edicion->identificador}/docentes", $cuerpo, revisionDocentes($edicion))
            ->assertStatus(422)->assertJsonValidationErrors($caso['campo']);
        expect($edicion->fresh()->revision)->toBe(1)->and(DB::table('convocatoria_docentes')->count())->toBe(0);
    })->with([
        'dos principales' => [['campo' => 'docentes', 'filas' => [[fn ($c) => $c['a']->identificador, 'principal'], [fn ($c) => $c['b']->identificador, 'principal']]]],
        'repetido' => [['campo' => 'docentes.1.docente', 'filas' => [[fn ($c) => $c['a']->identificador, 'principal'], [fn ($c) => $c['a']->identificador, 'apoyo']]]],
        'rol inválido' => [['campo' => 'docentes.0.rol', 'filas' => [[fn ($c) => $c['a']->identificador, 'jefe']]]],
        'inactivo' => [['campo' => 'docentes.0.docente', 'filas' => [[fn ($c) => $c['inactivo']->identificador, 'principal']]]],
        'eliminado' => [['campo' => 'docentes.0.docente', 'filas' => [[fn ($c) => $c['eliminado']->identificador, 'principal']]]],
        'inexistente' => [['campo' => 'docentes.0.docente', 'filas' => [[fn () => (string) Str::uuid(), 'principal']]]],
    ]);

    it('exige la revisión, rechaza una obsoleta, no admite ediciones cerradas ni más de 10 docentes', function (): void {
        $admin = $this->usuarioConRol('admin');
        $a = nuevoDocente();
        $edicion = edicionParaDocentes();
        $ruta = "academico/convocatorias/{$edicion->identificador}/docentes";
        $cuerpo = ['docentes' => [['docente' => $a->identificador, 'rol' => 'principal']]];

        $this->panelComo($admin, 'PUT', $ruta, $cuerpo)->assertStatus(428);
        $this->panelComo($admin, 'PUT', $ruta, $cuerpo, ['If-Match' => '"convocatoria-'.$edicion->identificador.'-v9"'])->assertStatus(412);
        $once = array_map(fn ($i) => ['docente' => (string) Str::uuid(), 'rol' => 'apoyo'], range(1, 11));
        $this->panelComo($admin, 'PUT', $ruta, ['docentes' => $once], revisionDocentes($edicion))->assertStatus(422)->assertJsonValidationErrors('docentes');
        $edicion->forceFill(['estado' => 'cerrada'])->save();
        $this->panelComo($admin, 'PUT', $ruta, $cuerpo, revisionDocentes($edicion))->assertStatus(409);
    });

    it('repetir la misma lista no avanza la revisión ni audita de nuevo', function (): void {
        $admin = $this->usuarioConRol('admin');
        $a = nuevoDocente();
        $edicion = edicionParaDocentes();
        $ruta = "academico/convocatorias/{$edicion->identificador}/docentes";
        $cuerpo = ['docentes' => [['docente' => $a->identificador, 'rol' => 'principal']]];

        $this->panelComo($admin, 'PUT', $ruta, $cuerpo, revisionDocentes($edicion))->assertOk();
        $this->panelComo($admin, 'PUT', $ruta, $cuerpo, revisionDocentes($edicion))->assertOk();

        expect($edicion->fresh()->revision)->toBe(2)->and(AuditLog::where('action', 'convocatoria_docentes_asignados')->count())->toBe(1);
    });

    it('la secretaria consulta pero no asigna', function (): void {
        $sec = $this->usuarioConRol('secretaria');
        $edicion = edicionParaDocentes();
        $ruta = "academico/convocatorias/{$edicion->identificador}/docentes";

        $this->panelComo($sec, 'GET', $ruta)->assertOk();
        $this->panelComo($sec, 'PUT', $ruta, ['docentes' => []], revisionDocentes($edicion))->assertForbidden();
    });
});

describe('consulta de DNI para docentes', function (): void {
    it('informa si el DNI ya es docente o estudiante según lo que la persona puede ver', function (): void {
        config(['services.jsonpe.token' => 'token-falso-0123456789', 'services.jsonpe.url' => 'https://api.json.pe/api/dni']);
        Cache::flush();
        Http::preventStrayRequests();
        Http::fake(['api.json.pe/*' => Http::response(['success' => true, 'data' => ['numero' => '40000001', 'nombres' => 'ROSA', 'apellido_paterno' => 'QUISPE', 'apellido_materno' => 'SOTO']])]);
        nuevoDocente('40000001')->delete();

        $admin = $this->usuarioConRol('admin');
        $this->panelComo($admin, 'POST', 'academico/consultas/dni', ['dni' => '40000001'])->assertOk()
            ->assertJsonPath('data.ya_registrado_como_docente', true)->assertJsonPath('data.ya_registrado_como_estudiante', false);
    });
});
