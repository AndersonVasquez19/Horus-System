<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Retira los permisos del panel de servicios tecnológicos: la entrada `panel.servicios.acceder` y `servicios.ver|crear|editar`.
 * La API de edición de fichas (`/api/panel/v1/servicios/*`) se eliminó. Spatie elimina en cascada las asignaciones.
 *
 * **Conserva `servicios.publicar`**: es la marca editorial del catálogo público (solo las fichas autorizadas por quien la tiene
 * aparecen en /tecnologias/* y en Horus AI); borrarla dejaría el catálogo vacío. Ya no se asigna desde Roles y permisos.
 *
 * Solo borra permisos de esa lista cerrada y no resincroniza roles. Repetirla no cambia nada. Las tablas del catálogo no se tocan.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const PERMISOS = [
        'panel.servicios.acceder',
        'servicios.ver',
        'servicios.crear',
        'servicios.editar',
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
        // No recrea los permisos a propósito: el panel de servicios se retiró. Revertir no debe fallar, pero tampoco lo resucita.
    }
};
