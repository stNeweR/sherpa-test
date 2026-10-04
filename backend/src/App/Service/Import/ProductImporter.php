<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Dto\Import\ProductDraft;
use App\Entity\Product;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Пишет товар, атрибуты и изображения в одной транзакции. Повторный импорт
 * обновляет существующий товар по внешнему коду, а не создаёт дубликат.
 */
final readonly class ProductImporter
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProductRepository $products,
    ) {
    }

    /**
     * @return bool true — товар создан, false — обновлён
     */
    public function import(ProductDraft $draft): bool
    {
        $created = false;

        $this->entityManager->wrapInTransaction(
            function (EntityManagerInterface $entityManager) use ($draft, &$created): void {
                $product = $this->products->findByExternalCode($draft->externalCode);

                if ($product === null) {
                    $product = new Product(
                        externalCode: $draft->externalCode,
                        name: $draft->name,
                        description: $draft->description,
                        price: $draft->price,
                        purchasePrice: $draft->purchasePrice,
                    );
                    $created = true;
                } else {
                    $product->update(
                        name: $draft->name,
                        description: $draft->description,
                        price: $draft->price,
                        purchasePrice: $draft->purchasePrice,
                    );
                }

                $product->replaceAttributes($draft->attributes);
                $product->replaceImages(array_map(
                    static fn ($image): array => ['url' => $image->url, 'path' => $image->path],
                    $draft->images,
                ));

                $entityManager->persist($product);
                $entityManager->flush();
            },
        );

        return $created;
    }
}
