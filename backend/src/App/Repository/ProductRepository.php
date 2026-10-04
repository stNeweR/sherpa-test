<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\Api\FilterDto;
use App\Entity\Product;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;

/**
 * @extends EntityRepository<Product>
 */
class ProductRepository extends EntityRepository
{
    public function findByExternalCode(string $externalCode): ?Product
    {
        return $this->findOneBy(['externalCode' => $externalCode]);
    }

    public function findCardByExternalCode(string $externalCode): ?Product
    {
        /** @var Product|null $product */
        $product = $this->createEagerQueryBuilder()
            ->andWhere('p.externalCode = :externalCode')
            ->setParameter('externalCode', $externalCode)
            ->getQuery()
            ->getOneOrNullResult();

        return $product;
    }

    /**
     * @return array{items: list<Product>, total: int, page: int, limit: int, pages: int}
     */
    public function findPage(FilterDto $filter): array
    {
        $page = $filter->page;
        $limit = $filter->limit;

        $items = $this->findPageItems($filter);

        $countQb = $this->createQueryBuilder('p');
        $this->applyFilter($countQb, $filter);
        $total = (int) $countQb
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => (int) ceil($total / $limit),
        ];
    }

    /**
     * @return list<string>
     */
    public function findExternalCodes(): array
    {
        /** @var list<string> $codes */
        $codes = $this->createQueryBuilder('p')
            ->select('p.externalCode')
            ->getQuery()
            ->getScalarResult();

        return $codes;
    }

    /**
     * @return list<Product>
     */
    private function findPageItems(FilterDto $filter): array
    {
        $idsQb = $this->createQueryBuilder('p')
            ->select('p.id')
            ->orderBy('p.id', 'DESC')
            ->setFirstResult(($filter->page - 1) * $filter->limit)
            ->setMaxResults($filter->limit);
        $this->applyFilter($idsQb, $filter);

        /** @var list<int> $ids */
        $ids = $idsQb->getQuery()->getSingleColumnResult();

        if ($ids === []) {
            return [];
        }

        // LIMIT по запросу с join коллекций считает строки, а не товары:
        // товар с 17 атрибутами и 4 фото раздувает страницу в 68 строк и
        // съедает лимит. Поэтому сначала режется страница идентификаторов
        // без join, затем они грузятся вместе со связями — два SELECT,
        // без N+1.
        /** @var list<Product> $items */
        $items = $this->createEagerQueryBuilder()
            ->andWhere('p.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('p.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $items;
    }

    private function createEagerQueryBuilder(): QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.attributes', 'a')
            ->addSelect('a')
            ->leftJoin('p.images', 'i')
            ->addSelect('i');

        return $qb;
    }

    private function applyFilter(QueryBuilder $qb, FilterDto $filter): void
    {
        if (!$filter->hasFilters()) {
            return;
        }

        $conditions = [];

        if ($filter->search !== null) {
            $conditions[] = 'LOWER(p.name) LIKE :search';
            $qb->setParameter('search', '%' . self::escapeLike(mb_strtolower($filter->search)) . '%');
        }

        if ($filter->priceFrom !== null) {
            $conditions[] = $qb->expr()->gte('p.price', ':priceFrom');
            $qb->setParameter('priceFrom', number_format($filter->priceFrom, 2, '.', ''));
        }

        if ($filter->priceTo !== null) {
            $conditions[] = $qb->expr()->lte('p.price', ':priceTo');
            $qb->setParameter('priceTo', number_format($filter->priceTo, 2, '.', ''));
        }

        if ($conditions !== []) {
            $qb->andWhere(...$conditions);
        }
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value,
        );
    }
}
