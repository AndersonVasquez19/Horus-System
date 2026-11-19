<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\SessionRevoker;
use Illuminate\Console\Command;

/**
 * Quita la verificación en dos pasos de una cuenta desde la consola del servidor (recuperación cuando se perdieron el dispositivo y los
 * códigos de recuperación). Cierra los accesos vivos de la cuenta y deja registro; la persona debe volver a activar el 2FA.
 */
final class ReiniciarDosFactores extends Command
{
    protected $signature = 'horus:reiniciar-2fa {email : Correo de la cuenta}';

    protected $description = 'Desactiva el 2FA de una cuenta (recuperación desde consola) y revoca sus accesos.';

    public function handle(): int
    {
        $user = User::query()->where('email', strtolower(trim((string) $this->argument('email'))))->whereNull('deleted_at')->first();
        if (! $user instanceof User) {
            $this->error('No existe una cuenta activa con ese correo.');

            return self::FAILURE;
        }
        if (! $user->tiene2faActivo()) {
            $this->line('La cuenta no tenía el 2FA activo.');

            return self::SUCCESS;
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
            'jwt_version' => (int) $user->jwt_version + 1,
        ])->save();
        SessionRevoker::revokeSessions($user);
        AuditLog::record('two_factor_reset_console', User::class, $user->id, ['canal' => 'consola'], null);
        $this->info('Verificación en dos pasos desactivada. La persona debe volver a activarla desde su perfil.');

        return self::SUCCESS;
    }
}
