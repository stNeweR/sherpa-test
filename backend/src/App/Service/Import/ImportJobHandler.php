<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Dto\Import\ImportErrorDto;
use App\Dto\Import\ImportJobMessage;
use App\Dto\Import\ImportReportDto;
use App\Dto\Import\ProductDraft;
use App\Dto\Import\ProductImageDraft;
use App\Entity\ImportJob;
use App\Repository\ImportJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class ImportJobHandler
{
    private const PROGRESS_FLUSH_EVERY = 10;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ImportJobRepository $jobs,
        private XlsxRowReader $reader,
        private RowNormalizer $normalizer,
        private ImageDownloader $imageDownloader,
        private ProductImporter $importer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ImportJobMessage $message): void
    {
        $job = $this->jobs->findJob($message->jobId);

        if ($job === null) {
            $this->logger->error('Задача импорта не найдена', ['job_id' => $message->jobId]);

            return;
        }

        $startedAt = microtime(true);
        $errors = [];
        $processed = 0;
        $imported = 0;
        $updated = 0;

        $job->markProcessing();
        $job = $this->flush($job);

        try {
            $this->process($message, $job, $startedAt, $errors, $processed, $imported, $updated);
        } catch (Throwable $e) {
            $this->logger->error('Импорт упал', [
                'job_id' => $job->getId(),
                'error' => $e->getMessage(),
            ]);

            if ($this->entityManager->isOpen()) {
                $job->markFailed($e->getMessage());
                $this->flush($job);
            }

            throw $e;
        }

        $this->logger->info('Импорт завершён', [
            'job_id' => $job->getId(),
            'total' => $processed,
            'imported' => $imported,
            'updated' => $updated,
            'failed' => count($errors),
        ]);
    }

    /**
     * @param list<ImportErrorDto> $errors
     */
    private function process(
        ImportJobMessage $message,
        ImportJob $job,
        float $startedAt,
        array &$errors,
        int &$processed,
        int &$imported,
        int &$updated,
    ): void {
        foreach ($this->reader->read($message->filePath) as $row) {
            $processed++;

            match ($this->processRow($row->rowNumber, $this->normalizer->normalize($row), $errors)) {
                'imported' => $imported++,
                'updated' => $updated++,
                default => null,
            };

            if ($processed % self::PROGRESS_FLUSH_EVERY === 0) {
                $job->markProgress($processed, $imported, $updated, count($errors));
                $job = $this->flush($job);
            }
        }

        $job->markCompleted(new ImportReportDto(
            total: $processed,
            imported: $imported,
            updated: $updated,
            failed: count($errors),
            durationSeconds: microtime(true) - $startedAt,
            errors: $errors,
        ));
        $this->flush($job);
        $this->removeUploadedFile($message->filePath);
    }

    /**
     * @param list<ImportErrorDto> $errors
     *
     * @return string 'imported'|'updated'|'failed'
     */
    private function processRow(int $rowNumber, ProductDraft|ImportErrorDto $normalized, array &$errors): string
    {
        if ($normalized instanceof ImportErrorDto) {
            $errors[] = $normalized;
            $this->logger->warning('Строка импорта отклонена', [
                'row' => $normalized->rowNumber,
                'code' => $normalized->code,
            ]);

            return 'failed';
        }

        $download = $this->imageDownloader->downloadAll($normalized->images, $normalized->externalCode);

        $images = $download['images'];

        foreach ($download['errors'] as $url => $message) {
            $errors[] = new ImportErrorDto(
                rowNumber: $rowNumber,
                code: ImageDownloader::ERROR_IMAGE_DOWNLOAD_FAILED,
                message: $message,
                context: ['external_code' => $normalized->externalCode, 'url' => (string) $url],
            );

            $images[] = new ProductImageDraft(url: (string) $url, path: null);
        }

        $created = $this->importer->import(new ProductDraft(
            externalCode: $normalized->externalCode,
            name: $normalized->name,
            description: $normalized->description,
            price: $normalized->price,
            purchasePrice: $normalized->purchasePrice,
            attributes: $normalized->attributes,
            images: $images,
        ));

        return $created ? 'imported' : 'updated';
    }

    private function removeUploadedFile(string $filePath): void
    {
        if (is_file($filePath) && !unlink($filePath)) {
            $this->logger->warning('Не удалось удалить файл импорта', ['path' => $filePath]);
        }
    }

    /**
     * Задача остаётся managed, поэтому persist() обновляет её, а не вставляет заново:
     * после clear() сущность отсоединена и ORM 3 больше не умеет merge().
     * Остальные сущности отсоединяются, чтобы не держать в памяти весь импорт.
     */
    private function flush(ImportJob $job): ImportJob
    {
        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($job): void {
            $entityManager->persist($job);
            $entityManager->flush();
        });

        foreach ($this->entityManager->getUnitOfWork()->getIdentityMap() as $entities) {
            foreach ($entities as $entity) {
                if ($entity !== $job) {
                    $this->entityManager->detach($entity);
                }
            }
        }

        return $job;
    }
}
