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
    /** Solo admin por defecto; no concede acceso a otros roles administrativos. @var list<string> */
    public const SERVICIOS_PERMISSIONS = ['servicios.ver', 'servicios.crear', 'servicios.editar', 'servicios.publicar'];

    /** Solo admin por defecto. @var list<string> */
    public const COTIZACIONES_PERMISSIONS = ['cotizaciones.ver', 'cotizaciones.crear', 'cotizaciones.editar', 'cotizaciones.gestionar'];

    /** CRM, Ola 3: clientes. Solo admin por defecto (restricción comercial vigente). @var list<string> */
    public const CLIENTES_PERMISSIONS = ['clientes.ver', 'clientes.crear', 'clientes.editar'];

    /** Libro de Reclamaciones (Sprint 20). Solo admin por defecto; ampliar a otros roles es decisión del propietario. @var list<string> */
    public const RECLAMACIONES_PERMISSIONS = ['reclamaciones.ver', 'reclamaciones.gestionar', 'reclamaciones.responder'];

    /** Entrada al panel académico de apps/web (/admin/academico). */
    public const PANEL_ACADEMICO = 'panel.academico.acceder';

    /** Entrada al panel de servicios tecnológicos de apps/web (/admin/servicios). Solo admin por defecto. */
    public const PANEL_SERVICIOS = 'panel.servicios.acceder';

    /**
     * Permisos de entrada a los paneles de apps/web. Son necesarios, no suficientes: cada operación
     * de la API del panel exige además su permiso de módulo.
     *
     * @var list<string>
     */
    public const PANEL_PERMISSIONS = [self::PANEL_ACADEMICO, self::PANEL_SERVICIOS];

    /** @var list<string> */
    public const PERMISSIONS = [
        ...self::PANEL_PERMISSIONS,
        ...self::SERVICIOS_PERMISSIONS,
        ...self::COTIZACIONES_PERMISSIONS,
        ...self::CLIENTES_PERMISSIONS,
        ...self::RECLAMACIONES_PERMISSIONS,
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
        'respaldo.ver',
        'respaldo.exportar',
    ];

    /** @var list<string> */
    public const COORDINADOR_PERMISSIONS = [
        self::PANEL_ACADEMICO,
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
        self::PANEL_ACADEMICO,
        'estudiantes.ver',
        'estudiantes.crear',
        'cursos.ver',
        'matriculas.ver',
        'matriculas.crear',
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
