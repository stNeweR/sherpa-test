<?php

declare(strict_types=1);

namespace App\Entity;

use App\Dto\Import\ImportErrorDto;
use App\Dto\Import\ImportJobState;
use App\Dto\Import\ImportReportDto;
use App\Repository\ImportJobRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use JsonSerializable;

#[ORM\Entity(repositoryClass: ImportJobRepository::class)]
#[ORM\Table(name: 'import_jobs')]
#[ORM\Index(name: 'idx_import_jobs_created_at', columns: ['created_at'])]
class ImportJob implements JsonSerializable
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'file_name', type: 'string', length: 255)]
    private string $fileName;

    #[ORM\Column(name: 'file_path', type: 'string', length: 1024)]
    private string $filePath;

    #[ORM\Column(type: 'string', length: 32)]
    private string $state;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $processed = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $imported = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $updated = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $failed = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $error = null;

    /**
     * @var array{
     *     total: int,
     *     imported: int,
     *     updated: int,
     *     failed: int,
     *     duration_seconds: float,
     *     errors: list<array{
     *         row_number: int,
     *         code: string,
     *         message: string,
     *         context: array<string, string>,
     *     }>,
     * }|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $report = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(string $id, string $fileName, string $filePath)
    {
        $this->id = $id;
        $this->fileName = $fileName;
        $this->filePath = $filePath;
        $this->state = ImportJobState::Pending->value;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function getState(): ImportJobState
    {
        return ImportJobState::from($this->state);
    }

    public function getProcessed(): int
    {
        return $this->processed;
    }

    public function getImported(): int
    {
        return $this->imported;
    }

    public function getUpdated(): int
    {
        return $this->updated;
    }

    public function getFailed(): int
    {
        return $this->failed;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getReport(): ?ImportReportDto
    {
        if ($this->report === null) {
            return null;
        }

        return new ImportReportDto(
            total: $this->report['total'],
            imported: $this->report['imported'],
            updated: $this->report['updated'],
            failed: $this->report['failed'],
            durationSeconds: $this->report['duration_seconds'],
            errors: array_map(
                static fn (array $error): ImportErrorDto => new ImportErrorDto(
                    rowNumber: $error['row_number'],
                    code: $error['code'],
                    message: $error['message'],
                    context: $error['context'],
                ),
                $this->report['errors'],
            ),
        );
    }

    /**
     * @return array{
     *     job_id: string,
     *     state: string,
     *     processed: int,
     *     imported: int,
     *     updated: int,
     *     failed: int,
     *     created_at: string,
     *     updated_at: string,
     *     error: string|null,
     *     report: ImportReportDto|null,
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'job_id' => $this->id,
            'state' => $this->state,
            'processed' => $this->processed,
            'imported' => $this->imported,
            'updated' => $this->updated,
            'failed' => $this->failed,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'updated_at' => $this->updatedAt->format(DATE_ATOM),
            'error' => $this->error,
            'report' => $this->getReport(),
        ];
    }

    public function markProcessing(): void
    {
        $this->state = ImportJobState::Processing->value;
        $this->touch();
    }

    public function markProgress(int $processed, int $imported, int $updated, int $failed): void
    {
        $this->processed = $processed;
        $this->imported = $imported;
        $this->updated = $updated;
        $this->failed = $failed;
        $this->touch();
    }

    public function markCompleted(ImportReportDto $report): void
    {
        $this->state = ImportJobState::Completed->value;
        $this->processed = $report->total;
        $this->imported = $report->imported;
        $this->updated = $report->updated;
        $this->failed = $report->failed;
        $this->report = [
            'total' => $report->total,
            'imported' => $report->imported,
            'updated' => $report->updated,
            'failed' => $report->failed,
            'duration_seconds' => round($report->durationSeconds, 3),
            'errors' => array_map(
                static fn (ImportErrorDto $error): array => $error->jsonSerialize(),
                $report->errors,
            ),
        ];
        $this->touch();
    }

    public function markFailed(string $error): void
    {
        $this->state = ImportJobState::Failed->value;
        $this->error = mb_substr($error, 0, 4000);
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
