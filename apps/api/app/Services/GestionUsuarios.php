<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\RestablecerContrasenaWebNotification;
use App\Support\SeguridadCuentas;
use App\Support\SessionRevoker;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Altas, cambios y bajas de usuarios con todas las reglas de protección administrativa (Sprint 21.1).
 *
 * Es la única implementación: la usa el panel (`Api\Panel\Compartido\UsuarioController`; el Blade se retiró el 14/11/2026),
 * así que escalada de privilegios, último administrador y auditoría viven en un solo lugar. Cada operación corre bajo
 * `AdminProtection::runAdministrative()`: el actor se relee con datos vigentes (no con la caché de Spatie) y una
 * denegación (403) se registra después del rollback.
 */
final class GestionUsuarios
{
    public function __construct(private readonly AdminProtection $adminProtection) {}

    /**
     * @param  array{name: string, email: string, password: string, temporal?: bool|null}  $data
     * @param  list<string>  $roles
     */
    public function crear(User $actor, array $data, array $roles): User
    {
        $roles = array_values(array_unique($roles));
        $temporal = (bool) ($data['temporal'] ?? false);

        /** @var User $creado */
        $creado = $this->adminProtection->runAdministrative(function () use ($data, $actor, $roles, $temporal): User {
            // La creación también es exclusiva. Los permisos del actor se releen bajo el bloqueo y asignar roles
            // exige su propio permiso, sin superar los privilegios del actor (R2).
            $alcance = $this->adminProtection->actorVigente($actor, ['usuarios.crear'], 'usuario.crear', User::class, null);
            $this->adminProtection->assertMayRequestRoles($alcance, $roles !== [], 'usuario.crear', null);
            $this->adminProtection->assertCanAssignRoles($alcance, null, [], $roles, 'usuario.crear');

            $usuario = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);
            // Cuenta creada por un administrador: el correo queda dado por válido para que pueda entrar aunque
            // la verificación de registro esté activa (no es asignable en masa).
            $usuario->forceFill([
                'email_verified_at' => now(),
                'debe_cambiar_password' => $temporal,
            ])->save();

            if ($roles !== []) {
                $usuario->syncRoles($roles);
                $this->adminProtection->forgetPermissionCacheAfterCommit();
            }

            AuditLog::record('user_created', User::class, $usuario->id, [
                'name' => $usuario->name,
                'email' => $usuario->email,
                'roles' => $usuario->roles->pluck('name')->all(),
                'temporal' => $temporal,
            ], $alcance['actor']->id);

            return $usuario;
        });

