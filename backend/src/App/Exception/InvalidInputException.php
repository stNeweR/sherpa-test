<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Некорректные входные данные: клиент прислал то, с чем работать нельзя.
 */
final class InvalidInputException extends DomainException
{
    public function httpStatus(): int
    {
        return 400;
    }
}
