<?php

declare(strict_types=1);

namespace App\Dto\Import;

use JsonSerializable;

/**
 * Итог одного запуска импорта.
 */
final readonly class ImportReportDto implements JsonSerializable
{
    /**
     * @param list<ImportErrorDto> $errors
     */
    public function __construct(
        public int $total,
        public int $imported,
        public int $updated,
        public int $failed,
        public float $durationSeconds,
        public array $errors = [],
    ) {
    }

    /**
     * @return array{
     *     total: int,
     *     imported: int,
     *     updated: int,
     *     failed: int,
     *     duration_seconds: float,
     *     errors: list<ImportErrorDto>,
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'total' => $this->total,
            'imported' => $this->imported,
            'updated' => $this->updated,
            'failed' => $this->failed,
            'duration_seconds' => round($this->durationSeconds, 3),
            'errors' => $this->errors,
        ];
    }
}
