<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Inicialización segura: solo crea permisos y roles base y define su matriz.
 * No crea ni modifica usuarios. Es repetible: usa firstOrCreate y sincroniza la matriz de los roles base.
 * Las cuentas del panel se crean con `php artisan horus:crear-usuario`.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /** @var list<string> */
    public const PERMISSIONS = [
        'estudiantes.ver',
        'estudiantes.crear',
        'estudiantes.editar',
        'estudiantes.eliminar',
        'estudiantes.restaurar',
        'cursos.ver',
        'cursos.crear',
        'cursos.editar',
        'cursos.eliminar',
        'cursos.restaurar',
        'cursos.publicar',
        'matriculas.ver',
        'matriculas.crear',
        'matriculas.editar',
        'matriculas.anular',
        'matriculas.restaurar',
        'matriculas.exportar',
        'matriculas.eliminar',
        'seguimientos.ver',
        'seguimientos.crear',
        'seguimientos.editar',
        'seguimientos.eliminar',
        'procesos.ver',
        'procesos.gestionar',
        'aprobaciones.ver',
        'aprobaciones.gestionar',
        'reportes.ver',
        'reportes.exportar.excel',
        'reportes.exportar.pdf',
        'usuarios.ver',
        'usuarios.crear',
        'usuarios.editar',
        'usuarios.eliminar',
        'usuarios.asignar-roles',
        'roles.ver',
        'roles.crear',
        'roles.editar',
        'roles.eliminar',
        'auditoria.ver',
        'auditoria.exportar',
        'configuracion.ver',
        'configuracion.editar',
        'asistente.ver',
        'asistente.entrenar',
        'respaldo.ver',
        'respaldo.exportar',
    ];

    /** @var list<string> */
    public const COORDINADOR_PERMISSIONS = [
        'estudiantes.ver',
        'estudiantes.crear',
        'estudiantes.editar',
        'cursos.ver',
        'cursos.crear',
        'cursos.editar',
        'matriculas.ver',
        'matriculas.crear',
        'matriculas.editar',
        'matriculas.restaurar',
        'seguimientos.ver',
        'seguimientos.crear',
        'seguimientos.editar',
        'procesos.ver',
        'aprobaciones.ver',
        'aprobaciones.gestionar',
        'reportes.ver',
        'reportes.exportar.excel',
        'reportes.exportar.pdf',
        'asistente.ver',
        'respaldo.ver',
    ];

    /** @var list<string> */
    public const SECRETARIA_PERMISSIONS = [
        'estudiantes.ver',
        'estudiantes.crear',
        'cursos.ver',
        'matriculas.ver',
        'matriculas.crear',
        'seguimientos.ver',
        'seguimientos.crear',
        'aprobaciones.ver',
        'reportes.ver',
    ];

    /** Roles con acceso al panel /admin (ver routes/web.php). */
    public const STAFF_ROLES = ['admin', 'coordinador', 'secretaria'];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permissionName) {
            Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]);
        }

        $matrix = [
            'admin' => self::PERMISSIONS,
            'coordinador' => self::COORDINADOR_PERMISSIONS,
            'secretaria' => self::SECRETARIA_PERMISSIONS,
        ];

        foreach ($matrix as $roleName => $permissions) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web'])
                ->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
