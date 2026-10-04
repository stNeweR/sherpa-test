<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Dto\Import\RawProductRow;
use DateInterval;
use DateTimeInterface;
use Generator;
use OpenSpout\Common\Entity\Comment\TextRun;
use OpenSpout\Common\Exception\OpenSpoutException;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Снимает товары из XLSX. Карта колонок соответствует файлу-примеру:
 * 5 — наименование, 6 — внешний код, 9 — цена продажи, 11 — описание,
 * 12 — закупочная цена, 38 — «Доп. поле: Ссылки на фото».
 * Остальные колонки с префиксом «Доп. поле: » становятся атрибутами.
 */
final class XlsxRowReader
{
    private const COLUMN_NAME = 4;
    private const COLUMN_EXTERNAL_CODE = 5;
    private const COLUMN_PRICE = 8;
    private const COLUMN_DESCRIPTION = 10;
    private const COLUMN_PURCHASE_PRICE = 11;

    private const ATTRIBUTE_PREFIX = 'Доп. поле: ';

    public const PHOTO_LINKS_ATTRIBUTE = 'Ссылки на фото';

    /**
     * @return Generator<RawProductRow>
     *
     * @throws OpenSpoutException
     */
    public function read(string $filePath): Generator
    {
        $reader = new Reader();

        try {
            $reader->open($filePath);

            foreach ($reader->getSheetIterator() as $sheet) {
                $attributeColumns = null;
                $rowNumber = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $rowNumber++;
                    $cells = array_map(
                        /**
                         * @param array<TextRun>|bool|DateInterval|DateTimeInterface|float|int|string|null $value
                         */
                        static fn ($value): string => self::toText($value),
                        array_values($row->toArray()),
                    );

                    if ($attributeColumns === null) {
                        $attributeColumns = $this->resolveAttributeColumns($cells);

                        continue;
                    }

                    $rawRow = $this->toRawRow($rowNumber, $cells, $attributeColumns);

                    if ($rawRow !== null) {
                        yield $rawRow;
                    }
                }
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * @param list<string>        $cells
     * @param array<int, string>  $attributeColumns
     */
    private function toRawRow(int $rowNumber, array $cells, array $attributeColumns): ?RawProductRow
    {
        $externalCode = $cells[self::COLUMN_EXTERNAL_CODE] ?? '';
        $name = $cells[self::COLUMN_NAME] ?? '';

        if ($externalCode === '' && $name === '') {
            return null;
        }

        $attributes = [];
        $imageUrls = [];

        foreach ($attributeColumns as $index => $attributeName) {
            $value = $cells[$index] ?? '';

            if ($value === '') {
                continue;
            }

            if ($attributeName === self::PHOTO_LINKS_ATTRIBUTE) {
                $imageUrls = $this->splitLinks($value);

                continue;
            }

            $attributes[$attributeName] = $value;
        }

        return new RawProductRow(
            rowNumber: $rowNumber,
            externalCode: $externalCode,
            name: $name,
            description: $cells[self::COLUMN_DESCRIPTION] ?? '',
            price: $cells[self::COLUMN_PRICE] ?? '',
            purchasePrice: $cells[self::COLUMN_PURCHASE_PRICE] ?? '',
            attributes: $attributes,
            imageUrls: $imageUrls,
        );
    }

    /**
     * Значение ячейки XLSX: приводим к тексту, сохраняя даты в ISO-формате.
     *
     * @param array<TextRun>|bool|DateInterval|DateTimeInterface|float|int|string|null $value
     */
    private static function toText(array|bool|DateInterval|DateTimeInterface|float|int|string|null $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param list<string> $headerCells
     *
     * @return array<int, string>
     */
    private function resolveAttributeColumns(array $headerCells): array
    {
        $columns = [];

        foreach ($headerCells as $index => $header) {
            if (str_starts_with($header, self::ATTRIBUTE_PREFIX)) {
                $columns[$index] = mb_substr($header, mb_strlen(self::ATTRIBUTE_PREFIX));
            }
        }

        return $columns;
    }

    /** @return list<string> */
    private function splitLinks(string $value): array
    {
        $links = array_map(trim(...), explode(',', $value));

        return array_values(array_filter(
            $links,
            static fn (string $link): bool => $link !== '' && filter_var($link, FILTER_VALIDATE_URL) !== false,
        ));
    }
}
