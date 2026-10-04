<?php

declare(strict_types=1);

namespace App\Controller;

use App\UseCase\Import\CreateImportJobUseCase;
use App\UseCase\Import\GetImportJobUseCase;
use Core\Http\JsonResponder;
use Core\Http\RequestPayload;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * HTTP-слой импорта. Единственное, что осталось в контроллере, — достать
 * загруженный файл и адрес клиента из PSR-7 запроса: это адаптация протокола,
 * а не логика.
 */
final readonly class ImportController
{
    public function __construct(
        private CreateImportJobUseCase $createImportJob,
        private GetImportJobUseCase $getImportJob,
    ) {
    }

    public function upload(Request $request, Response $response): Response
    {
        return JsonResponder::json(
            $response,
            $this->createImportJob->handle(self::uploadedFile($request), self::clientId($request)),
            202,
        );
    }

    /** @param array<array-key, mixed> $args */
    public function status(Request $request, Response $response, array $args = []): Response
    {
        return JsonResponder::json(
            $response,
            $this->getImportJob->handle(RequestPayload::fromArray($args)->string('job_id')),
        );
    }

    private static function uploadedFile(Request $request): ?UploadedFileInterface
    {
        $uploaded = $request->getUploadedFiles()['file'] ?? null;

        return $uploaded instanceof UploadedFileInterface ? $uploaded : null;
    }

    /**
     * nginx прокидывает в fastcgi_params значение $remote_addr, поэтому
     * за прокси REMOTE_ADDR — реальный адрес клиента, а не адрес контейнера.
     * За заглушкой без REMOTE_ADDR ключ лимита станет общим для всех клиентов.
     */
    private static function clientId(Request $request): string
    {
        $address = $request->getServerParams()['REMOTE_ADDR'] ?? '';

        return is_string($address) ? $address : '';
    }
}
