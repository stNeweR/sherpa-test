<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Dto\Import\ProductImageDraft;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Скачивает изображения по ссылкам из XLSX в локальный каталог. Неудача не
 * прерывает импорт: товар сохраняется с path = null, ошибка уходит в отчёт.
 */
final class ImageDownloader
{
    public const ERROR_IMAGE_DOWNLOAD_FAILED = 'image_download_failed';

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly string $mediaPath,
        private readonly string $mediaPublicUrl,
        private readonly LoggerInterface $logger,
        private readonly int $timeoutSeconds,
        private readonly int $maxAttempts,
    ) {
    }

    /**
     * @param list<ProductImageDraft> $images
     *
     * @return array{images: list<ProductImageDraft>, errors: array<string, string>}
     */
    public function downloadAll(array $images, string $externalCode): array
    {
        $downloaded = [];
        $errors = [];

        foreach ($images as $image) {
            $relativePath = $this->download($image->url, $externalCode);

            if ($relativePath === null) {
                $errors[$image->url] = 'Не удалось скачать изображение';

                continue;
            }

            $downloaded[] = new ProductImageDraft(url: $image->url, path: $relativePath);
        }

        return ['images' => $downloaded, 'errors' => $errors];
    }

    private function download(string $url, string $externalCode): ?string
    {
        if (!$this->ensureMediaDirectory()) {
            return null;
        }

        $fileName = $this->buildFileName($url, $externalCode);

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                $response = $this->httpClient->request('GET', $url, [
                    'timeout' => $this->timeoutSeconds,
                    'connect_timeout' => $this->timeoutSeconds,
                    'sink' => $this->mediaPath . '/' . $fileName,
                    'headers' => ['User-Agent' => 'sherpa-products-import/1.0'],
                ]);

                if ($response->getStatusCode() >= 400) {
                    throw new \RuntimeException('HTTP ' . $response->getStatusCode());
                }

                return $this->mediaPublicUrl . '/' . $fileName;
            } catch (GuzzleException|\RuntimeException $e) {
                $this->logger->warning('Не удалось скачать изображение', [
                    'url' => $url,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    private function ensureMediaDirectory(): bool
    {
        if (is_dir($this->mediaPath)) {
            return true;
        }

        if (!mkdir($this->mediaPath, 0775, true) && !is_dir($this->mediaPath)) {
            $this->logger->error('Не удалось создать каталог для изображений', [
                'path' => $this->mediaPath,
            ]);

            return false;
        }

        return true;
    }

    private function buildFileName(string $url, string $externalCode): string
    {
        $extension = $this->detectExtension($url);
        $safeCode = preg_replace('/[^A-Za-z0-9_-]+/', '-', $externalCode) ?? 'product';
        $hash = substr(hash('sha256', $url), 0, 12);

        return trim($safeCode, '-') . '-' . $hash . '.' . $extension;
    }

    private function detectExtension(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $extension = is_string($path) ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';

        return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) ? $extension : 'jpg';
    }
}
