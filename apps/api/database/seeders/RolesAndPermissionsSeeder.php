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
    /**
     * Ya no hay panel de servicios tecnológicos. `servicios.publicar` se conserva como marca editorial del catálogo público: solo
     * las fichas autorizadas por quien la tiene aparecen en /tecnologias/* y en Horus AI. No se asigna desde Roles y permisos (se
     * oculta) y solo la tiene admin. @var list<string>
     */
    public const SERVICIOS_PERMISSIONS = ['servicios.publicar'];

    /** Académico, Ola 3: convocatorias y horarios. Admin y coordinador todos; secretaria solo `ver`. @var list<string> */
    public const CONVOCATORIAS_PERMISSIONS = ['convocatorias.ver', 'convocatorias.crear', 'convocatorias.editar', 'convocatorias.cerrar'];

    /**
     * Académico, Ola 3: calificaciones. `registrar` carga una nota nueva; `corregir` cambia una ya registrada (con motivo) y
     * gestiona las evaluaciones; `exportar` baja el acta. Admin y coordinador todos; secretaria `ver` y `registrar`.
     *
     * @var list<string>
     */
    public const CALIFICACIONES_PERMISSIONS = ['calificaciones.ver', 'calificaciones.registrar', 'calificaciones.corregir', 'calificaciones.exportar'];

    /**
     * Horus Academic, Fase 5: sesiones de clase. Admin y coordinador todos; secretaria solo `ver`.
     *
     * @var list<string>
     */
    public const SESIONES_PERMISSIONS = ['sesiones.ver', 'sesiones.crear', 'sesiones.editar', 'sesiones.cancelar'];

    /**
     * Horus Academic, Fase 6: asistencia. Admin y coordinador todos; secretaria solo `ver`.
     *
     * @var list<string>
     */
    public const ASISTENCIAS_PERMISSIONS = ['asistencias.ver', 'asistencias.registrar', 'asistencias.corregir', 'asistencias.exportar'];

    /**
     * Horus Academic, Fase 7: finanzas. Solo admin (el administrador registra y confirma todo el dinero; no hay rol de tesorería).
     *
     * @var list<string>
     */
    public const PAGOS_PERMISSIONS = ['pagos.ver', 'pagos.planificar', 'pagos.registrar', 'pagos.confirmar', 'pagos.reversar', 'pagos.exportar', 'pagos.cerrar_caja'];

    /**
     * Certificados institucionales: admin todos; coordinador ver y emitir; secretaria solo ver.
     *
     * @var list<string>
     */
    public const CERTIFICADOS_PERMISSIONS = ['certificados.ver', 'certificados.emitir', 'certificados.anular'];

    /** Libro de Reclamaciones (Sprint 20). Solo admin por defecto; ampliar a otros roles es decisión del propietario. @var list<string> */
    public const RECLAMACIONES_PERMISSIONS = ['reclamaciones.ver', 'reclamaciones.gestionar', 'reclamaciones.responder'];

    /** Entrada al panel académico de apps/web (/admin/academico). */
    public const PANEL_ACADEMICO = 'panel.academico.acceder';

    /**
     * Permisos de entrada a los paneles de apps/web. Son necesarios, no suficientes: cada operación
     * de la API del panel exige además su permiso de módulo.
     *
     * @var list<string>
     */
    public const PANEL_PERMISSIONS = [self::PANEL_ACADEMICO];

    /** @var list<string> */
    public const PERMISSIONS = [
        ...self::PANEL_PERMISSIONS,
        ...self::SERVICIOS_PERMISSIONS,
        ...self::CONVOCATORIAS_PERMISSIONS,
        ...self::CALIFICACIONES_PERMISSIONS,
        ...self::SESIONES_PERMISSIONS,
        ...self::ASISTENCIAS_PERMISSIONS,
        ...self::PAGOS_PERMISSIONS,
        ...self::CERTIFICADOS_PERMISSIONS,
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
        // Pagos (decisión del propietario, 20/11/2026): ver y registrar, igual que secretaría. Así conserva el tope de roles para gestionar
        // sus cuentas. Confirmar, reversar, planificar y exportar siguen solo en admin.
        'pagos.ver',
        'pagos.registrar',
        ...self::CONVOCATORIAS_PERMISSIONS,
        ...self::CALIFICACIONES_PERMISSIONS,
        ...self::SESIONES_PERMISSIONS,
        ...self::ASISTENCIAS_PERMISSIONS,
        'certificados.ver',
        'certificados.emitir',
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
        'convocatorias.ver',
        'calificaciones.ver',
        'calificaciones.registrar',
        'sesiones.ver',
        'asistencias.ver',
        // Pagos (decisión del propietario, 20/11/2026): secretaría sube los comprobantes. Confirmar, reversar, planificar y exportar siguen solo en admin.
        'pagos.ver',
        'pagos.registrar',
        'certificados.ver',
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
