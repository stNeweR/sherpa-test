<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Import;

use App\Dto\Import\ImportJobMessage;
use App\Dto\Import\ImportJobState;
use App\Entity\ImportJob;
use App\Service\Import\ImportJobHandler;
use App\Service\Import\XlsxRowReader;
use App\Tests\Integration\DatabaseTestCase;
use Core\Container\ServiceFetcher;
use Doctrine\ORM\EntityManagerInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class ImportPipelineTest extends DatabaseTestCase
{
    private const COLUMNS = 14;

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir() . '/import-pipeline-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->workDir) && is_dir($this->workDir)) {
            foreach (glob($this->workDir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->workDir);
        }

        parent::tearDown();
    }

    public function testImportsFileAndKeepsProductWhenImageDownloadFails(): void
    {
        $file = $this->createXlsx([
            ['EXT-1', 'Бермуды', '1320,00', '880,00', 'http://127.0.0.1:1/unreachable.jpg'],
            ['EXT-2', 'Шорты', '900', '450,00', 'http://127.0.0.1:1/unreachable.jpg'],
        ]);

        $job = $this->createJob($file);
        $this->handle($job);

        $job = $this->reloadJob($job->getId());

        $this->assertSame(ImportJobState::Completed, $job->getState());
        $this->assertSame(2, $job->getProcessed());
        $this->assertSame(2, $job->getImported());
        $this->assertSame(0, $job->getUpdated());
        $this->assertSame(2, $job->getFailed(), 'Каждая строка содержит недоступное фото');

        $report = $job->getReport();
        $this->assertNotNull($report);
        $this->assertSame(2, $report->total);
        $this->assertSame(2, $report->failed);
        $this->assertSame('image_download_failed', $report->errors[0]->code);
        $this->assertNotSame('', $report->errors[0]->message);

        $this->assertSame(2, $this->countProducts());
        $this->assertSame('33.33', $this->scalar(
            "SELECT discount FROM products WHERE external_code = 'EXT-1'",
        ));

        $this->assertSame(2, $this->countRows('SELECT COUNT(*) FROM product_images'));
        $this->assertSame(0, $this->countRows('SELECT COUNT(*) FROM product_images WHERE path IS NOT NULL'));
        $this->assertSame(2, $this->countRows(
            "SELECT COUNT(*) FROM product_attributes WHERE key = 'Цвет'",
        ), 'У каждого из двух товаров свой атрибут «Цвет»');
    }

    public function testSecondRunUpdatesProductsInsteadOfDuplicatingThem(): void
    {
        $rows = [
            ['EXT-1', 'Бермуды', '1320,00', '880,00', ''],
        ];

        $first = $this->createJob($this->createXlsx($rows));
        $this->handle($first);
        $this->reloadJob($first->getId());
        $this->assertSame(1, $this->reloadJob($first->getId())->getImported());

        $second = $this->createJob($this->createXlsx($rows));
        $this->handle($second);
        $reloaded = $this->reloadJob($second->getId());

        $this->assertSame(ImportJobState::Completed, $reloaded->getState());
        $this->assertSame(0, $reloaded->getImported());
        $this->assertSame(1, $reloaded->getUpdated());
        $this->assertSame(0, $reloaded->getFailed());
        $this->assertSame(1, $this->countProducts(), 'Повторный импорт не создаёт дубликаты');
    }

    public function testBrokenRowsAreSkippedAndDoNotBlockValidOnes(): void
    {
        $file = $this->createXlsx(
            [
                ['EXT-1', 'Бермуды', '1320,00', '880,00', ''],
                ['', 'Без кода', '100', '50', ''],
                ['EXT-3', 'Битая цена', 'не число', '50', ''],
            ],
        );

        $job = $this->createJob($file);
        $this->handle($job);

        $reloaded = $this->reloadJob($job->getId());
        $report = $reloaded->getReport();

        $this->assertSame(ImportJobState::Completed, $reloaded->getState());
        $this->assertSame(1, $reloaded->getImported());
        $this->assertSame(2, $reloaded->getFailed());
        $this->assertNotNull($report);

        $codes = array_map(
            static fn ($error): string => (string) $error->code,
            $report->errors,
        );

        $this->assertContains('missing_external_code', $codes);
        $this->assertContains('invalid_price', $codes);
        $this->assertSame(1, $this->countProducts());
    }

    public function testUploadedFileIsRemovedAfterSuccessfulProcessing(): void
    {
        $file = $this->createXlsx([['EXT-1', 'Бермуды', '1320,00', '880,00', '']]);

        $this->handle($this->createJob($file));

        $this->assertFileDoesNotExist($file);
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: string, 4: string}> $rows
     */
    private function createXlsx(array $rows): string
    {
        $path = $this->workDir . '/import-' . bin2hex(random_bytes(4)) . '.xlsx';

        $header = array_fill(0, self::COLUMNS, '');
        $header[4] = 'Наименование';
        $header[5] = 'Артикул';
        $header[8] = 'Цена продажи';
        $header[10] = 'Описание';
        $header[11] = 'Закупочная цена';
        $header[12] = 'Доп. поле: Цвет';
        $header[13] = 'Доп. поле: ' . XlsxRowReader::PHOTO_LINKS_ATTRIBUTE;

        $writer = new Writer();
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($header));

        foreach ($rows as [$code, $name, $price, $purchasePrice, $photo]) {
            $row = array_fill(0, self::COLUMNS, '');
            $row[4] = $name;
            $row[5] = $code;
            $row[8] = $price;
            $row[10] = 'Описание ' . $code;
            $row[11] = $purchasePrice;
            $row[12] = 'Grigio/Verde';
            $row[13] = $photo;
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();

        return $path;
    }

    private function createJob(string $file): ImportJob
    {
        $job = new ImportJob(
            id: sprintf('job-%s', bin2hex(random_bytes(6))),
            fileName: 'import.xlsx',
            filePath: $file,
        );

        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $job;
    }

    private function handle(ImportJob $job): void
    {
        $handler = (new ServiceFetcher($this->container))->get(ImportJobHandler::class);

        $handler(new ImportJobMessage(
            jobId: $job->getId(),
            filePath: $job->getFilePath(),
            originalName: $job->getFileName(),
        ));
    }

    private function reloadJob(string $id): ImportJob
    {
        $this->entityManager->clear();

        $job = (new ServiceFetcher($this->container))
            ->get(EntityManagerInterface::class)
            ->getRepository(ImportJob::class)
            ->find($id);

        $this->assertInstanceOf(ImportJob::class, $job);

        return $job;
    }
}
