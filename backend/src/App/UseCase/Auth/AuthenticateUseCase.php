<?php

declare(strict_types=1);

namespace App\UseCase\Auth;

use App\Dto\Auth\JwtClaims;
use App\Service\Auth\JwtService;

/**
 * Проверка заголовка Authorization. Используется middleware, поэтому
 * возвращает null вместо исключения: решение «пускать дальше или нет»
 * остаётся у вызывающего.
 */
final readonly class AuthenticateUseCase
{
    public function __construct(private JwtService $jwt)
    {
    }

    public function handle(?string $authorizationHeader): ?JwtClaims
    {
        if ($authorizationHeader === null || !str_starts_with($authorizationHeader, 'Bearer ')) {
            return null;
        }

        return $this->jwt->decode(trim(substr($authorizationHeader, 7)));
    }
}
