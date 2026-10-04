<?php

declare(strict_types=1);

namespace App\UseCase\Product;

use App\Dto\Api\FilterDto;
use App\Dto\Api\PageDto;
use App\Entity\Product;
use App\Repository\ProductRepository;

/**
 * Страница товаров: разбор query-параметров, выборка и проекция в summary.
 */
final readonly class ListProductsUseCase
{
    public function __construct(private ProductRepository $products)
    {
    }

    /** @param array<array-key, mixed> $queryParams */
    public function handle(array $queryParams): PageDto
    {
        $result = $this->products->findPage(FilterDto::fromQuery($queryParams));

        return new PageDto(
            items: array_map(
                static fn (Product $product): array => $product->toSummaryArray(),
                $result['items'],
            ),
            page: $result['page'],
            limit: $result['limit'],
            total: $result['total'],
            pages: $result['pages'],
        );
    }
}
