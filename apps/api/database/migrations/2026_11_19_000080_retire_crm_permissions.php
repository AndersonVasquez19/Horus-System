<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Retira los permisos del CRM comercial (clientes, prospectos, pipeline, proyectos, soporte y reportes comerciales), cuyo panel
 * y cuyas tablas se retiraron (migración 000079). Spatie elimina en cascada sus asignaciones a roles y a usuarios.
 *
 * Solo borra permisos de esa lista cerrada: no toca ningún otro permiso ni resincroniza roles. Repetirla no cambia nada.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const PERMISOS = [
        'clientes.ver',
        'clientes.crear',
        'clientes.editar',
        'prospectos.ver',
        'prospectos.crear',
        'prospectos.editar',
        'prospectos.convertir',
        'pipeline.ver',
        'pipeline.gestionar',
        'proyectos.ver',
        'proyectos.crear',
        'proyectos.editar',
        'soporte.ver',
        'soporte.crear',
        'soporte.gestionar',
        'reportes-comerciales.ver',
        'reportes-comerciales.exportar',
    ];

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
        // No recrea los permisos a propósito: el CRM se retiró. Revertir no debe fallar, pero tampoco resucita un módulo retirado.
    }
};
