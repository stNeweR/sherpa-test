<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Core\Bootstrap\ContainerFactory;
use Core\Container\ServiceFetcher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

abstract class DatabaseTestCase extends TestCase
{
    private const TABLES = 'product_images, product_attributes, import_jobs, products';

    protected ContainerInterface $container;
    protected EntityManagerInterface $entityManager;

    /**
     * Недоступная БД — это ошибка окружения, а не повод пропустить тест: иначе
     * прогон выглядит зелёным при нуле выполненных проверок.
     */
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->container = ContainerFactory::create(dirname(__DIR__, 2));
            $this->entityManager = (new ServiceFetcher($this->container))->get(EntityManagerInterface::class);
            $this->truncate();
        } catch (Throwable $e) {
            throw new RuntimeException(sprintf(
                'База данных недоступна: %s. Поднимите стек и примените миграции: make up && make migrate',
                $e->getMessage(),
            ), 0, $e);
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->truncate();
        } catch (Throwable) {
        }

        parent::tearDown();
    }

    protected function countProducts(): int
    {
        return $this->countRows('SELECT COUNT(*) FROM products');
    }

    protected function countRows(string $sql): int
    {
        $value = $this->entityManager->getConnection()->fetchOne($sql);

        return is_numeric($value) ? (int) $value : 0;
    }

    protected function scalar(string $sql): ?string
    {
        $value = $this->entityManager->getConnection()->fetchOne($sql);

        return is_scalar($value) ? (string) $value : null;
    }

    private function truncate(): void
    {
        $this->entityManager->getConnection()->executeStatement(sprintf(
            'TRUNCATE TABLE %s RESTART IDENTITY CASCADE',
            self::TABLES,
        ));
        $this->entityManager->clear();
    }
}
