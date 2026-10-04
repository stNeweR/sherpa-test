<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Внутренняя ошибка приложения. Клиенту отдаётся безопасный текст,
 * детали пишутся в лог.
 */
final class InternalErrorException extends DomainException
{
    public function httpStatus(): int
    {
        return 500;
    }
}
