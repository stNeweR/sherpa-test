<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Запрошенного ресурса нет.
 */
final class NotFoundException extends DomainException
{
    public function httpStatus(): int
    {
        return 404;
    }
}
