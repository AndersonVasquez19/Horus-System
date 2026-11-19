<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Horus Academic, Fase 4: permisos del módulo de docentes.
 *
 * Crea los permisos y los concede como en una instalación nueva: `admin` todos; `coordinador` ver, crear y editar; `secretaria`
 * solo ver. Eliminar y restaurar son solo de `admin`. No resincroniza ningún rol ni retira permisos concedidos a mano; un rol
 * base inexistente se omite.
 */
return new class extends Migration
{
    /** Permisos de esta migración (propios: el seeder ya no los define porque el módulo se retiró). @var list<string> */
    private const PERMISOS = [
        'docentes.ver',
        'docentes.crear',
        'docentes.editar',
        'docentes.eliminar',
        'docentes.restaurar',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (self::PERMISOS as $name) {
                Permission::findOrCreate($name, 'web');
            }

            $concesiones = [
                'admin' => self::PERMISOS,
                'coordinador' => ['docentes.ver', 'docentes.crear', 'docentes.editar'],
                'secretaria' => ['docentes.ver'],
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
