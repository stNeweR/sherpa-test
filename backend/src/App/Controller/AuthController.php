<?php

declare(strict_types=1);

namespace App\Controller;

use App\UseCase\Auth\LoginUseCase;
use Core\Http\JsonResponder;
use Core\Http\RequestPayload;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * HTTP-слой аутентификации: читает тело запроса и отдаёт результат use case.
 * Коды статуса и формат ошибок задаёт DomainErrorHandler.
 */
final readonly class AuthController
{
    public function __construct(private LoginUseCase $login)
    {
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = RequestPayload::fromRequest($request);

        return JsonResponder::json($response, $this->login->handle(
            $payload->string('email'),
            $payload->string('password'),
        ));
    }
}
