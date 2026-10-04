<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Dto\Import\ImportErrorDto;
use App\Dto\Import\ProductDraft;
use App\Dto\Import\ProductImageDraft;
use App\Dto\Import\RawProductRow;

/**
 * Приводит строку XLSX к типам и проверяет обязательные поля. Битая строка
 * не прерывает импорт — возвращается ошибка, товар пропускается.
 */
final class RowNormalizer
{
    public const ERROR_MISSING_EXTERNAL_CODE = 'missing_external_code';
    public const ERROR_MISSING_NAME = 'missing_name';
    public const ERROR_INVALID_PRICE = 'invalid_price';
    public const ERROR_INVALID_PURCHASE_PRICE = 'invalid_purchase_price';

    public function normalize(RawProductRow $row): ProductDraft|ImportErrorDto
    {
        if ($row->externalCode === '') {
            return new ImportErrorDto(
                rowNumber: $row->rowNumber,
                code: self::ERROR_MISSING_EXTERNAL_CODE,
                message: 'Не заполнен внешний код',
            );
        }

        if ($row->name === '') {
            return new ImportErrorDto(
                rowNumber: $row->rowNumber,
                code: self::ERROR_MISSING_NAME,
                message: 'Не заполнено наименование',
                context: ['external_code' => $row->externalCode],
            );
        }

        if (!$this->isParsableAmount($row->price)) {
            return new ImportErrorDto(
                rowNumber: $row->rowNumber,
                code: self::ERROR_INVALID_PRICE,
                message: 'Цена продажи не является числом',
                context: ['external_code' => $row->externalCode, 'value' => $row->price],
            );
        }

        if ($row->purchasePrice !== '' && !$this->isParsableAmount($row->purchasePrice)) {
            return new ImportErrorDto(
                rowNumber: $row->rowNumber,
                code: self::ERROR_INVALID_PURCHASE_PRICE,
                message: 'Закупочная цена не является числом',
                context: ['external_code' => $row->externalCode, 'value' => $row->purchasePrice],
            );
        }

        return new ProductDraft(
            externalCode: $row->externalCode,
            name: $row->name,
            description: $row->description,
            price: $this->toAmount($row->price),
            purchasePrice: $this->toAmount($row->purchasePrice),
            attributes: $this->withoutPhotoLinks($row->attributes),
            images: array_map(
                static fn (string $url): ProductImageDraft => new ProductImageDraft(url: $url, path: null),
                $row->imageUrls,
            ),
        );
    }

    /**
     * @param array<string, string> $attributes
     *
     * @return array<string, string>
     */
    private function withoutPhotoLinks(array $attributes): array
    {
        unset($attributes[XlsxRowReader::PHOTO_LINKS_ATTRIBUTE]);

        return $attributes;
    }

    private function isParsableAmount(string $value): bool
    {
        return $value === '' || is_numeric($this->normalizeDecimalSeparator($value));
    }

    private function toAmount(string $value): float
    {
        if ($value === '') {
            return 0.0;
        }

        return (float) $this->normalizeDecimalSeparator($value);
    }

    private function normalizeDecimalSeparator(string $value): string
    {
        return str_replace([' ', "\u{00A0}"], '', str_replace(',', '.', $value));
    }
}
