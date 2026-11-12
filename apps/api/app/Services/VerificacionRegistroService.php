<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\VerificarRegistroMail;
use App\Models\RegistroPendiente;
use App\Models\User;
use App\Support\Email;
use App\Support\SessionRevoker;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

final class VerificacionRegistroService
{
    public const SOLICITUD_RECIBIDA = 'Si la solicitud puede continuar, recibirás instrucciones en ese correo.';

    public const CONFIRMACION_COMPLETA = 'Correo confirmado. Puedes iniciar sesión o recuperar tu acceso.';

    public static function enabled(): bool
    {
        return config('registro-verificacion.enabled') === true;
    }

    public function solicitar(string $email): void
    {
        if (! self::enabled()) {
            return;
        }

        $email = Email::normalize($email);
        $emailHash = hash_hmac('sha256', $email, (string) config('app.key'));
        $codigo = DB::transaction(function () use ($email, $emailHash): ?string {
            // createOrFirst usa savepoint al resolver una colisión de unicidad dentro de la transacción.
            RegistroPendiente::query()->firstOrCreate(['email_hash' => $emailHash], ['email' => $email]);
            $pendiente = RegistroPendiente::query()->where('email_hash', $emailHash)->lockForUpdate()->firstOrFail();
            if ($pendiente->requested_at !== null && $pendiente->requested_at->addSeconds(60)->isFuture()) {
                return null;
            }

            $codigo = bin2hex(random_bytes(32));
            $pendiente->forceFill(['token_hash' => hash('sha256', $codigo), 'expires_at' => now()->addMinutes(30),
                'requested_at' => now(), 'consumed_at' => null])->save();

            return $codigo;
        });

        if ($codigo === null) {
            return;
        }

        // Un transporte log/failover podría persistir el código. No se usa el mailer por defecto.
        $mailer = config('registro-verificacion.mailer');
        $transport = is_string($mailer) ? config('mail.mailers.'.$mailer.'.transport') : null;
        if (! is_string($mailer) || ! in_array($transport, ['smtp', 'resend', 'ses', 'ses-v2', 'postmark', 'mailgun', 'array'], true)
            || ($transport === 'array' && ! app()->environment('testing'))) {
            Log::warning('auth.registration_verification', ['outcome' => 'transport_not_configured']);

            return;
        }

        try {
            Mail::mailer($mailer)->to($email)->send(new VerificarRegistroMail($codigo));
        } catch (Throwable) {
            // Frontera de entrega: nunca registrar mensaje del proveedor, destinatario o código.
            // No reintentar automáticamente: un fallo puede corresponder a una entrega incierta.
            Log::warning('auth.registration_verification', ['outcome' => 'delivery_unavailable']);
        }
    }

    public function confirmar(#[SensitiveParameter] string $codigo, string $nombre, #[SensitiveParameter] string $password): bool
    {
        if (! self::enabled()) {
            return false;
        }

        // El coste de hashing no depende de que el correo tenga ya una cuenta verificada.
        $passwordHash = Hash::make($password);

        return DB::transaction(function () use ($codigo, $nombre, $passwordHash): bool {
            $pendiente = RegistroPendiente::query()->where('token_hash', hash('sha256', $codigo))->lockForUpdate()->first();
            if ($pendiente === null || $pendiente->consumed_at !== null || $pendiente->expires_at === null
                || ! $pendiente->expires_at->isFuture()) {
                return false;
            }

            // La actualización condicional añade defensa frente a consumo repetido.
            $consumido = RegistroPendiente::query()->whereKey($pendiente->getKey())->whereNull('consumed_at')
                ->where('token_hash', hash('sha256', $codigo))->where('expires_at', '>', now())
                ->update(['consumed_at' => now()]);
            if ($consumido !== 1) {
                return false;
            }

            $user = User::query()->whereRaw('lower(email) = ?', [$pendiente->email])->lockForUpdate()->first();
            if ($user !== null && $user->hasVerifiedEmail()) {
                return true;
            }

            if ($user === null) {
                $user = new User(['name' => $nombre, 'email' => $pendiente->email, 'password' => $passwordHash]);
            } else {
                // El poseedor del código controla el buzón. No se modifican nombre ni roles existentes.
                $user->password = $passwordHash;
                $user->jwt_version = (int) $user->jwt_version + 1;
                $user->setRememberToken(Str::random(60));
                SessionRevoker::revokeSessions($user);
            }

            // También elimina tokens huérfanos de una cuenta anterior borrada con el mismo correo.
            DB::table('password_reset_tokens')->whereRaw('lower(email) = ?', [$pendiente->email])->delete();
            $user->markEmailAsVerified();
            event(new Verified($user));

            return true;
        });
    }
}
