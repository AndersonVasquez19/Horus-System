<?php

declare(strict_types=1);

use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * El módulo de Configuración de los paneles anteriores se descartó (16/11/2026): ni rutas, ni permisos en el catálogo, y la
 * migración 000065 retira los permisos de una base ya sembrada sin tocar el resto de la matriz.
 */

it('ya no hay rutas ni permisos de configuración en una instalación nueva', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Permission::query()->where('name', 'like', 'configuracion.%')->count())->toBe(0);
    foreach (['/admin/configuracion', '/api/panel/v1/compartido/configuracion'] as $ruta) {
        $this->get($ruta)->assertNotFound();
    }
    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        expect((string) $route->getName())->not->toStartWith('admin.configuracion');
    }
});

it('la migración retira los permisos de una base antigua sin tocar el resto y es reversible', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    foreach (['configuracion.ver', 'configuracion.editar'] as $nombre) {
        Permission::findOrCreate($nombre, 'web');
    }
    Role::findByName('admin', 'web')->givePermissionTo(['configuracion.ver', 'configuracion.editar']);
    $otros = Permission::query()->where('name', 'not like', 'configuracion.%')->count();

    $migracion = require database_path('migrations/2026_11_16_000065_retire_configuracion_permissions.php');
    $migracion->up();
    $migracion->up(); // idempotente

    expect(Permission::query()->where('name', 'like', 'configuracion.%')->count())->toBe(0)
        ->and(Permission::query()->count())->toBe($otros)
        ->and(Role::findByName('admin', 'web')->hasPermissionTo('estudiantes.ver'))->toBeTrue();

    $migracion->down();
    expect(Role::findByName('admin', 'web')->fresh()->hasPermissionTo('configuracion.ver'))->toBeTrue()
        ->and(Role::findByName('coordinador', 'web')->hasPermissionTo('configuracion.ver'))->toBeFalse();
});
