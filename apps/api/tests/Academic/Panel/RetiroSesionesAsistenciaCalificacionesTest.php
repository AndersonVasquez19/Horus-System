<?php

declare(strict_types=1);

use App\Services\GestionRoles;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

/*
 * Retiro de sesiones de clase, asistencia y calificaciones (27/11/2026). Las tablas se conservan; la API, los permisos concedidos y
 * los avisos desaparecen.
 */

/** @return list<string> */
function rscPermisos(): array
{
    return [...RolesAndPermissionsSeeder::SESIONES_PERMISSIONS, ...RolesAndPermissionsSeeder::ASISTENCIAS_PERMISSIONS, ...RolesAndPermissionsSeeder::CALIFICACIONES_PERMISSIONS];
}

it('ningún rol base recibe los 12 permisos retirados y Roles y permisos los oculta', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(rscPermisos())->toHaveCount(12)
        ->and(array_values(array_diff(rscPermisos(), GestionRoles::PERMISOS_RETIRADOS)))->toBe([])
        ->and(array_values(array_intersect(rscPermisos(), RolesAndPermissionsSeeder::PERMISSIONS)))->toBe([]);

    foreach (['admin', 'coordinador', 'secretaria'] as $rol) {
        $concedidos = Role::findByName($rol, 'web')->permissions->pluck('name')->all();
        expect(array_values(array_intersect(rscPermisos(), $concedidos)))->toBe([]);
    }
});

it('no queda ninguna ruta del panel de sesiones de clase, asistencia, calificaciones, actas ni calendario', function (): void {
    $restantes = [];
    foreach (Route::getRoutes()->getRoutes() as $ruta) {
        if (str_starts_with($ruta->uri(), 'api/panel/v1/academico/')
            && preg_match('#/(sesiones|asistencias|calificaciones|evaluaciones|acta)(/|$)|calendario\.ics#', $ruta->uri()) === 1) {
            $restantes[] = $ruta->uri();
        }
    }

    expect($restantes)->toBe([]);
});

it('conserva las tablas y columnas con los datos históricos', function (): void {
    foreach (['sesiones_clase', 'asistencias', 'evaluaciones', 'calificaciones'] as $tabla) {
        expect(Schema::hasTable($tabla))->toBeTrue();
    }
    expect(Schema::hasColumns('matriculas', ['nota_final', 'resultado']))->toBeTrue()
        ->and(Schema::hasColumns('certificados', ['porcentaje_asistencia', 'nota_final']))->toBeTrue();
});

it('el recordatorio diario ya no genera el aviso de clases', function (): void {
    config(['recordatorios.internos_enabled' => true]);

    $this->artisan('horus:recordatorios-diarios')->doesntExpectOutputToContain('Sesiones')->assertSuccessful();
});
