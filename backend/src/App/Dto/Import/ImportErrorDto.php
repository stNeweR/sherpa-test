<?php

declare(strict_types=1);

namespace App\Dto\Import;

use JsonSerializable;

/**
 * Ошибка одной строки импорта. Ошибка не прерывает процесс — строки собираются
 * в отчёт и возвращаются клиенту.
 */
final readonly class ImportErrorDto implements JsonSerializable
{
    /**
     * @param array<string, string> $context
     */
    public function __construct(
        public int $rowNumber,
        public string $code,
        public string $message,
        public array $context = [],
    ) {
    }

    /** @return array{row_number: int, code: string, message: string, context: array<string, string>} */
    public function jsonSerialize(): array
    {
        return [
            'row_number' => $this->rowNumber,
            'code' => $this->code,
            'message' => $this->message,
            'context' => $this->context,
        ];
    }
}
