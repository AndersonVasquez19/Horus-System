<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Pagos: secretaría y coordinación suben los comprobantes de pago (decisión del propietario, 20/11/2026).
 *
 * Concede a `secretaria` y a `coordinador` solo `pagos.ver` y `pagos.registrar`. No toca ningún otro permiso ni a `admin`, y no
 * resincroniza (el seeder sí resincroniza roles, por eso una base con datos lo recibe por esta migración). El coordinador los
 * recibe también para conservar el tope de roles: un no admin solo gestiona cuentas cuyo rol no supere sus propios permisos, y
 * sin ellos dejaría de poder administrar las cuentas de secretaría. Confirmar, reversar, planificar y exportar siguen solo en
 * `admin`: quien registra un pago no lo confirma. Sustituye la retirada de la migración 000078 solo para estos dos permisos y
 * estos dos roles. Un rol inexistente se omite; `down()` los retira otra vez.
 */
return new class extends Migration
{
    private const ROLES = ['secretaria', 'coordinador'];

    private const PERMISOS = ['pagos.ver', 'pagos.registrar'];

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (self::ROLES as $nombre) {
                $rol = Role::query()->where('name', $nombre)->where('guard_name', 'web')->first();
                if ($rol === null) {
                    continue;
                }
                foreach (self::PERMISOS as $permiso) {
                    Permission::findOrCreate($permiso, 'web');
                }
                $rol->givePermissionTo(self::PERMISOS);
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            foreach (self::ROLES as $nombre) {
                Role::query()->where('name', $nombre)->where('guard_name', 'web')->first()?->revokePermissionTo(self::PERMISOS);
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
