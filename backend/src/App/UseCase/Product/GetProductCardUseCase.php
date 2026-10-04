<?php

declare(strict_types=1);

namespace App\UseCase\Product;

use App\Exception\NotFoundException;
use App\Repository\ProductRepository;

/**
 * Карточка товара по внешнему коду.
 */
final readonly class GetProductCardUseCase
{
    public function __construct(private ProductRepository $products)
    {
    }

    /**
     * @return array{
     *     id: int,
     *     external_code: string,
     *     name: string,
     *     description: string,
     *     price: string,
     *     purchase_price: string,
     *     discount: string,
     *     created_at: string,
     *     updated_at: string,
     *     attributes: list<array{key: string, value: string}>,
     *     images: list<array{url: string, path: string|null}>,
     * }
     *
     * @throws NotFoundException
     */
    public function handle(string $externalCode): array
    {
        $product = $this->products->findCardByExternalCode($externalCode);

        if ($product === null) {
            throw new NotFoundException('Товар не найден');
        }

        return $product->toCardArray();
    }
}
