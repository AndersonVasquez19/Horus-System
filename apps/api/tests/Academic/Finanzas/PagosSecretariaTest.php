<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Pagos, decisión del propietario (20/11/2026): secretaría y coordinación suben los comprobantes (`pagos.ver` + `pagos.registrar`);
 * confirmar, reversar, planificar y exportar siguen solo en admin. El coordinador los recibe también para conservar el tope de
 * roles (un no admin solo gestiona cuentas cuyo rol no supere sus permisos). Las bases con datos lo reciben por migración, no por
 * el seeder. SQLite en memoria: no prueba PostgreSQL ni se ejecuta contra ninguna base real.
 */

const ROLES_QUE_SUBEN = ['secretaria', 'coordinador'];

function migracionPagosSecretaria(): object
{
    return require database_path('migrations/2026_11_20_000094_grant_comprobantes_pagos_to_secretaria.php');
}

/** Estado de una base ya sembrada antes de este cambio: secretaría y coordinación sin permisos de pagos. */
function basePrevia(object $t): void
{
    (fn () => $this->seed(RolesAndPermissionsSeeder::class))->call($t);
    foreach (ROLES_QUE_SUBEN as $rol) {
        Role::findByName($rol, 'web')->revokePermissionTo(['pagos.ver', 'pagos.registrar']);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/** @return list<string> */
function permisosDe(string $rol): array
{
    return Role::findByName($rol, 'web')->permissions->pluck('name')->sort()->values()->all();
}

it('una instalación nueva deja a secretaría y coordinación con pagos.ver y pagos.registrar y nada más de pagos', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    foreach (ROLES_QUE_SUBEN as $rol) {
        $pagos = array_values(array_filter(permisosDe($rol), fn (string $p) => str_starts_with($p, 'pagos.')));
        expect($pagos)->toBe(['pagos.registrar', 'pagos.ver']);
        foreach (['pagos.planificar', 'pagos.confirmar', 'pagos.reversar', 'pagos.exportar'] as $soloAdmin) {
            expect(Role::findByName($rol, 'web')->hasPermissionTo($soloAdmin))->toBeFalse()
                ->and(Role::findByName('admin', 'web')->hasPermissionTo($soloAdmin))->toBeTrue();
        }
    }
});

it('la migración concede solo esos dos permisos a secretaría y coordinación en una base ya sembrada, sin tocar nada más', function (): void {
    basePrevia($this);
    $adminAntes = permisosDe('admin');
    $secretariaAntes = permisosDe('secretaria');
    $coordinadorAntes = permisosDe('coordinador');
    $filas = Permission::count();

    migracionPagosSecretaria()->up();

    expect(permisosDe('secretaria'))->toBe(collect([...$secretariaAntes, 'pagos.registrar', 'pagos.ver'])->sort()->values()->all())
        ->and(permisosDe('coordinador'))->toBe(collect([...$coordinadorAntes, 'pagos.registrar', 'pagos.ver'])->sort()->values()->all())
        ->and(permisosDe('admin'))->toBe($adminAntes)
        ->and(Permission::count())->toBe($filas);
});

it('la migración es idempotente, no resincroniza permisos concedidos a mano y down los retira', function (): void {
    basePrevia($this);
    $secretaria = User::factory()->create();
    $secretaria->assignRole('secretaria');
    $secretaria->givePermissionTo('reportes.exportar.pdf');

    migracionPagosSecretaria()->up();
    migracionPagosSecretaria()->up();
    $concedidos = DB::table('role_has_permissions')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
        ->whereIn('roles.name', ROLES_QUE_SUBEN)
        ->whereIn('permissions.name', ['pagos.ver', 'pagos.registrar'])
        ->count();
    expect($concedidos)->toBe(4)->and($secretaria->fresh()->hasDirectPermission('reportes.exportar.pdf'))->toBeTrue();

    migracionPagosSecretaria()->down();
    foreach (ROLES_QUE_SUBEN as $rol) {
        expect(Role::findByName($rol, 'web')->hasAnyPermission(['pagos.ver', 'pagos.registrar']))->toBeFalse();
    }
});

it('sin el rol secretaria o coordinador la migración omite ese rol y no falla', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Role::query()->where('name', 'secretaria')->delete();
    Role::findByName('coordinador', 'web')->revokePermissionTo(['pagos.ver', 'pagos.registrar']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $filas = Permission::count();

    migracionPagosSecretaria()->up();

    expect(Role::query()->where('name', 'secretaria')->exists())->toBeFalse()
        ->and(Permission::count())->toBe($filas)
        ->and(permisosDe('coordinador'))->toContain('pagos.ver', 'pagos.registrar');
});

it('secretaría y coordinación suben un comprobante de punta a punta, pero no confirman, reversan ni planifican', function (string $rol): void {
    $m = pagoMatricula('60000041');
    $admin = $this->usuarioConRol('admin', 'adm-sec@example.invalid');
    $operador = $this->usuarioConRol($rol, 'operador-rol@example.invalid');

    $r = pagoDeMatricula($this, $operador, $m->id, '25', 'clave-operador-rol')->assertCreated();
    $tx = $r->json('transaccion');
    pagoComprobante($this, $operador, $tx)->assertOk();
    $this->panelComo($operador, 'GET', 'academico/pagos/comprobantes')->assertOk()->assertJsonPath('meta.total', 1);

    $this->panelComo($operador, 'POST', "academico/pagos/transacciones/{$tx}/confirmar")->assertForbidden();
    $this->panelComo($operador, 'POST', "academico/pagos/transacciones/{$tx}/rechazar", ['motivo' => 'Motivo suficiente'])->assertForbidden();
    $this->panelComo($operador, 'POST', "academico/pagos/transacciones/{$tx}/reversar", ['motivo' => 'Motivo suficiente'])->assertForbidden();
    $this->panelComo($operador, 'POST', "academico/matriculas/{$m->id}/obligacion", ['concepto' => 'Otra', 'plan' => 'completo', 'monto_total' => '50'])->assertForbidden();
    $this->panelComo($operador, 'POST', 'academico/pagos/exportar')->assertForbidden();
    // Pedir confirmar al registrar tampoco: confirmar es de admin.
    pagoDeMatricula($this, $operador, $m->id, '10', 'clave-operador-conf', ['confirmar' => true])->assertForbidden();

    $this->panelComo($admin, 'POST', "academico/pagos/transacciones/{$tx}/confirmar")->assertOk();
})->with(ROLES_QUE_SUBEN);
