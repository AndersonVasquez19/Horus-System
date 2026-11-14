<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Panel\Compartido;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePerfilRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\SesionesActivas;
use App\Support\SessionRevoker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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
            'id' => $usuario->getKey(),
            'nombre' => $usuario->name,
            'email' => $usuario->email,
            'roles' => $usuario->getRoleNames()->sort()->values()->all(),
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
                $user->password = Hash::make($data['password']);
                // Revoca los JWT emitidos con la contraseña anterior.
                $user->jwt_version = (int) $user->jwt_version + 1;
            }

            $campos = array_keys($user->getDirty());
            $user->save();

            if ($cambiaClave) {
                // Cierra las demás sesiones; esta se conserva y se renueva su identificador (el BFF recibe la cookie nueva).
                SessionRevoker::revokeSessions($user, $request->session()->getId());
                $request->session()->regenerate();
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
