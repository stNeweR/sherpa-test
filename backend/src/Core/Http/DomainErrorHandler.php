<?php

declare(strict_types=1);

namespace Core\Http;

use App\Exception\DomainException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function sprintf;

use Throwable;

/**
 * Превращает доменную ошибку use case в ответ API. Подключается к
 * ErrorMiddleware как обработчик App\Exception\DomainException и всех
 * подклассов, поэтому контроллеры не содержат ни try/catch, ни кодов статуса.
 * Ошибки инфраструктуры (роутинг, middleware) остаются на стандартном
 * обработчике Slim.
 */
final readonly class DomainErrorHandler
{
    public function __construct(private ResponseFactoryInterface $responseFactory)
    {
    }

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        if (!$exception instanceof DomainException) {
            throw new \LogicException(sprintf(
                '%s обрабатывает только %s, передан %s',
                self::class,
                DomainException::class,
                $exception::class,
            ));
        }

        return JsonResponder::json(
            $this->responseFactory->createResponse(),
            $exception->errorBody(),
            $exception->httpStatus(),
            $exception->responseHeaders(),
        );
    }
}
