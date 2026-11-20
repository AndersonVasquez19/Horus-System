<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Retira los permisos de los módulos Docentes e Infraestructura (sedes, aulas y plataformas virtuales) y el de ver el enlace
 * privado de una clase: la gestión académica no asigna docentes ni lugares (los estudiantes se agregan a un grupo de WhatsApp
 * por curso). Spatie elimina en cascada las asignaciones a roles y a usuarios.
 *
 * Solo borra permisos de esa lista cerrada: no toca ningún otro permiso ni resincroniza roles. Repetirla no cambia nada. Las
 * tablas las elimina la migración 000085.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const PERMISOS = [
        'docentes.ver',
        'docentes.crear',
        'docentes.editar',
        'docentes.eliminar',
        'docentes.restaurar',
        'infraestructura.ver',
        'infraestructura.gestionar',
        'sesiones.ver-enlace',
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
        // No recrea los permisos a propósito: los módulos se retiraron. Revertir no debe fallar, pero tampoco los resucita.
    }
};
