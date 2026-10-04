<?php

declare(strict_types=1);

namespace App\UseCase\Import;

use App\Dto\Import\ImportJobMessage;
use App\Entity\ImportJob;
use App\Exception\DomainException;
use App\Exception\InternalErrorException;
use App\Exception\InvalidInputException;
use App\Exception\TooManyRequestsException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Throwable;

/**
 * Приём XLSX-файла: лимит запросов, проверка файла, запись задачи и отправка
 * в RabbitMQ. Раньше эти шаги были разделены между ImportController
 * (лимит и коды ошибок загрузки) и ImportService, из-за чего правила
 * импорта нельзя было прочитать в одном месте.
 */
final readonly class CreateImportJobUseCase
{
    private const UPLOAD_ERRORS = [
        UPLOAD_ERR_INI_SIZE => 'Файл превышает допустимый размер',
        UPLOAD_ERR_FORM_SIZE => 'Файл превышает допустимый размер',
        UPLOAD_ERR_PARTIAL => 'Файл загружен частично',
        UPLOAD_ERR_NO_FILE => 'Файл не передан',
        UPLOAD_ERR_NO_TMP_DIR => 'Нет временного каталога',
        UPLOAD_ERR_CANT_WRITE => 'Не удалось записать файл на диск',
        UPLOAD_ERR_EXTENSION => 'Загрузка заблокирована расширением PHP',
    ];

    /**
     * @param list<string> $allowedExtensions
     * @param list<string> $allowedMimeTypes
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
        private RateLimiterFactory $rateLimiter,
        private LoggerInterface $logger,
        private string $storagePath,
        private int $maxFileSize,
        private array $allowedExtensions,
        private array $allowedMimeTypes,
    ) {
    }

    /**
     * @param string|null $clientId IP-адрес клиента: входит в ключ лимита, чтобы
     *                              один клиент не выбивал лимит остальным
     * @throws InvalidInputException файл не передан или не прошёл проверку
     * @throws TooManyRequestsException превышен лимит запросов импорта
     * @throws InternalErrorException задачу не удалось поставить в очередь
     */
    public function handle(?UploadedFileInterface $file, ?string $clientId = null): ImportJob
    {
        $limit = $this->rateLimiter->create(self::rateLimitKey($clientId))->consume();

        if (!$limit->isAccepted()) {
            throw new TooManyRequestsException(
                'Слишком много запросов импорта, попробуйте позже',
                max(0, $limit->getRetryAfter()->getTimestamp() - time()),
            );
        }

        if (!$file instanceof UploadedFileInterface) {
            throw new InvalidInputException('Файл не передан, ожидается поле file');
        }

        $uploadCode = $file->getError();

        if ($uploadCode !== UPLOAD_ERR_OK) {
            throw new InvalidInputException(self::UPLOAD_ERRORS[$uploadCode] ?? 'Не удалось загрузить файл');
        }

        $this->validate($file);

        try {
            $jobId = self::generateJobId();
            $originalName = self::sanitizeFileName($file);
            $storedPath = $this->storeUploadedFile($file, $jobId);

            $job = new ImportJob($jobId, $originalName, $storedPath);

            $this->entityManager->wrapInTransaction(
                function (EntityManagerInterface $entityManager) use ($job): void {
                    $entityManager->persist($job);
                    $entityManager->flush();
                },
            );

            $this->bus->dispatch(new ImportJobMessage(
                jobId: $jobId,
                filePath: $storedPath,
                originalName: $originalName,
            ));
        } catch (DomainException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->logger->error('Задача импорта не поставлена в очередь', ['error' => $e->getMessage()]);

            throw new InternalErrorException('Не удалось поставить файл в очередь');
        }

        return $job;
    }

    /**
     * Лимит применяется на адрес клиента, а не на весь сервис. Ключ кэша Symfony
     * не допускает "{", "}", "(", ")", "/", "\", "@", ":", поэтому IPv6 и любые
     * нестандартные символы нормализуем в безопасный вид.
     */
    private static function rateLimitKey(?string $clientId): string
    {
        $normalized = strtolower(trim((string) $clientId));
        $safe = preg_replace('/[^a-z0-9]+/', '-', $normalized);

        if ($safe === null || $safe === '') {
            $safe = 'unknown';
        }

        return 'api-imports-' . $safe;
    }

    private function validate(UploadedFileInterface $file): void
    {
        $extension = strtolower(pathinfo(self::sanitizeFileName($file), PATHINFO_EXTENSION));

        if (!in_array($extension, $this->allowedExtensions, true)) {
            throw new InvalidInputException('Допустимы только файлы .xlsx');
        }

        $size = $file->getSize();

        if ($size === null || $size === 0) {
            throw new InvalidInputException('Файл пустой');
        }

        if ($size > $this->maxFileSize) {
            throw new InvalidInputException(sprintf(
                'Файл больше лимита в %d МБ',
                intdiv($this->maxFileSize, 1024 * 1024),
            ));
        }

        $mimeType = (string) $file->getClientMediaType();

        if (!in_array($mimeType, $this->allowedMimeTypes, true)) {
            throw new InvalidInputException(sprintf('Недопустимый тип файла: %s', $mimeType));
        }

        if (!$this->looksLikeZipArchive($file)) {
            throw new InvalidInputException('Файл не является XLSX (ожидается zip-архив)');
        }
    }

    /**
     * Браузеры и curl часто присылают application/octet-stream, поэтому сверяем сигнатуру ZIP,
     * а не только заявленный MIME: XLSX — это zip-архив, начинающийся с "PK\x03\x04".
     */
    private function looksLikeZipArchive(UploadedFileInterface $file): bool
    {
        $stream = $file->getStream();
        $position = $stream->isSeekable() ? $stream->tell() : null;

        if ($position !== null) {
            $stream->rewind();
        }

        $signature = $stream->read(4);

        if ($position !== null) {
            $stream->seek($position);
        }

        return $signature === "PK\x03\x04";
    }

    private function storeUploadedFile(UploadedFileInterface $file, string $jobId): string
    {
        $targetDir = rtrim($this->storagePath, '/');

        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new InvalidInputException(sprintf('Не удалось создать каталог %s', $targetDir));
        }

        $target = sprintf('%s/%s.xlsx', $targetDir, $jobId);
        $file->moveTo($target);

        return $target;
    }

    private static function sanitizeFileName(UploadedFileInterface $file): string
    {
        return basename(str_replace(['\\', "\0"], '', $file->getClientFilename() ?? 'import.xlsx'));
    }

    private static function generateJobId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
