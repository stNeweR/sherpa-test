<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Import;

use App\Dto\Import\ImportErrorDto;
use App\Dto\Import\ProductDraft;
use App\Dto\Import\RawProductRow;
use App\Service\Import\RowNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RowNormalizerTest extends TestCase
{
    private RowNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new RowNormalizer();
    }

    public function testConvertsCommaDecimalSeparatorAndKeepsImagesOutOfAttributes(): void
    {
        $result = $this->normalizer->normalize($this->row(
            externalCode: 'EXT-1',
            name: 'Бермуды',
            price: '1320,00',
            purchasePrice: '880,50',
            attributes: ['Цвет' => 'Grigio/Verde', 'Ссылки на фото' => 'http://cdn.test/a.jpg'],
            imageUrls: ['http://cdn.test/a.jpg'],
        ));

        $this->assertInstanceOf(ProductDraft::class, $result);
        $this->assertSame(1320.0, $result->price);
        $this->assertSame(880.5, $result->purchasePrice);
        $this->assertSame(['Цвет' => 'Grigio/Verde'], $result->attributes);
        $this->assertCount(1, $result->images);
        $this->assertSame('http://cdn.test/a.jpg', $result->images[0]->url);
    }

    #[DataProvider('invalidRowsProvider')]
    public function testRejectsBrokenRows(string $code, string $name, string $price, string $purchase, string $expectedError): void
    {
        $result = $this->normalizer->normalize($this->row(
            externalCode: $code,
            name: $name,
            price: $price,
            purchasePrice: $purchase,
        ));

        $this->assertInstanceOf(ImportErrorDto::class, $result);
        $this->assertSame($expectedError, $result->code);
        $this->assertSame(7, $result->rowNumber);
    }

    /** @return iterable<string, array{string, string, string, string, string}> */
    public static function invalidRowsProvider(): iterable
    {
        yield 'нет внешнего кода' => ['', 'Товар', '100', '50', RowNormalizer::ERROR_MISSING_EXTERNAL_CODE];
        yield 'нет наименования' => ['EXT-1', '', '100', '50', RowNormalizer::ERROR_MISSING_NAME];
        yield 'цена не число' => ['EXT-1', 'Товар', 'abc', '50', RowNormalizer::ERROR_INVALID_PRICE];
        yield 'закупочная не число' => ['EXT-1', 'Товар', '100', 'N/A', RowNormalizer::ERROR_INVALID_PURCHASE_PRICE];
    }

    public function testEmptyPurchasePriceIsAllowedAndClampsDiscountAtHundredPercent(): void
    {
        $result = $this->normalizer->normalize($this->row(
            externalCode: 'EXT-1',
            name: 'Товар',
            price: '100',
            purchasePrice: '',
        ));

        $this->assertInstanceOf(ProductDraft::class, $result);
        $this->assertSame(0.0, $result->purchasePrice);
    }

    public function testPurchasePriceAboveSalePriceIsAcceptedBecauseDiscountIsClamped(): void
    {
        $result = $this->normalizer->normalize($this->row(
            externalCode: 'EXT-1',
            name: 'Товар',
            price: '100',
            purchasePrice: '150',
        ));

        $this->assertInstanceOf(ProductDraft::class, $result);
        $this->assertSame(150.0, $result->purchasePrice);
    }

    public function testKeepsIntegerAmountsAsFloats(): void
    {
        $result = $this->normalizer->normalize($this->row(
            externalCode: 'EXT-1',
            name: 'Товар',
            price: '1 320',
            purchasePrice: '880',
        ));

        $this->assertInstanceOf(ProductDraft::class, $result);
        $this->assertSame(1320.0, $result->price);
        $this->assertSame(880.0, $result->purchasePrice);
    }

    /**
     * @param array<string, string> $attributes
     * @param list<string>          $imageUrls
     */
    private function row(
        string $externalCode,
        string $name,
        string $price,
        string $purchasePrice,
        array $attributes = [],
        array $imageUrls = [],
    ): RawProductRow {
        return new RawProductRow(
            rowNumber: 7,
            externalCode: $externalCode,
            name: $name,
            description: 'Описание',
            price: $price,
            purchasePrice: $purchasePrice,
            attributes: $attributes,
            imageUrls: $imageUrls,
        );
    }
}
