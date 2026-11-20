<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Retira los permisos del panel de cotizaciones (`cotizaciones.*`), retirado junto con el CRM comercial. La recepción pública de
 * solicitudes de cotización no usa permisos y sigue funcionando por `/api/public/v1/cotizaciones`. Spatie elimina en cascada las
 * asignaciones a roles y a usuarios.
 *
 * Solo borra permisos de esa lista cerrada: no toca ningún otro permiso ni resincroniza roles. Repetirla no cambia nada.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const PERMISOS = [
        'cotizaciones.ver',
        'cotizaciones.crear',
        'cotizaciones.editar',
        'cotizaciones.gestionar',
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
        // No recrea los permisos a propósito: el panel de cotizaciones se retiró. Revertir no debe fallar, pero tampoco lo resucita.
    }
};
