<?php

declare(strict_types=1);

namespace Core\Bootstrap;

use App\Exception\DomainException;
use Core\Container\ServiceFetcher;
use Core\Http\DomainErrorHandler;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;

/**
 * Сборка HTTP-приложения: middleware и подключение таблицы маршрутов.
 * Сами маршруты описаны в Routing.
 */
final class AppFactory
{
    /**
     * @return App<ContainerInterface|null>
     */
    public function create(ContainerInterface $container): App
    {
        $services = new ServiceFetcher($container);
        $app = SlimAppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();

        $errorMiddleware = $app->addErrorMiddleware(
            displayErrorDetails: false,
            logErrors: true,
            logErrorDetails: true,
        );

        // Доменные ошибки use case разбирает наш обработчик, всё остальное
        // (роутинг, middleware, неожиданные исключения) — стандартный Slim.
        $errorMiddleware->setErrorHandler(
            DomainException::class,
            $services->get(DomainErrorHandler::class),
            handleSubclasses: true,
        );

        (new Routing($services))->register($app);

        return $app;
    }
}
