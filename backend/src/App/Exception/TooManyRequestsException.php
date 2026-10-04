<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Клиент превысил лимит запросов. Значение retryAfterSeconds попадает и в тело
 * ответа, и в заголовок Retry-After.
 */
final class TooManyRequestsException extends DomainException
{
    public function __construct(string $message, private readonly int $retryAfterSeconds)
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return 429;
    }

    /** @return array{error: string, retry_after: int} */
    public function errorBody(): array
    {
        return ['error' => $this->getMessage(), 'retry_after' => $this->retryAfterSeconds];
    }

    /** @return array{Retry-After: string} */
    public function responseHeaders(): array
    {
        return ['Retry-After' => (string) $this->retryAfterSeconds];
    }
}
