<?php

declare(strict_types=1);

namespace App\Controller;

use App\UseCase\Health\CheckHealthUseCase;
use Core\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final readonly class HealthController
{
    public function __construct(private CheckHealthUseCase $checkHealth)
    {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $status = $this->checkHealth->handle();

        return JsonResponder::json($response, $status, $status['status'] === 'ok' ? 200 : 503);
    }
}
