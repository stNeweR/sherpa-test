<?php

declare(strict_types=1);

namespace App\Controller;

use App\UseCase\Product\GetProductCardUseCase;
use App\UseCase\Product\ListProductsUseCase;
use Core\Http\JsonResponder;
use Core\Http\RequestPayload;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * HTTP-слой каталога: query-параметры и аргумент маршрута уходят в use case,
 * наружу отдаётся только сериализованный результат.
 */
final readonly class ProductController
{
    public function __construct(
        private ListProductsUseCase $listProducts,
        private GetProductCardUseCase $getProductCard,
    ) {
    }

    public function list(Request $request, Response $response): Response
    {
        return JsonResponder::json($response, $this->listProducts->handle($request->getQueryParams()));
    }

    /** @param array<array-key, mixed> $args */
    public function card(Request $request, Response $response, array $args = []): Response
    {
        return JsonResponder::json(
            $response,
            $this->getProductCard->handle(RequestPayload::fromArray($args)->string('external_code')),
        );
    }
}
