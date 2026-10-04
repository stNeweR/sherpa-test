<?php

declare(strict_types=1);

namespace Core\Http;

use JsonSerializable;
use Psr\Http\Message\ResponseInterface;

/**
 * Единственный способ сериализовать ответ API: контроллеры не собирают JSON
 * вручную, поэтому формат тела и заголовок Content-Type не расходятся
 * между эндпоинтами.
 */
final class JsonResponder
{
    /**
     * @param array<string, mixed>|JsonSerializable $payload
     * @param array<string, string> $headers
     */
    public static function json(
        ResponseInterface $response,
        array|JsonSerializable $payload,
        int $status = 200,
        array $headers = [],
    ): ResponseInterface {
        $response->getBody()->write(json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));

        $response = $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($status);

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
