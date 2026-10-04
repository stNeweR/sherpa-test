<?php

declare(strict_types=1);

namespace App\Middleware;

use App\UseCase\Auth\AuthenticateUseCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpUnauthorizedException;

/**
 * Пускает дальше только запросы с валидным JWT. Роль проверяется в маршруте:
 * требуется администратор для импорта.
 */
final readonly class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private AuthenticateUseCase $authenticate)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $claims = $this->authenticate->handle($request->getHeaderLine('Authorization'));

        if ($claims === null) {
            throw new HttpUnauthorizedException($request, 'Требуется авторизация');
        }

        return $handler->handle($request->withAttribute('auth', $claims));
    }
}
