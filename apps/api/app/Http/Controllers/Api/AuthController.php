<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Services\VerificacionRegistroService;
use App\Support\CredentialTiming;
use App\Support\Email;
use App\Support\PasswordPolicy;
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
            'email' => ['required', 'string', 'email', 'max:255', ...(
                VerificacionRegistroService::enabled() ? [] : [Email::uniqueRule(null, 'Ya existe una cuenta con este correo.')]
            )],
            'password' => ['required', 'confirmed', PasswordPolicy::rule()],
        ]);

        if (VerificacionRegistroService::enabled()) {
            app(VerificacionRegistroService::class)->solicitar($data['email']);

            return response()->json(['message' => VerificacionRegistroService::SOLICITUD_RECIBIDA], 202);
        }

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
        $verificada = ! VerificacionRegistroService::enabled() || $user?->hasVerifiedEmail() === true;

        if (! $valid || ! $user || ! $verificada) {
            AuditLog::record('login_failed', User::class, null, [
                'canal' => 'api',
                'cuenta_existente' => $user !== null,
                'huella_correo' => substr(hash_hmac('sha256', $data['email'], (string) config('app.key')), 0, 16),
            ], null);

            return response()->json(['message' => 'Las credenciales no son válidas.'], 422);
        }

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
