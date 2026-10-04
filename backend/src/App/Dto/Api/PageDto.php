<?php

declare(strict_types=1);

namespace App\Dto\Api;

use JsonSerializable;

final readonly class PageDto implements JsonSerializable
{
    /**
     * @param list<array<string, mixed>> $items
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $limit,
        public int $total,
        public int $pages,
    ) {
    }

    /** @return array{
     *     items: list<array<string, mixed>>,
     *     pagination: array{page: int, limit: int, total: int, pages: int},
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'items' => $this->items,
            'pagination' => [
                'page' => $this->page,
                'limit' => $this->limit,
                'total' => $this->total,
                'pages' => $this->pages,
            ],
        ];
    }
}
