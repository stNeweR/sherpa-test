<?php

declare(strict_types=1);

namespace App\UseCase\Import;

use App\Entity\ImportJob;
use App\Exception\NotFoundException;
use App\Repository\ImportJobRepository;

/**
 * Снимок состояния задачи импорта для polled-эндпоинта.
 */
final readonly class GetImportJobUseCase
{
    public function __construct(private ImportJobRepository $jobs)
    {
    }

    /** @throws NotFoundException */
    public function handle(string $jobId): ImportJob
    {
        $job = $this->jobs->findJob($jobId);

        if ($job === null) {
            throw new NotFoundException('Задача не найдена');
        }

        return $job;
    }
}
