<?php

declare(strict_types=1);

namespace Core\Bootstrap;

use App\Controller\AuthController;
use App\Controller\HealthController;
use App\Controller\ImportController;
use App\Controller\ProductController;
use App\Middleware\AuthMiddleware;
use Core\Container\ServiceFetcher;
use Psr\Container\ContainerInterface;
use Slim\App;

/**
 * Таблица маршрутов API. Отдельный файл, чтобы AppFactory занимался только
 * сборкой приложения, а список эндпоинтов и правила доступа читались
 * в одном месте. Пути проверяются тестом OpenApiSpecTest на соответствие
 * спецификации, поэтому новый маршрут без документации ломает сборку.
 */
final readonly class Routing
{
    public function __construct(private ServiceFetcher $services)
    {
    }

    /**
     * @param App<ContainerInterface|null> $app
     */
    public function register(App $app): void
    {
        $authMiddleware = $this->services->get(AuthMiddleware::class);

        $app->get('/api/health', $this->services->get(HealthController::class));
        $app->post('/api/auth/login', $this->services->get(AuthController::class)->login(...));

        $productController = $this->services->get(ProductController::class);
        $app->get('/api/products', $productController->list(...))->add($authMiddleware);
        $app->get('/api/products/{external_code}', $productController->card(...))->add($authMiddleware);

        $importController = $this->services->get(ImportController::class);
        $app->post('/api/imports', $importController->upload(...))->add($authMiddleware);
        $app->get('/api/imports/{job_id}', $importController->status(...))->add($authMiddleware);
    }
}
