<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Compartido;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePerfilRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Entrada;
use App\Support\SesionesActivas;
use App\Support\SessionRevoker;
use App\Support\Totp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Perfil propio y sesiones activas del usuario del panel (compartidos por los dos paneles). Sin permiso de módulo: el
 * usuario sale siempre de la sesión, nunca de la petición, y no se tocan roles ni permisos. Cambiar el correo o la
 * contraseña, y cerrar sesiones, exige la contraseña actual. Mismas reglas que el Blade retirado el 14/11/2026.
 */
final class PerfilController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $request->user();

        return response()->json(['data' => [
            'id' => $usuario->id,
            'nombre' => $usuario->name,
            'email' => $usuario->email,
            'telefono' => $usuario->telefono,
            'roles' => $usuario->getRoleNames()->sort()->values()->all(),
            'preferencias_notificacion' => $usuario->preferenciasNotificacion(),
            'two_factor_habilitado' => $usuario->tiene2faActivo(),
            'debe_cambiar_clave' => (bool) $usuario->debe_cambiar_password,
        ]]);
    }

    public function update(UpdatePerfilRequest $request): JsonResponse
    {
        /** @var User $actual */
        $actual = $request->user();
        $cambiaClave = $request->filled('password');
        $data = $request->validated();

        $cambios = DB::transaction(function () use ($request, $actual, $data, $cambiaClave): array {
            $user = User::query()->whereKey($actual->getKey())->lockForUpdate()->firstOrFail();

            $antes = ['name' => $user->name, 'email' => $user->email];
            $user->fill(['name' => $data['name'], 'email' => $data['email']]);

            if ($cambiaClave) {
                $user->password = Hash::make(Entrada::texto($data['password']));
                // Revoca los JWT emitidos con la contraseña anterior y limpia la marca de clave temporal.
                $user->jwt_version = (int) $user->jwt_version + 1;
                $user->debe_cambiar_password = false;
            }

            $campos = array_keys($user->getDirty());
            $user->save();

            if ($cambiaClave) {
                // Cierra las demás sesiones; esta se conserva y se renuevan su identificador y su token CSRF.
                SessionRevoker::revokeSessions($user, $request->session()->getId());
                $request->session()->regenerate();
                $request->session()->regenerateToken();
            }

            if ($campos !== []) {
                // Nunca se registran contraseñas ni hashes: solo qué campos cambiaron.
                AuditLog::record('perfil_actualizado', User::class, $user->id, [
                    'campos' => array_values(array_diff($campos, ['password', 'jwt_version', 'remember_token', 'email_verified_at'])),
                    'clave_modificada' => $cambiaClave,
                    'email_antes' => $antes['email'] !== $user->email ? $antes['email'] : null,
                ], $user->id);
            }

            return $campos;
        });

        $actualizado = $request->user()?->fresh() ?? $actual;

        return response()->json([
            'message' => $cambios === [] ? 'No hubo cambios en tu perfil.' : 'Tu perfil se actualizó correctamente.',
            'data' => ['id' => $actualizado->getKey(), 'nombre' => $actualizado->name, 'email' => $actualizado->email],
        ]);
    }

    public function sesiones(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => [
            'disponible' => SesionesActivas::disponible(),
            'sesiones' => SesionesActivas::listar($user, $request->session()->getId()),
        ]]);
    }

    /** Cierra todas las demás sesiones web y revoca los JWT emitidos (todas las APIs del usuario). */
    public function cerrarOtras(Request $request): JsonResponse
    {
        $this->validarClave($request);

        /** @var User $actual */
        $actual = $request->user();
        $sesionActual = $request->session()->getId();

        $cerradas = DB::transaction(function () use ($actual, $sesionActual): int {
            $user = User::query()->whereKey($actual->getKey())->lockForUpdate()->firstOrFail();

            // Revoca los JWT y cualquier token "recordarme"; esta sesión web se conserva.
            $user->forceFill(['jwt_version' => (int) $user->jwt_version + 1])->setRememberToken(Str::random(60));
            $user->save();

            $cerradas = SesionesActivas::cerrarOtras($user, $sesionActual);

            AuditLog::record('sesiones_cerradas', User::class, $user->id, [
                'alcance' => 'otras',
                'sesiones' => $cerradas,
                'tokens_api_revocados' => true,
            ], $user->id);

            return $cerradas;
        });

        return response()->json([
            'message' => $cerradas === 0
                ? 'No había otras sesiones abiertas. Los accesos por API anteriores se revocaron.'
                : 'Se cerraron '.$cerradas.' sesión(es) en otros dispositivos.',
            'data' => ['cerradas' => $cerradas],
        ]);
    }

    public function cerrarUna(Request $request, string $identificador): JsonResponse
    {
        $this->validarClave($request);

        /** @var User $user */
        $user = $request->user();
        $sesionActual = $request->session()->getId();

        DB::transaction(function () use ($user, $sesionActual, $identificador): void {
            if (! SesionesActivas::cerrarUna($user, $sesionActual, $identificador)) {
                abort(404, 'La sesión no existe o ya se cerró.');
            }

            AuditLog::record('sesiones_cerradas', User::class, $user->id, [
                'alcance' => 'una',
                'sesiones' => 1,
                'tokens_api_revocados' => false,
            ], $user->id);
        });

        return response()->json(['message' => 'La sesión se cerró.', 'data' => ['cerradas' => 1]]);
    }

    public function preferencias(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'telefono' => ['nullable', 'string', 'max:20', 'regex:/\A[+\d\s()-]{6,20}\z/'],
            'interno' => ['required', 'boolean'],
            'email' => ['required', 'boolean'],
            'whatsapp' => ['required', 'boolean'],
            'whatsapp_consentimiento' => ['boolean'],
            'recordatorio_cuotas' => ['required', 'boolean'],
            'recordatorio_sesiones' => ['required', 'boolean'],
            'recordatorio_reclamaciones' => ['required', 'boolean'],
            'alerta_accesos' => ['required', 'boolean'],
        ], [
            'telefono.regex' => 'Ingresa un teléfono válido de 6 a 20 caracteres.',
        ]);

        $telefono = isset($datos['telefono']) && trim((string) $datos['telefono']) !== '' ? trim((string) $datos['telefono']) : null;
        $whatsapp = (bool) $datos['whatsapp'];
        $consentimiento = (bool) ($datos['whatsapp_consentimiento'] ?? false);

        if ($whatsapp && ! $consentimiento) {
            throw ValidationException::withMessages([
                'whatsapp_consentimiento' => 'Debes autorizar expresamente el envío de recordatorios por WhatsApp (Ley N.º 29733).',
            ]);
        }
        if ($whatsapp && $telefono === null) {
            throw ValidationException::withMessages([
                'telefono' => 'Registra tu número de teléfono para recibir avisos por WhatsApp.',
            ]);
        }

        /** @var User $actual */
        $actual = $request->user();
        // El consentimiento queda con fecha y versión de la política vigente (se conserva la fecha original si ya lo había dado).
        $previas = is_array($actual->notificacion_preferencias) ? $actual->notificacion_preferencias : [];
        $conservaConsentimiento = $consentimiento && ($previas['whatsapp_consentimiento'] ?? false) === true && $telefono === $actual->telefono;
        $preferencias = [
            'interno' => (bool) $datos['interno'],
            'email' => (bool) $datos['email'],
            'whatsapp' => $whatsapp,
            'whatsapp_consentimiento' => $consentimiento,
            'whatsapp_consentimiento_at' => $consentimiento ? ($conservaConsentimiento ? ($previas['whatsapp_consentimiento_at'] ?? now()->toIso8601String()) : now()->toIso8601String()) : null,
            'whatsapp_consentimiento_version' => $consentimiento ? Entrada::texto(config('reclamaciones.privacidad_version', 'sin-version')) : null,
            'recordatorio_cuotas' => (bool) $datos['recordatorio_cuotas'],
            'recordatorio_sesiones' => (bool) $datos['recordatorio_sesiones'],
            'recordatorio_reclamaciones' => (bool) $datos['recordatorio_reclamaciones'],
            'alerta_accesos' => (bool) $datos['alerta_accesos'],
        ];

        $user = DB::transaction(function () use ($actual, $telefono, $preferencias): User {
            $user = User::query()->whereKey($actual->getKey())->lockForUpdate()->firstOrFail();
            $user->forceFill([
                'telefono' => $telefono,
                'notificacion_preferencias' => $preferencias,
            ])->save();

            AuditLog::record('perfil_preferencias_actualizadas', User::class, $user->id, [
                'interno' => $preferencias['interno'],
                'email' => $preferencias['email'],
                'whatsapp' => $preferencias['whatsapp'],
            ], $user->id);

            return $user;
        });

        return response()->json([
            'message' => 'Preferencias de notificación guardadas.',
            'data' => [
                'telefono' => $user->telefono,
                'preferencias_notificacion' => $user->preferenciasNotificacion(),
            ],
        ]);
    }

    public function iniciar2fa(Request $request): JsonResponse
    {
        $this->validarClave($request);
        /** @var User $actual */
        $actual = $request->user();
        $secreto = Totp::generarSecreto();

        $user = DB::transaction(function () use ($actual, $secreto): User {
            $user = User::query()->whereKey($actual->getKey())->lockForUpdate()->firstOrFail();
            if ($user->tiene2faActivo()) {
                throw ValidationException::withMessages(['codigo' => 'La verificación en dos pasos ya se encuentra activa.']);
            }
            $user->forceFill([
                'two_factor_secret' => $secreto,
                'two_factor_confirmed_at' => null,
                'two_factor_recovery_codes' => null,
            ])->save();

            return $user;
        });

        return response()->json([
            'message' => 'Escanea o copia la clave en tu aplicación autenticadora y confirma con un código de 6 dígitos.',
            'data' => [
                'secreto' => $secreto,
                'uri' => Totp::uriProvision(Entrada::texto(config('seguridad.totp_issuer', 'Horus Academic')), $user->email, $secreto),
            ],
        ]);
    }

    public function confirmar2fa(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'codigo' => ['required', 'string', 'regex:/\A\d{6}\z/'],
        ], [
            'codigo.required' => 'Ingresa el código de 6 dígitos de tu aplicación autenticadora.',
            'codigo.regex' => 'El código debe tener exactamente 6 dígitos.',
        ]);

        /** @var User $actual */
        $actual = $request->user();

        $planos = DB::transaction(function () use ($actual, $datos): array {
            $user = User::query()->whereKey($actual->getKey())->lockForUpdate()->firstOrFail();
            $secreto = is_string($user->two_factor_secret) ? $user->two_factor_secret : '';
            $paso = $secreto === '' ? null : Totp::pasoValido($secreto, $datos['codigo']);
            if ($paso === null) {
                throw ValidationException::withMessages(['codigo' => 'El código de verificación no coincide. Intenta con el código actual.']);
            }

            $codigos = Totp::generarCodigosRecuperacion();
            $user->forceFill([
                'two_factor_confirmed_at' => now(),
                'two_factor_last_step' => $paso,
                'two_factor_recovery_codes' => $codigos['hashes'],
            ])->save();

            AuditLog::record('two_factor_enabled', User::class, $user->id, [], $user->id);

            return $codigos['planos'];
        });

        return response()->json([
            'message' => 'Verificación en dos pasos activada. Guarda estos códigos de recuperación en un lugar seguro.',
            'data' => ['codigos_recuperacion' => $planos],
        ]);
    }

    public function desactivar2fa(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'codigo' => ['required', 'string', 'max:32'],
        ], [
            'current_password.required' => 'Escribe tu contraseña actual para continuar.',
            'current_password.current_password' => 'La contraseña actual no es correcta.',
            'codigo.required' => 'Ingresa un código 2FA o código de recuperación.',
        ]);

        /** @var User $actual */
        $actual = $request->user();
        $codigo = trim(Entrada::texto($request->input('codigo')));

        DB::transaction(function () use ($actual, $codigo): void {
            $user = User::query()->whereKey($actual->getKey())->lockForUpdate()->firstOrFail();
            if (! $user->tiene2faActivo()) {
                throw ValidationException::withMessages(['codigo' => 'La verificación en dos pasos no está activa.']);
            }
            $paso = Totp::pasoValido((string) $user->two_factor_secret, $codigo);
            $totpNuevo = $paso !== null && ($user->two_factor_last_step === null || $paso > $user->two_factor_last_step);
            $valido = $totpNuevo
                || (is_array($user->two_factor_recovery_codes) && Totp::consumirCodigoRecuperacion($user->two_factor_recovery_codes, $codigo) !== null);
            if (! $valido) {
                throw ValidationException::withMessages(['codigo' => 'El código de verificación no es válido.']);
            }

            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'two_factor_last_step' => null,
                'jwt_version' => (int) $user->jwt_version + 1,
            ])->save();

            AuditLog::record('two_factor_disabled', User::class, $user->id, [], $user->id);
        });

        return response()->json(['message' => 'Verificación en dos pasos desactivada.']);
    }

    private function validarClave(Request $request): void
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
        ], [
            'current_password.required' => 'Escribe tu contraseña actual para cerrar sesiones.',
            'current_password.current_password' => 'La contraseña actual no es correcta.',
        ]);
    }
}
