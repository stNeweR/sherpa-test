<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Dto\Auth\JwtClaims;
use App\Entity\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

final readonly class JwtService
{
    public function __construct(
        private string $secret,
        private string $algorithm,
        private int $ttlSeconds,
    ) {
    }

    public function issue(User $user): string
    {
        $issuedAt = time();

        return JWT::encode(
            [
                'sub' => $user->getEmail(),
                'email' => $user->getEmail(),
                'name' => $user->getDisplayName(),
                'iat' => $issuedAt,
                'exp' => $issuedAt + $this->ttlSeconds,
            ],
            $this->secret,
            $this->algorithm,
        );
    }

    public function ttlSeconds(): int
    {
        return $this->ttlSeconds;
    }

    public function decode(string $token): ?JwtClaims
    {
        try {
            $claims = (array) JWT::decode($token, new Key($this->secret, $this->algorithm));
        } catch (\Throwable) {
            return null;
        }

        return JwtClaims::fromArray($claims);
    }
}
