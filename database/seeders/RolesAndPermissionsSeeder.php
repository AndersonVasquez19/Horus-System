<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
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
            'matriculas.ver',
            'matriculas.crear',
            'matriculas.editar',
            'matriculas.anular',
            'matriculas.restaurar',
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

        foreach ($permissions as $permissionName) {
            Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]);
        }

        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $coordinadorRole = Role::firstOrCreate(['name' => 'coordinador', 'guard_name' => 'web']);
        $secretariaRole = Role::firstOrCreate(['name' => 'secretaria', 'guard_name' => 'web']);

        $adminRole->syncPermissions($permissions);

        $coordinadorPermissions = [
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
        $coordinadorRole->syncPermissions($coordinadorPermissions);

        $secretariaPermissions = [
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
        $secretariaRole->syncPermissions($secretariaPermissions);

        $admin = User::firstOrCreate(
            ['email' => 'admin@horusgroup.com'],
            ['name' => 'Administrador Horus', 'password' => 'Horus2026*']
        );
        $admin->syncRoles($adminRole);

        $coordinador = User::firstOrCreate(
            ['email' => 'coordinador@horusgroup.com'],
            ['name' => 'Coordinador Horus', 'password' => 'Horus2026*']
        );
        $coordinador->syncRoles($coordinadorRole);

        $secretaria = User::firstOrCreate(
            ['email' => 'secretaria@horusgroup.com'],
            ['name' => 'Secretaria Horus', 'password' => 'Horus2026*']
        );
        $secretaria->syncRoles($secretariaRole);
    }
}
