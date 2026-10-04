<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\Api\FilterDto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FilterDto владеет разбором query string: приводит типы, отбрасывает мусор
 * и зажимает пагинацию. Репозиторий и сервис должны получать уже готовые
 * значения, поэтому проверяем именно границы приведения.
 */
final class FilterDtoTest extends TestCase
{
    public function testDefaultsWithoutQueryParams(): void
    {
        $filter = FilterDto::fromQuery([]);

        $this->assertSame(FilterDto::DEFAULT_PAGE, $filter->page);
        $this->assertSame(FilterDto::DEFAULT_LIMIT, $filter->limit);
        $this->assertNull($filter->search);
        $this->assertNull($filter->priceFrom);
        $this->assertNull($filter->priceTo);
        $this->assertFalse($filter->hasFilters());
    }

    public function testReadsSnakeCaseParams(): void
    {
        $filter = FilterDto::fromQuery([
            'page' => '3',
            'limit' => '50',
            'name' => '  Болт  ',
            'price_from' => '100.50',
            'price_to' => '500',
        ]);

        $this->assertSame(3, $filter->page);
        $this->assertSame(50, $filter->limit);
        $this->assertSame('Болт', $filter->search);
        $this->assertSame(100.5, $filter->priceFrom);
        $this->assertSame(500.0, $filter->priceTo);
        $this->assertTrue($filter->hasFilters());
    }

    public function testKeepsCamelCaseParamsAsFallback(): void
    {
        $filter = FilterDto::fromQuery(['search' => 'гайка', 'priceFrom' => '10', 'priceTo' => '20']);

        $this->assertSame('гайка', $filter->search);
        $this->assertSame(10.0, $filter->priceFrom);
        $this->assertSame(20.0, $filter->priceTo);
    }

    #[DataProvider('garbageParamProvider')]
    public function testGarbageFallsBackToDefaults(string $key, mixed $value): void
    {
        $filter = FilterDto::fromQuery([$key => $value]);

        $this->assertSame(FilterDto::DEFAULT_PAGE, $filter->page);
        $this->assertSame(FilterDto::DEFAULT_LIMIT, $filter->limit);
        $this->assertFalse($filter->hasFilters());
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function garbageParamProvider(): iterable
    {
        yield 'page не число' => ['page', 'abc'];
        yield 'page массив' => ['page', ['1']];
        yield 'limit пустая строка' => ['limit', ''];
        yield 'name из массива' => ['name', [' Bolts ']];
        yield 'price_from мусор' => ['price_from', 'от 100'];
        yield 'price_to null' => ['price_to', null];
    }

    public function testPaginationIsClamped(): void
    {
        $this->assertSame(FilterDto::DEFAULT_PAGE, (new FilterDto(page: 0))->page);
        $this->assertSame(FilterDto::DEFAULT_PAGE, (new FilterDto(page: -5))->page);
        $this->assertSame(1, (new FilterDto(limit: 0))->limit);
        $this->assertSame(FilterDto::MAX_LIMIT, (new FilterDto(limit: 1000))->limit);
    }
}
