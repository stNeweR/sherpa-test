<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Product;
use App\Entity\ProductAttribute;
use App\Entity\ProductImage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductTest extends TestCase
{
    #[DataProvider('discountProvider')]
    public function testDiscountIsRecalculatedOnCreateAndUpdate(
        float $price,
        float $purchasePrice,
        string $expected,
    ): void {
        $product = new Product('EXT-1', 'Товар', '', $price, $purchasePrice);

        $this->assertSame($expected, $product->getDiscount());
        $this->assertSame(number_format($price, 2, '.', ''), $product->getPrice());
        $this->assertSame(number_format($purchasePrice, 2, '.', ''), $product->getPurchasePrice());
    }

    /** @return iterable<string, array{float, float, string}> */
    public static function discountProvider(): iterable
    {
        yield 'обычная скидка' => [1320.0, 880.0, '33.33'];
        yield 'закупочная равна цене' => [100.0, 100.0, '0.00'];
        yield 'закупочная выше цены — скидка не отрицательная' => [100.0, 150.0, '0.00'];
        yield 'закупочная пустая — скидка 100%' => [100.0, 0.0, '100.00'];
        yield 'нулевая цена — скидка 0%' => [0.0, 50.0, '0.00'];
    }

    public function testUpdateRecalculatesDiscountAndKeepsRelations(): void
    {
        $product = new Product('EXT-1', 'Товар', 'Описание', 100.0, 50.0);
        $product->replaceAttributes(['Цвет' => 'Red']);
        $product->replaceImages([['url' => 'http://cdn.test/a.jpg', 'path' => '/media/a.jpg']]);

        $product->update('Новое имя', 'Новое описание', 200.0, 100.0);

        $this->assertSame('Новое имя', $product->getName());
        $this->assertSame('Новое описание', $product->getDescription());
        $this->assertSame('50.00', $product->getDiscount());
        $this->assertCount(1, $product->getAttributes());
        $this->assertCount(1, $product->getImages());
    }

    public function testReplaceAttributesIsIdempotent(): void
    {
        $product = new Product('EXT-1', 'Товар');

        $product->replaceAttributes(['Цвет' => 'Red', 'Размер' => '46']);
        $product->replaceAttributes(['Размер' => '48']);

        $this->assertCount(1, $product->getAttributes());

        $attribute = $product->getAttributes()->first();
        $this->assertInstanceOf(ProductAttribute::class, $attribute);
        $this->assertSame('48', $attribute->getValue());
    }

    public function testRelationsPointBackToProduct(): void
    {
        $product = new Product('EXT-1', 'Товар');

        $product->replaceAttributes(['Цвет' => 'Red']);
        $product->replaceImages([['url' => 'http://cdn.test/a.jpg', 'path' => null]]);

        $attribute = $product->getAttributes()->first();
        $image = $product->getImages()->first();

        $this->assertInstanceOf(ProductAttribute::class, $attribute);
        $this->assertSame($product, $attribute->getProduct());
        $this->assertInstanceOf(ProductImage::class, $image);
        $this->assertSame($product, $image->getProduct());
    }
}
