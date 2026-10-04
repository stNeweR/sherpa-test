<?php

declare(strict_types=1);

namespace App\Tests\Unit\UseCase\Import;

use App\Dto\Import\ImportJobMessage;
use App\Exception\InvalidInputException;
use App\Exception\TooManyRequestsException;
use App\UseCase\Import\CreateImportJobUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\UploadedFile;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Приём файла — точка, где задание требует валидацию (MIME, размер, расширение)
 * и ограничение частоты запросов. Раньше обе проверки были без тестов.
 */
final class CreateImportJobUseCaseTest extends TestCase
{
    private const MAX_SIZE = 1048576;

    /** @var list<string> */
    private array $tempFiles = [];

    private RecordingMessageBus $bus;
    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bus = new RecordingMessageBus();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->tempFiles = [];

        parent::tearDown();
    }

    public function testValidFileIsAcceptedAndDispatchedToQueue(): void
    {
        $useCase = $this->useCase();

        $job = $useCase->handle($this->xlsx('products.xlsx'), '203.0.113.7');

        self::assertSame('products.xlsx', $job->getFileName());
        self::assertFileExists($job->getFilePath());
        self::assertStringEndsWith('.xlsx', $job->getFilePath());
        self::assertCount(1, $this->bus->messages);
        self::assertInstanceOf(ImportJobMessage::class, $this->bus->messages[0]);
        self::assertSame($job->getFilePath(), $this->bus->messages[0]->filePath);
    }

    public function testFileNameIsSanitizedBeforeStoring(): void
    {
        $useCase = $this->useCase();

        $job = $useCase->handle($this->xlsx('../../etc/passwd.xlsx'), '203.0.113.7');

        self::assertSame('passwd.xlsx', $job->getFileName());
        self::assertSame($this->storagePath . '/' . $job->getId() . '.xlsx', $job->getFilePath());
    }

    public function testFileIsRejectedWhenExtensionIsNotAllowed(): void
    {
        $useCase = $this->useCase();

        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('Допустимы только файлы .xlsx');

        $useCase->handle($this->xlsx('report.csv'), '203.0.113.7');
    }

    public function testFileIsRejectedWhenClientMimeTypeIsNotAllowed(): void
    {
        $useCase = $this->useCase();

        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('Недопустимый тип файла: text/csv');

        $useCase->handle($this->xlsx('products.xlsx', 'text/csv'), '203.0.113.7');
    }

    public function testFileIsRejectedWhenSignatureIsNotZip(): void
    {
        $useCase = $this->useCase();

        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('Файл не является XLSX');

        $useCase->handle($this->raw('products.xlsx', 'переименованный csv'), '203.0.113.7');
    }

    public function testFileIsRejectedWhenLargerThanLimit(): void
    {
        $useCase = $this->useCase();

        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('Файл больше лимита в 1 МБ');

        $useCase->handle($this->xlsx('huge.xlsx', null, self::MAX_SIZE + 1), '203.0.113.7');
    }

    public function testMissingFileIsRejected(): void
    {
        $useCase = $this->useCase();

        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('Файл не передан');

        $useCase->handle(null, '203.0.113.7');
    }

    public function testFailedUploadIsReportedWithUploadError(): void
    {
        $useCase = $this->useCase();

        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('Файл превышает допустимый размер');

        $useCase->handle($this->uploaded('products.xlsx', UPLOAD_ERR_INI_SIZE), '203.0.113.7');
    }

    public function testSixthRequestFromSameClientIsRateLimited(): void
    {
        $useCase = $this->useCase(5);

        for ($i = 0; $i < 5; $i++) {
            $useCase->handle($this->xlsx('products.xlsx'), '203.0.113.7');
        }

        $this->expectException(TooManyRequestsException::class);
        $this->expectExceptionMessage('Слишком много запросов импорта');

        $useCase->handle($this->xlsx('products.xlsx'), '203.0.113.7');
    }

    public function testRateLimitIsIsolatedPerClient(): void
    {
        $useCase = $this->useCase(5);

        for ($i = 0; $i < 5; $i++) {
            $useCase->handle($this->xlsx('products.xlsx'), '203.0.113.7');
        }

        $job = $useCase->handle($this->xlsx('products.xlsx'), '198.51.100.4');

        self::assertSame('products.xlsx', $job->getFileName());
    }

    public function testRateLimitKeySurvivesIpv6Address(): void
    {
        $useCase = $this->useCase(1);
        $useCase->handle($this->xlsx('products.xlsx'), '2001:db8::1');

        $this->expectException(TooManyRequestsException::class);

        $useCase->handle($this->xlsx('products.xlsx'), '2001:db8::1');
    }

    public function testRateLimitErrorCarriesHttpStatus(): void
    {
        $useCase = $this->useCase(1);
        $useCase->handle($this->xlsx('products.xlsx'), '203.0.113.7');

        try {
            $useCase->handle($this->xlsx('products.xlsx'), '203.0.113.7');
            self::fail('Ожидалось исключение');
        } catch (TooManyRequestsException $e) {
            self::assertSame(429, $e->httpStatus());
            self::assertArrayHasKey('retry_after', $e->errorBody());
        }
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function uploadErrorProvider(): iterable
    {
        yield 'нет файла' => [UPLOAD_ERR_NO_FILE, 'Файл не передан'];
        yield 'частично' => [UPLOAD_ERR_PARTIAL, 'Файл загружен частично'];
        yield 'нет временного каталога' => [UPLOAD_ERR_NO_TMP_DIR, 'Нет временного каталога'];
    }

    #[DataProvider('uploadErrorProvider')]
    public function testUploadErrorsAreTranslated(int $code, string $message): void
    {
        $useCase = $this->useCase();

        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage($message);

        $useCase->handle($this->uploaded('products.xlsx', $code), '203.0.113.7');
    }

    private function useCase(int $limit = 100): CreateImportJobUseCase
    {
        $storage = sys_get_temp_dir() . '/sherpa-import-tests-' . bin2hex(random_bytes(6));
        mkdir($storage, 0775, true);
        $this->storagePath = $storage;

        return new CreateImportJobUseCase(
            entityManager: $this->createStub(EntityManagerInterface::class),
            bus: $this->bus,
            rateLimiter: new RateLimiterFactory(
                [
                    'id' => 'imports',
                    'policy' => 'token_bucket',
                    'limit' => $limit,
                    'rate' => ['interval' => '1 minute', 'amount' => $limit],
                ],
                new CacheStorage(new ArrayAdapter()),
            ),
            logger: new NullLogger(),
            storagePath: $this->storagePath,
            maxFileSize: self::MAX_SIZE,
            allowedExtensions: ['xlsx'],
            allowedMimeTypes: [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/octet-stream',
            ],
        );
    }

    private function xlsx(string $name, ?string $mimeType = null, int $size = 64): UploadedFileInterface
    {
        return $this->uploaded(
            $name,
            UPLOAD_ERR_OK,
            $mimeType ?? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $size,
            true,
        );
    }

    private function raw(string $name, string $contents, ?string $mimeType = null): UploadedFileInterface
    {
        return $this->uploaded(
            $name,
            UPLOAD_ERR_OK,
            $mimeType ?? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            max(1, strlen($contents)),
            false,
            $contents,
        );
    }

    private function uploaded(
        string $name,
        int $error,
        ?string $mimeType = null,
        ?int $size = null,
        bool $zipSignature = true,
        ?string $contents = null,
    ): UploadedFileInterface {
        $path = tempnam(sys_get_temp_dir(), 'upload-');
        self::assertIsString($path);

        file_put_contents($path, $contents ?? ($zipSignature ? "PK\x03\x04" . random_bytes(64) : 'a,b,c' . random_bytes(64)));

        $this->tempFiles[] = $path;

        /**
     * Файл передаётся путём, а не потоком: так UploadedFile::moveTo() работает
     * без sapi-режима — ровно как файл, пришедший из HTTP-тела запроса.
 */
        return new UploadedFile($path, $name, $mimeType, $size, $error);
    }
}
