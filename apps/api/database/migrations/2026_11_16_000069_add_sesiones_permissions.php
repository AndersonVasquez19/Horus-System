<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Horus Academic, Fase 5: permisos de sesiones de clase. `admin` y `coordinador` todos (incluido `sesiones.ver-enlace`); `secretaria` solo
 * `sesiones.ver`. No resincroniza ningún rol ni retira permisos concedidos a mano; un rol base inexistente se omite.
 */
return new class extends Migration
{
    /** Permisos de esta migración (propios: el seeder ya no los define porque el módulo se retiró). @var list<string> */
    private const PERMISOS = [
        'sesiones.ver',
        'sesiones.crear',
        'sesiones.editar',
        'sesiones.cancelar',
        'sesiones.ver-enlace',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (self::PERMISOS as $name) {
                Permission::findOrCreate($name, 'web');
            }

            $concesiones = [
                'admin' => self::PERMISOS,
                'coordinador' => self::PERMISOS,
                'secretaria' => ['sesiones.ver'],
            ];
            foreach ($concesiones as $rol => $permisos) {
                Role::query()->where('name', $rol)->where('guard_name', 'web')->first()?->givePermissionTo($permisos);
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            Permission::query()->where('guard_name', 'web')
                ->whereIn('name', self::PERMISOS)
                ->get()
                ->each(fn (Permission $permiso) => $permiso->delete());
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
