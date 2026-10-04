<?php

declare(strict_types=1);

namespace App\Dto\Import;

/**
 * Изображение после попытки скачивания: url — из XLSX, path — локальный файл
 * либо null, если скачивание не удалось.
 */
final readonly class ProductImageDraft
{
    public function __construct(
        public string $url,
        public ?string $path,
    ) {
    }
}
