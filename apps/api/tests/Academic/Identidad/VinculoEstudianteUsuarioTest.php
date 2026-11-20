<?php

declare(strict_types=1);

use App\Models\Estudiante;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Horus Academic, Fase 2: vínculo OPCIONAL y ÚNICO entre `estudiantes` y `users` (migración 000061).
 * No hay portal ni endpoint de estudiante: solo se prueba el esquema, las relaciones y que nada pueda fijar el vínculo
 * por la API existente. SQLite en memoria (no prueba PostgreSQL: ver README de la fase).
 */

function nuevoEstudiante(string $dni, array $extra = []): Estudiante
{
    return Estudiante::create([
        'dni' => $dni, 'nombres' => 'Ana', 'apellidos' => 'Ficticia', 'estado' => 'activo', ...$extra,
    ]);
}

it('crea la columna user_id nula, con clave foránea ON DELETE SET NULL e índice único', function (): void {
    expect(Schema::hasColumn('estudiantes', 'user_id'))->toBeTrue();

    $columna = collect(Schema::getColumns('estudiantes'))->firstWhere('name', 'user_id');
    expect($columna['nullable'])->toBeTrue();

    $unico = collect(Schema::getIndexes('estudiantes'))->first(fn (array $i): bool => $i['columns'] === ['user_id'] && $i['unique']);
    expect($unico)->not->toBeNull();

    $fk = collect(Schema::getForeignKeys('estudiantes'))->first(fn (array $f): bool => $f['columns'] === ['user_id']);
    expect($fk)->not->toBeNull()
        ->and($fk['foreign_table'])->toBe('users')
        ->and(strtolower((string) $fk['on_delete']))->toBe('set null');
});

it('deja a los expedientes existentes sin cuenta y admite muchos expedientes sin vínculo', function (): void {
    $a = nuevoEstudiante('70000001');
    $b = nuevoEstudiante('70000002');
    $c = nuevoEstudiante('70000003');

    expect($a->fresh()->user_id)->toBeNull()
        ->and($b->fresh()->user_id)->toBeNull()
        ->and($c->fresh()->user_id)->toBeNull()
        ->and($a->user)->toBeNull();
});

it('vincula una cuenta con su expediente en ambos sentidos', function (): void {
    $usuario = User::factory()->create();
    $estudiante = nuevoEstudiante('70000004');

    $estudiante->forceFill(['user_id' => $usuario->id])->save();

    expect($estudiante->fresh()->user->is($usuario))->toBeTrue()
        ->and($usuario->fresh()->estudiante->is($estudiante))->toBeTrue();
});

it('rechaza vincular la misma cuenta a dos expedientes (un usuario, un expediente)', function (): void {
    $usuario = User::factory()->create();
    nuevoEstudiante('70000005')->forceFill(['user_id' => $usuario->id])->save();
    $otro = nuevoEstudiante('70000006');

    // En un SAVEPOINT: en PostgreSQL un error aborta la transacción de la prueba si no se aísla.
    expect(fn () => DB::transaction(fn () => $otro->forceFill(['user_id' => $usuario->id])->save()))->toThrow(QueryException::class);

    expect($otro->fresh()->user_id)->toBeNull();
});

it('no permite fijar user_id por asignación masiva', function (): void {
    $usuario = User::factory()->create();

    $estudiante = nuevoEstudiante('70000007', ['user_id' => $usuario->id]);
    expect($estudiante->fresh()->user_id)->toBeNull();

    $estudiante->update(['user_id' => $usuario->id]);
    expect($estudiante->fresh()->user_id)->toBeNull();
});

it('conserva el expediente y lo deja sin cuenta cuando se elimina el usuario', function (): void {
    $usuario = User::factory()->create();
    $estudiante = nuevoEstudiante('70000008');
    $estudiante->forceFill(['user_id' => $usuario->id])->save();

    $usuario->delete();

    $estudiante->refresh();
    expect($estudiante->exists)->toBeTrue()
        ->and($estudiante->user_id)->toBeNull()
        ->and(DB::table('estudiantes')->where('dni', '70000008')->exists())->toBeTrue();
});

it('no expone el vínculo en la API del panel ni en la serialización del modelo', function (): void {
    $admin = $this->usuarioConRol('admin');
    $cuenta = User::factory()->create(['email' => 'cuenta-vinculada@example.invalid']);
    $estudiante = nuevoEstudiante('70000009');
    $estudiante->forceFill(['user_id' => $cuenta->id])->save();

    expect($estudiante->fresh()->toArray())->not->toHaveKey('user_id');

    $detalle = $this->panelComo($admin, 'GET', 'academico/estudiantes/'.$estudiante->id)->assertOk();
    $listado = $this->panelComo($admin, 'GET', 'academico/estudiantes')->assertOk();

    foreach ([$detalle, $listado] as $respuesta) {
        $texto = (string) $respuesta->getContent();
        expect($texto)->not->toContain('user_id')->not->toContain('cuenta-vinculada@example.invalid');
    }
});

it('no puede vincularse desde la API del panel: el alta y la edición ignoran user_id', function (): void {
    $admin = $this->usuarioConRol('admin');
    $cuenta = User::factory()->create();

    $creado = $this->panelComo($admin, 'POST', 'academico/estudiantes', [
        'dni' => '70000010', 'nombres' => 'Luis', 'apellidos' => 'Ficticio', 'estado' => 'activo', 'user_id' => $cuenta->id,
    ])->assertSuccessful();

    $id = (int) $creado->json('data.id');
    expect(Estudiante::query()->findOrFail($id)->user_id)->toBeNull();

    $this->panelComo($admin, 'PUT', 'academico/estudiantes/'.$id, [
        'dni' => '70000010', 'nombres' => 'Luis', 'apellidos' => 'Ficticio', 'estado' => 'activo', 'user_id' => $cuenta->id,
    ]);
    expect(Estudiante::query()->findOrFail($id)->user_id)->toBeNull();
});

it('el registro público nunca crea un expediente ni lo vincula', function (): void {
    $this->postJson(route('api.auth.register'), [
        'name' => 'Visitante', 'email' => 'visitante@example.invalid',
        'password' => 'ClaveSegura123', 'password_confirmation' => 'ClaveSegura123',
    ])->assertCreated();

    $usuario = User::query()->where('email', 'visitante@example.invalid')->firstOrFail();
    expect($usuario->estudiante)->toBeNull()
        ->and($usuario->roles)->toHaveCount(0)
        ->and(Estudiante::query()->count())->toBe(0);
});
