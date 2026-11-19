<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Support\CredentialTiming;
use App\Support\Email;
use App\Support\PasswordPolicy;
use App\Support\SeguridadCuentas;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request, JwtTokenService $tokens): JsonResponse
    {
        Email::normalizeRequest($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', Email::uniqueRule(null, 'Ya existe una cuenta con este correo.')],
            'password' => ['required', 'confirmed', PasswordPolicy::rule()],
        ]);

        return DB::transaction(function () use ($data, $tokens): JsonResponse {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);

            return response()->json([
                'user' => $this->userPayload($user),
                ...$tokens->issue($user),
            ], 201);
        });
    }

    public function login(Request $request, JwtTokenService $tokens): JsonResponse
    {
        Email::normalizeRequest($request);
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        // Una única comprobación de hash, exista o no la cuenta, para no revelarlo por latencia.
        $valid = CredentialTiming::check($data['password'], $user?->password);

        if (! $valid || ! $user || $user->estaDadoDeBaja() || $user->estaSuspendido()) {
            AuditLog::record('login_failed', User::class, null, [
                'canal' => 'api',
                'cuenta_existente' => $user !== null,
                'huella_correo' => substr(hash_hmac('sha256', $data['email'], (string) config('app.key')), 0, 16),
            ], null);
            SeguridadCuentas::registrarFalloParaCuentaExistente($data['email']);

            return response()->json(['message' => 'Las credenciales no son válidas.'], 422);
        }

        // Esta vía no tiene segundo factor ni cambio de contraseña: las cuentas que los exigen deben entrar por el panel.
        if ($user->tiene2faActivo() || $user->debe_cambiar_password) {
            return response()->json([
                'message' => $user->tiene2faActivo()
                    ? 'Esta cuenta usa verificación en dos pasos: inicia sesión desde el panel.'
                    : 'Esta cuenta debe cambiar su contraseña: inicia sesión desde el panel.',
                'code' => $user->tiene2faActivo() ? '2fa_requerido' : 'cambio_clave_requerido',
            ], 403);
        }

        SeguridadCuentas::limpiarIntentos($user);
        AuditLog::record('login', User::class, $user->getKey(), [
            'canal' => 'api',
            'personal_panel' => $user->hasAnyRole(RolesAndPermissionsSeeder::STAFF_ROLES),
        ], $user->getKey());

        return response()->json([
            'user' => $this->userPayload($user),
            ...$tokens->issue($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->attributes->get('jwt_user'))]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('jwt_user');
        $user->increment('jwt_version');

        AuditLog::record('logout', User::class, $user->getKey(), ['canal' => 'api'], $user->getKey());

        return response()->json(['message' => 'Sesión JWT cerrada.']);
    }

    /** @return array{id: int, name: string, email: string} */
    private function userPayload(User $user): array
    {
        return ['id' => $user->getKey(), 'name' => $user->name, 'email' => $user->email];
    }
}
