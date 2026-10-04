<?php

declare(strict_types=1);

namespace App\Dto\Api;

/**
 * Типизированные параметры выборки товаров — всё, что приходит в query string
 * у GET /api/products. Значения приводятся к типам один раз здесь, поэтому
 * сервис и репозиторий не работают с array<mixed>, а границы пагинации
 * не дублируются.
 */
final readonly class FilterDto
{
    public const DEFAULT_PAGE = 1;
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT = 100;

    public int $page;

    public int $limit;

    public ?string $search;

    public ?float $priceFrom;

    public ?float $priceTo;

    public function __construct(
        int $page = self::DEFAULT_PAGE,
        int $limit = self::DEFAULT_LIMIT,
        ?string $search = null,
        ?float $priceFrom = null,
        ?float $priceTo = null,
    ) {
        $this->page = max($page, self::DEFAULT_PAGE);
        $this->limit = min(max($limit, 1), self::MAX_LIMIT);
        $this->search = $search;
        $this->priceFrom = $priceFrom;
        $this->priceTo = $priceTo;
    }

    /**
     * Поддерживаются оба написания параметров: snake_case из документации
     * и camelCase из ранних версий фронтенда. Некорректные значения
     * отбрасываются, а не ломают выдачу: поиск без фильтров лучше, чем 500.
     *
     * На вход принимается сырой query bag без гарантии типов — PSR-7
     * отдаёт getQueryParams() как plain array. Выход уже полностью типизирован.
     *
     * @param array<array-key, mixed> $params
     */
    public static function fromQuery(array $params): self
    {
        return new self(
            page: self::toInt($params['page'] ?? null) ?? self::DEFAULT_PAGE,
            limit: self::toInt($params['limit'] ?? null) ?? self::DEFAULT_LIMIT,
            search: self::toNullableString($params['name'] ?? $params['search'] ?? null),
            priceFrom: self::toNullableFloat($params['price_from'] ?? $params['priceFrom'] ?? null),
            priceTo: self::toNullableFloat($params['price_to'] ?? $params['priceTo'] ?? null),
        );
    }

    /**
     * Есть ли что фильтровать, кроме пагинации.
     */
    public function hasFilters(): bool
    {
        return $this->search !== null || $this->priceFrom !== null || $this->priceTo !== null;
    }

    private static function toInt(mixed $value): ?int
    {
        if (!is_scalar($value) || !is_numeric((string) $value)) {
            return null;
        }

        return (int) $value;
    }

    private static function toNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function toNullableFloat(mixed $value): ?float
    {
        if (!is_scalar($value) || !is_numeric((string) $value)) {
            return null;
        }

        return (float) $value;
    }
}
