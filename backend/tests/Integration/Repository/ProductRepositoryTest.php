<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Dto\Api\FilterDto;
use App\Entity\Product;
use App\Repository\ProductRepository;
use App\Tests\Integration\DatabaseTestCase;
use Core\Container\ServiceFetcher;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\AbstractLogger;

#[Group('integration')]
final class ProductRepositoryTest extends DatabaseTestCase
{
    private ProductRepository $products;

    protected function setUp(): void
    {
        parent::setUp();

        $this->products = (new ServiceFetcher($this->container))->get(ProductRepository::class);
    }

    public function testCreateReadUpdateDelete(): void
    {
        $product = new Product('EXT-1', 'Бермуды', 'Описание', 1320.0, 880.0);
        $product->replaceAttributes(['Цвет' => 'Grigio/Verde']);
        $product->replaceImages([['url' => 'https://cdn.test/a.jpg', 'path' => '/media/a.jpg']]);

        $this->entityManager->persist($product);
        $this->entityManager->flush();
        $id = $product->getId();

        $this->entityManager->clear();

        $stored = $this->products->findByExternalCode('EXT-1');
        $this->assertNotNull($stored);
        $this->assertSame($id, $stored->getId());
        $this->assertSame('Бермуды', $stored->getName());
        $this->assertSame('33.33', $stored->getDiscount());
        $this->assertCount(1, $stored->getAttributes());
        $this->assertCount(1, $stored->getImages());

        $stored->update('Новое имя', 'Новое описание', 1000.0, 500.0);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $updated = $this->products->findByExternalCode('EXT-1');
        $this->assertNotNull($updated);
        $this->assertSame('Новое имя', $updated->getName());
        $this->assertSame('50.00', $updated->getDiscount());

        $this->entityManager->remove($updated);
        $this->entityManager->flush();

        $this->assertNull($this->products->findByExternalCode('EXT-1'));
        $this->assertSame(0, $this->countProducts());
    }

    public function testDeletingProductCascadesToAttributesAndImages(): void
    {
        $product = new Product('EXT-1', 'Товар');
        $product->replaceAttributes(['Цвет' => 'Red']);
        $product->replaceImages([['url' => 'https://cdn.test/a.jpg', 'path' => null]]);

        $this->entityManager->persist($product);
        $this->entityManager->flush();
        $this->entityManager->remove($product);
        $this->entityManager->flush();

        $this->assertSame(0, $this->countRows('SELECT COUNT(*) FROM product_attributes'));
        $this->assertSame(0, $this->countRows('SELECT COUNT(*) FROM product_images'));
    }

    public function testPaginationReturnsRequestedSlice(): void
    {
        $this->seedProducts(25);

        $page = $this->products->findPage(new FilterDto(page: 2, limit: 10));

        $this->assertCount(10, $page['items']);
        $this->assertSame(2, $page['page']);
        $this->assertSame(25, $page['total']);
        $this->assertSame(3, $page['pages']);
    }

    public function testPageIsNotEatenByCollectionJoins(): void
    {
        $this->seedProducts(6, attributes: 3, images: 4);

        $page = $this->products->findPage(new FilterDto(limit: 4));

        $this->assertSame(4, $page['limit']);
        $this->assertCount(4, $page['items']);
        $this->assertCount(3, $page['items'][0]->getAttributes());
        $this->assertCount(4, $page['items'][0]->getImages());
    }

    public function testSearchIsCaseInsensitive(): void
    {
        $this->seedProducts(3);

        $page = $this->products->findPage(new FilterDto(limit: 10, search: 'БЕРМУДЫ 2'));

        $this->assertSame(1, $page['total']);
        $this->assertSame('Бермуды 2', $page['items'][0]->getName());
    }

    public function testPriceRangeFilterAppliesToItemsAndTotal(): void
    {
        $this->seedProducts(5);

        $page = $this->products->findPage(new FilterDto(limit: 10, priceFrom: 200.0, priceTo: 400.0));

        $this->assertSame(3, $page['total']);
        $this->assertCount(3, $page['items']);

        foreach ($page['items'] as $item) {
            $this->assertGreaterThanOrEqual(200.0, (float) $item->getPrice());
            $this->assertLessThanOrEqual(400.0, (float) $item->getPrice());
        }
    }

    public function testWildcardsInSearchAreNotInterpreted(): void
    {
        $this->seedProducts(3);

        $page = $this->products->findPage(new FilterDto(limit: 10, search: '%'));

        $this->assertSame(0, $page['total']);
    }

    public function testListQueryLoadsRelationsWithoutNPlusOne(): void
    {
        $this->seedProducts(6);

        $logger = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $queries = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if (is_string($message) && str_contains($message, 'SELECT')) {
                    $this->queries[] = $message;
                }
            }
        };

        $configuration = $this->entityManager->getConnection()->getConfiguration();
        $configuration->setMiddlewares([new LoggingMiddleware($logger)]);
        $this->entityManager->getConnection()->close();

        $page = $this->products->findPage(new FilterDto(limit: 10));
        $this->assertCount(6, $page['items']);
        $this->assertCount(1, $page['items'][0]->getAttributes());
        $this->assertCount(1, $page['items'][0]->getImages());

        $configuration->setMiddlewares([]);

        $this->assertLessThanOrEqual(
            2,
            count($logger->queries),
            sprintf('Ожидалось 2 SELECT (товары + атрибуты/изображения), выполнено: %s', print_r($logger->queries, true)),
        );
    }

    private function seedProducts(int $count, int $attributes = 1, int $images = 1): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $product = new Product(sprintf('EXT-%02d', $i), sprintf('Бермуды %d', $i), '', (float) (100 * $i), 50.0);
            $product->replaceAttributes(array_combine(
                array_map(static fn (int $n): string => 'Attr ' . $n, range(1, $attributes)),
                array_fill(0, $attributes, 'value'),
            ));
            $product->replaceImages(array_map(
                static fn (int $n): array => ['url' => sprintf('https://cdn.test/%d-%d.jpg', $i, $n), 'path' => null],
                range(1, $images),
            ));
            $this->entityManager->persist($product);
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
    }
}
