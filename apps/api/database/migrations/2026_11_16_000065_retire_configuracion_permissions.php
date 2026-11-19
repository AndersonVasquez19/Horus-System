<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Horus Academic: el módulo de Configuración de los paneles anteriores se descartó (16/11/2026). Se retiran sus dos permisos
 * (`configuracion.ver`, `configuracion.editar`) del catálogo; Spatie elimina en cascada sus asignaciones a roles y usuarios.
 *
 * No toca `system_settings` ni `App\Support\ConfiguracionSistema` (los PDF siguen leyendo el nombre de la empresa y la moneda
 * de ahí). `down()` los vuelve a crear y los concede solo a `admin`, como en la instalación anterior.
 */
return new class extends Migration
{
    private const PERMISOS = ['configuracion.ver', 'configuracion.editar'];

    public function up(): void
    {
        DB::transaction(function (): void {
            Permission::query()->where('guard_name', 'web')->whereIn('name', self::PERMISOS)->get()
                ->each(fn (Permission $permiso) => $permiso->delete());
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            foreach (self::PERMISOS as $nombre) {
                Permission::findOrCreate($nombre, 'web');
            }
            Role::query()->where('name', 'admin')->where('guard_name', 'web')->first()?->givePermissionTo(self::PERMISOS);
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
