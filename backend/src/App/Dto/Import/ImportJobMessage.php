<?php

declare(strict_types=1);

namespace App\Dto\Import;

/**
 * Сообщение в очередь: воркер получает только путь к уже загруженному файлу,
 * сам файл по AMQP не передаётся.
 */
final readonly class ImportJobMessage
{
    public function __construct(
        public string $jobId,
        public string $filePath,
        public string $originalName,
    ) {
    }
}
