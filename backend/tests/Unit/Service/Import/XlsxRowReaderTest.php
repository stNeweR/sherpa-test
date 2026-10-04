<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Import;

use App\Dto\Import\RawProductRow;
use App\Service\Import\XlsxRowReader;
use PHPUnit\Framework\TestCase;

final class XlsxRowReaderTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../../Fixtures/import-example.xlsx';

    public function testReadsAllProductRowsFromFixture(): void
    {
        $rows = iterator_to_array($this->reader()->read(self::FIXTURE), false);

        $this->assertCount(40, $rows);
    }

    public function testMapsColumnsByZeroBasedIndexes(): void
    {
        $row = $this->firstRow();

        $this->assertNotSame('', $row->externalCode);
        $this->assertStringContainsString('Бермуды', $row->name);
        $this->assertNotSame('', $row->description);
        $this->assertSame('1320,00', $row->price);
        $this->assertSame('880,00', $row->purchasePrice);
        $this->assertSame(2, $row->rowNumber);
    }

    public function testExtractsPhotoLinksIntoSeparateList(): void
    {
        $row = $this->firstRow();

        $this->assertNotEmpty($row->imageUrls);
        $this->assertSame([], array_filter($row->imageUrls, static fn (string $url): bool => $url === ''));
        $this->assertSame(
            XlsxRowReader::PHOTO_LINKS_ATTRIBUTE,
            'Ссылки на фото',
        );
    }

    public function testPrefixedColumnsBecomeAttributesExceptPhotoLinks(): void
    {
        $row = $this->firstRow();

        $this->assertNotEmpty($row->attributes);
        $this->assertArrayHasKey('Цвет', $row->attributes);
        $this->assertArrayHasKey('Размер', $row->attributes);
        $this->assertArrayNotHasKey('Доп. поле: Цвет', $row->attributes);
        $this->assertArrayNotHasKey(XlsxRowReader::PHOTO_LINKS_ATTRIBUTE, $row->attributes);
        $this->assertNotContains(
            $row->imageUrls,
            $row->attributes,
        );
    }

    public function testExternalCodesAreUnique(): void
    {
        $codes = array_map(
            static fn (RawProductRow $row): string => $row->externalCode,
            iterator_to_array($this->reader()->read(self::FIXTURE), false),
        );

        $this->assertSame(array_unique($codes), $codes);
    }

    private function firstRow(): RawProductRow
    {
        $rows = iterator_to_array($this->reader()->read(self::FIXTURE), false);
        $first = $rows[0] ?? null;

        $this->assertInstanceOf(RawProductRow::class, $first);

        return $first;
    }

    private function reader(): XlsxRowReader
    {
        return new XlsxRowReader();
    }
}
