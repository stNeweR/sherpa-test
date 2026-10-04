<?php

declare(strict_types=1);

namespace App\Dto\Auth;

/**
 * Разобранные claims JWT. Вместо массива со смешанными значениями — конкретные поля.
 */
final readonly class JwtClaims
{
    public function __construct(
        public string $subject,
        public string $email,
        public string $name,
        public int $issuedAt,
        public int $expiresAt,
    ) {
    }

    /**
     * @param array<array-key, mixed> $claims
     *
     * @return self|null null — обязательные claims отсутствуют или имеют неверный тип
     */
    public static function fromArray(array $claims): ?self
    {
        $subject = $claims['sub'] ?? null;
        $email = $claims['email'] ?? $subject;
        $name = $claims['name'] ?? null;

        if (!is_string($subject) || !is_string($email)) {
            return null;
        }

        return new self(
            subject: $subject,
            email: $email,
            name: is_string($name) ? $name : $email,
            issuedAt: is_int($claims['iat'] ?? null) ? $claims['iat'] : 0,
            expiresAt: is_int($claims['exp'] ?? null) ? $claims['exp'] : 0,
        );
    }
}
