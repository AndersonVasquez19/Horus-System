<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Carbon;
use RuntimeException;

class JwtTokenService
{
    /** @return array{access_token: string, token_type: string, expires_in: int} */
    public function issue(User $user): array
    {
        $now = Carbon::now()->timestamp;
        $expiresAt = $now + max(60, (int) config('jwt.ttl', 900));
        $token = JWT::encode([
            'iss' => $this->issuer(),
            'aud' => $this->issuer(),
            'sub' => (string) $user->getKey(),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $expiresAt,
            'jti' => bin2hex(random_bytes(16)),
            'ver' => (int) $user->jwt_version,
        ], $this->secret(), 'HS256');

        return ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => $expiresAt - $now];
    }

    public function decode(string $token): object
    {
        JWT::$leeway = max(0, (int) config('jwt.leeway', 0));
        $claims = JWT::decode($token, new Key($this->secret(), 'HS256'));

        if (($claims->iss ?? null) !== $this->issuer() || ($claims->aud ?? null) !== $this->issuer()) {
            throw new RuntimeException('Invalid token issuer or audience.');
        }

        return $claims;
    }

    private function secret(): string
    {
        $secret = (string) config('jwt.secret');

        if (str_starts_with($secret, 'base64:')) {
            $secret = base64_decode(substr($secret, 7), true) ?: '';
        }

        if (strlen($secret) < 32) {
            throw new RuntimeException('JWT_SECRET must contain at least 32 bytes.');
        }

        return $secret;
    }

    private function issuer(): string
    {
        return (string) config('jwt.issuer');
    }
}
