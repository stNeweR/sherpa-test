<?php

declare(strict_types=1);

namespace App\Dto\Import;

/**
 * Строка XLSX, снятая ридером: значения ещё не приведены к типам и не валидированы.
 */
final readonly class RawProductRow
{
    /**
     * @param array<string, string> $attributes
     * @param list<string>          $imageUrls
     */
    public function __construct(
        public int $rowNumber,
        public string $externalCode,
        public string $name,
        public string $description,
        public string $price,
        public string $purchasePrice,
        public array $attributes,
        public array $imageUrls,
    ) {
    }
}
