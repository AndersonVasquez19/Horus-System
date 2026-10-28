<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\JwtTokenService;
use App\Support\Email;
use App\Support\PasswordPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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

        if (! $user) {
            // Gasta el mismo tiempo de hash que un usuario real para no revelar por latencia si el correo existe.
            Hash::check($data['password'], Hash::make(Str::random(32)));
        }

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Las credenciales no son válidas.'], 422);
        }

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
        $request->attributes->get('jwt_user')->increment('jwt_version');

        return response()->json(['message' => 'Sesión JWT cerrada.']);
    }

    private function userPayload(User $user): array
    {
        return ['id' => $user->getKey(), 'name' => $user->name, 'email' => $user->email];
    }
}
