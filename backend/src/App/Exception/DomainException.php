<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * Ошибка бизнес-логики. Use case сообщает о ней через конкретные подклассы,
 * а HTTP-слой превращает её в ответ API — контроллеры не знают ни про коды
 * статуса, ни про формат тела ошибки.
 */
abstract class DomainException extends RuntimeException
{
    abstract public function httpStatus(): int;

    /** @return array<string, mixed> */
    public function errorBody(): array
    {
        return ['error' => $this->getMessage()];
    }

    /** @return array<string, string> */
    public function responseHeaders(): array
    {
        return [];
    }
}
