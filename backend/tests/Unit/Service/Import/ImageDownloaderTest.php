<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Import;

use App\Dto\Import\ProductImageDraft;
use App\Service\Import\ImageDownloader;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class ImageDownloaderTest extends TestCase
{
    private string $mediaPath;
    private TestHandler $handler;

    protected function setUp(): void
    {
        $this->mediaPath = sys_get_temp_dir() . '/image-downloader-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->mediaPath)) {
            return;
        }

        foreach (glob($this->mediaPath . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->mediaPath);
    }

    public function testDownloadsImageAndReturnsPublicPath(): void
    {
        $downloader = $this->downloader([
            new GuzzleResponse(200, [], 'IMAGE-BYTES'),
        ]);

        $result = $downloader->downloadAll(
            [new ProductImageDraft('https://cdn.test/photo.jpg', null)],
            'EXT-1',
        );

        $this->assertSame([], $result['errors']);
        $this->assertCount(1, $result['images']);
        $this->assertSame('/media/EXT-1-' . substr(hash('sha256', 'https://cdn.test/photo.jpg'), 0, 12) . '.jpg', $result['images'][0]->path);
        $this->assertFileExists($this->mediaPath . '/EXT-1-' . substr(hash('sha256', 'https://cdn.test/photo.jpg'), 0, 12) . '.jpg');
    }

    public function testCreatesMediaDirectoryOnDemand(): void
    {
        $this->assertDirectoryDoesNotExist($this->mediaPath);

        $downloader = $this->downloader([new GuzzleResponse(200, [], 'BYTES')]);
        $downloader->downloadAll([new ProductImageDraft('https://cdn.test/a.jpg', null)], 'EXT-1');

        $this->assertDirectoryExists($this->mediaPath);
    }

    public function testRetriesOnNetworkFailureAndReportsError(): void
    {
        $downloader = $this->downloader([
            new ConnectException('refused', new Request('GET', 'https://cdn.test/a.jpg')),
            new ConnectException('refused', new Request('GET', 'https://cdn.test/a.jpg')),
        ]);

        $result = $downloader->downloadAll(
            [new ProductImageDraft('https://cdn.test/a.jpg', null)],
            'EXT-1',
        );

        $this->assertSame([], $result['images']);
        $this->assertArrayHasKey('https://cdn.test/a.jpg', $result['errors']);
        $this->assertCount(2, $this->handler->getRecords());
    }

    public function testKeepsGoingAfterBrokenUrl(): void
    {
        $downloader = $this->downloader([
            new ConnectException('refused', new Request('GET', 'https://cdn.test/broken.jpg')),
            new ConnectException('refused', new Request('GET', 'https://cdn.test/broken.jpg')),
            new GuzzleResponse(200, [], 'BYTES'),
        ]);

        $result = $downloader->downloadAll([
            new ProductImageDraft('https://cdn.test/broken.jpg', null),
            new ProductImageDraft('https://cdn.test/good.jpg', null),
        ], 'EXT-1');

        $this->assertCount(1, $result['images']);
        $this->assertCount(1, $result['errors']);
    }

    public function testDerivesExtensionFromUrlPath(): void
    {
        $downloader = $this->downloader([
            new GuzzleResponse(200, [], 'BYTES'),
            new GuzzleResponse(200, [], 'BYTES'),
            new GuzzleResponse(200, [], 'BYTES'),
        ]);

        $result = $downloader->downloadAll([
            new ProductImageDraft('https://cdn.test/a.PNG', null),
            new ProductImageDraft('https://cdn.test/b.webp?token=1', null),
            new ProductImageDraft('https://cdn.test/c', null),
        ], 'EXT-1');

        $extensions = array_map(
            static fn (ProductImageDraft $image): string => pathinfo((string) $image->path, PATHINFO_EXTENSION),
            $result['images'],
        );

        $this->assertSame(['png', 'webp', 'jpg'], $extensions);
    }

    /**
     * @param list<ResponseInterface|Throwable> $queue
     */
    private function downloader(array $queue): ImageDownloader
    {
        $this->handler = new TestHandler();

        return new ImageDownloader(
            new Client(['handler' => HandlerStack::create(new MockHandler($queue))]),
            $this->mediaPath,
            '/media',
            new Logger('test', [$this->handler]),
            timeoutSeconds: 5,
            maxAttempts: 2,
        );
    }

}
