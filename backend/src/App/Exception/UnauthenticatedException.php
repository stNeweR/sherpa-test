<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Не удалось подтвердить личность клиента.
 */
final class UnauthenticatedException extends DomainException
{
    public function httpStatus(): int
    {
        return 401;
    }
}
