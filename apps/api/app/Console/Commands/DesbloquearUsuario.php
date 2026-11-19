<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Desbloquea una cuenta suspendida o bloqueada desde la consola del servidor. Es la vía de recuperación cuando no queda ninguna
 * persona administradora con acceso al panel. Solo quita el bloqueo (no cambia contraseñas, roles ni 2FA) y deja registro.
 */
final class DesbloquearUsuario extends Command
{
    protected $signature = 'horus:desbloquear-usuario {email : Correo de la cuenta}';

    protected $description = 'Quita el bloqueo o suspensión de una cuenta (recuperación desde consola).';

    public function handle(): int
    {
        $user = User::query()->where('email', strtolower(trim((string) $this->argument('email'))))->whereNull('deleted_at')->first();
        if (! $user instanceof User) {
            $this->error('No existe una cuenta activa con ese correo.');

            return self::FAILURE;
        }

        if ($user->bloqueado_hasta === null) {
            $this->line('La cuenta no estaba bloqueada.');

            return self::SUCCESS;
        }

        $user->forceFill(['bloqueado_hasta' => null, 'motivo_bloqueo' => null])->save();
        AuditLog::record('user_unlocked', User::class, $user->id, ['canal' => 'consola'], null);
        $this->info('Cuenta desbloqueada.');

        return self::SUCCESS;
    }
}
