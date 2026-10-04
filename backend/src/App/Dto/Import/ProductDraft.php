<?php

declare(strict_types=1);

namespace App\Dto\Import;

/**
 * Товар, прошедший нормализацию и готовый к записи. Лишние колонки «Доп. поле»
 * отброшены в нормализаторе, товары без обязательных полей сюда не доходят.
 */
final readonly class ProductDraft
{
    /**
     * @param array<string, string>      $attributes
     * @param list<ProductImageDraft>    $images
     */
    public function __construct(
        public string $externalCode,
        public string $name,
        public string $description,
        public float $price,
        public float $purchasePrice,
        public array $attributes,
        public array $images,
    ) {
    }
}