        return $creado;
    }

    /**
     * @param  array{name: string, email: string, password?: string|null, temporal?: bool|null}  $data
     * @param  list<string>  $nuevosRoles
     * @param  bool  $solicitaRoles  si la petición trae el campo `roles` (su ausencia no significa «quitar todos»)
     * @param  string|null  $sesionActual  sesión web de quien actúa: se conserva al cambiar su propia clave
     */
    public function actualizar(User $actor, User $user, array $data, array $nuevosRoles, bool $solicitaRoles, ?string $sesionActual): void
    {
        $nuevosRoles = array_values(array_unique($nuevosRoles));

        // Toda decisión de autorización se toma bajo el bloqueo y con datos vigentes (no con la caché de Spatie).
        // Orden: actor vigente -> objetivo -> R1/R1+ -> R2 -> reglas 422 de último admin.
        $this->adminProtection->runAdministrative(function () use ($user, $data, $actor, $solicitaRoles, $nuevosRoles, $sesionActual): void {
            $alcance = $this->adminProtection->actorVigente($actor, ['usuarios.editar'], 'usuario.actualizar', User::class, $user->getKey());
            $actor = $alcance['actor'];
            $user = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $passwordChanged = false;

            $this->adminProtection->assertCanModifyUser($alcance, $user, 'usuario.actualizar');
            $this->adminProtection->assertMayRequestRoles($alcance, $solicitaRoles, 'usuario.actualizar', $user->id);

            $puedeAsignar = in_array('usuarios.asignar-roles', $alcance['permisos'], true);
            // Un no administrador que edita su propio perfil sin enviar `roles` no cambia sus roles (D-11):
            // la ausencia del campo no se interpreta como «quitar todos».
            $cambiaRoles = $puedeAsignar && ($solicitaRoles || ! $actor->is($user) || $alcance['admin']);
            $rolesActuales = $this->adminProtection->roleNamesOf($user);

            if ($cambiaRoles) {
                $this->adminProtection->assertCanAssignRoles($alcance, $user, $rolesActuales, $nuevosRoles, 'usuario.actualizar');
                $this->adminProtection->assertRoleChangeKeepsAdministration($user, $nuevosRoles, $actor);
            }

            $previous = [
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->pluck('name')->all(),
            ];

            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
            ]);

            if (! empty($data['password'])) {
                $user->password = Hash::make($data['password']);
                // Revoca los JWT emitidos con la contraseña anterior; las sesiones web se cierran tras guardar.
                $user->jwt_version = (int) $user->jwt_version + 1;
                if (array_key_exists('temporal', $data)) {
                    $user->debe_cambiar_password = (bool) $data['temporal'];
                }
                $passwordChanged = true;
            }

            $user->save();

            if ($passwordChanged) {
                // Quien cambia su propia clave conserva la sesión actual; las demás se cierran.
                SessionRevoker::revokeSessions($user, $user->is($actor) ? $sesionActual : null);
            }

            if ($cambiaRoles) {
                $user->syncRoles($nuevosRoles);
                $this->adminProtection->forgetPermissionCacheAfterCommit();
            }

            $after = [
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->pluck('name')->all(),
            ];

            if ($after !== $previous || $passwordChanged) {
                AuditLog::record('user_updated', User::class, $user->id, [
                    'before' => $previous,
                    'after' => $after,
                ], $actor->id);
            }
        });
    }

    public function suspender(User $actor, User $user, CarbonInterface $hasta, ?string $motivo = null): void
    {
        $this->adminProtection->runAdministrative(function () use ($actor, $user, $hasta, $motivo): void {
            $alcance = $this->adminProtection->actorVigente($actor, ['usuarios.editar'], 'usuario.suspender', User::class, $user->getKey());
            $objetivo = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $this->adminProtection->assertCanModifyUser($alcance, $objetivo, 'usuario.suspender');
            $this->adminProtection->assertUserCanBeSuspended($objetivo, $alcance['actor']);

            $objetivo->forceFill([
                'bloqueado_hasta' => $hasta,
                'motivo_bloqueo' => $motivo !== null && trim($motivo) !== '' ? trim($motivo) : null,
                'jwt_version' => (int) $objetivo->jwt_version + 1,
            ])->save();

            SessionRevoker::revokeSessions($objetivo);

            AuditLog::record('user_suspended', User::class, $objetivo->id, [
                'bloqueado_hasta' => $hasta->toIso8601String(),
                'motivo' => $objetivo->motivo_bloqueo,
            ], $alcance['actor']->id);
        });
    }

    public function desbloquear(User $actor, User $user): void
    {
        $this->adminProtection->runAdministrative(function () use ($actor, $user): void {
            $alcance = $this->adminProtection->actorVigente($actor, ['usuarios.editar'], 'usuario.desbloquear', User::class, $user->getKey());
            $objetivo = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $this->adminProtection->assertCanModifyUser($alcance, $objetivo, 'usuario.desbloquear');

            $objetivo->forceFill([
                'bloqueado_hasta' => null,
                'motivo_bloqueo' => null,
            ])->save();

            SeguridadCuentas::limpiarIntentos($objetivo);

            AuditLog::record('user_unlocked', User::class, $objetivo->id, [], $alcance['actor']->id);
        });
    }

    public function cerrarSesiones(User $actor, User $user, ?string $sesionActual): void
    {
        $this->adminProtection->runAdministrative(function () use ($actor, $user, $sesionActual): void {
            $alcance = $this->adminProtection->actorVigente($actor, ['usuarios.editar'], 'usuario.cerrar_sesiones', User::class, $user->getKey());
            $objetivo = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $this->adminProtection->assertCanModifyUser($alcance, $objetivo, 'usuario.cerrar_sesiones');

            $objetivo->forceFill(['jwt_version' => (int) $objetivo->jwt_version + 1])->save();
            SessionRevoker::revokeSessions($objetivo, $objetivo->is($alcance['actor']) ? $sesionActual : null);

            AuditLog::record('user_sessions_revoked', User::class, $objetivo->id, [], $alcance['actor']->id);
        });
    }

    public function invitar(User $actor, User $user): void
    {
        /** @var User $objetivo */
        $objetivo = $this->adminProtection->runAdministrative(function () use ($actor, $user): User {
            $alcance = $this->adminProtection->actorVigente($actor, ['usuarios.editar'], 'usuario.invitar', User::class, $user->getKey());
            $objetivo = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $this->adminProtection->assertCanModifyUser($alcance, $objetivo, 'usuario.invitar');
            if ($objetivo->estaSuspendido()) {
                throw ValidationException::withMessages(['usuario' => 'No puedes enviar enlace de acceso a una cuenta suspendida.']);
            }

            AuditLog::record('user_invited', User::class, $objetivo->id, [], $alcance['actor']->id);

            return $objetivo;
        });

        try {
            $token = Password::broker()->createToken($objetivo);
            $objetivo->notify(new RestablecerContrasenaWebNotification($token));
        } catch (Throwable) {
            Log::warning('auth.user_invite', ['outcome' => 'delivery_failed']);
        }
    }

    public function desactivar(User $actor, User $user): void
    {
        $this->adminProtection->runAdministrative(function () use ($user, $actor): void {
            $alcance = $this->adminProtection->actorVigente($actor, ['usuarios.eliminar'], 'usuario.desactivar', User::class, $user->getKey());
            $user = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $this->adminProtection->assertCanDeleteUser($alcance, $user, 'usuario.desactivar');
            $this->adminProtection->assertUserCanBeDeleted($user, $alcance['actor']);

            if (! $user->estaDadoDeBaja()) {
                $user->forceFill([
                    'deleted_at' => now(),
                    'jwt_version' => (int) $user->jwt_version + 1,
                ])->save();
                SessionRevoker::revokeSessions($user);

                AuditLog::record('user_deactivated', User::class, $user->id, [
                    'name' => $user->name,
                    'email' => $user->email,
                ], $alcance['actor']->id);
            }
        });
    }

    public function eliminar(User $actor, User $user): void
    {
        $this->adminProtection->runAdministrative(function () use ($user, $actor): void {
            $alcance = $this->adminProtection->actorVigente($actor, ['usuarios.eliminar'], 'usuario.eliminar', User::class, $user->getKey());
            $user = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            // R3/R1+ primero (403); después las reglas de integridad (422): último administrador y uno mismo.
            $this->adminProtection->assertCanDeleteUser($alcance, $user, 'usuario.eliminar');
            $this->adminProtection->assertUserCanBeDeleted($user, $alcance['actor']);

            $user->forceFill(['jwt_version' => (int) $user->jwt_version + 1])->save();
            SessionRevoker::revokeSessions($user);
            $user->delete();

            AuditLog::record('user_deleted', User::class, $user->id, [
                'name' => $user->name,
                'email' => $user->email,
            ], $alcance['actor']->id);
        });
    }

    public function restaurar(User $actor, int $userId): User
    {
        /** @var User $restaurado */
        $restaurado = $this->adminProtection->runAdministrative(function () use ($actor, $userId): User {
            $alcance = $this->adminProtection->actorVigente($actor, ['usuarios.eliminar'], 'usuario.restaurar', User::class, $userId);
            $user = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();

            $this->adminProtection->assertCanModifyUser($alcance, $user, 'usuario.restaurar');

            if ($user->estaDadoDeBaja()) {
                $user->forceFill(['deleted_at' => null])->save();
                AuditLog::record('user_restored', User::class, $user->id, [
                    'name' => $user->name,
                    'email' => $user->email,
                ], $alcance['actor']->id);
            }

            return $user;
        });

        return $restaurado;
    }
}
