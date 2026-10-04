<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Exception\InternalErrorException;
use App\Exception\InvalidInputException;
use App\Exception\TooManyRequestsException;
use Core\Http\DomainErrorHandler;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Uri;

/**
 * DomainErrorHandler — единственное место, где доменная ошибка превращается
 * в ответ API. Проверяем статусы, тела и заголовки, чтобы контроллеры могли
 * остаться пустыми.
 */
final class DomainErrorHandlerTest extends TestCase
{
    private DomainErrorHandler $handler;

    private ServerRequestInterface $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new DomainErrorHandler(new ResponseFactory());
        $this->request = (new ServerRequestFactory())
            ->createServerRequest('POST', new Uri('http', 'localhost', null, '/api/imports'));
    }

    /**
     * @param callable(): \Throwable $makeException
     * @param array<string, string> $expectedHeaders
     */
    #[DataProvider('domainErrorProvider')]
    public function testMapsDomainErrorsToResponses(
        callable $makeException,
        int $expectedStatus,
        string $expectedBody,
        array $expectedHeaders,
    ): void {
        $response = ($this->handler)($this->request, $makeException(), false, false, false);

        $this->assertSame($expectedStatus, $response->getStatusCode());
        $this->assertSame($expectedBody, (string) $response->getBody());

        foreach ($expectedHeaders as $name => $value) {
            $this->assertSame($value, $response->getHeaderLine($name));
        }
    }

    /**
     * @return iterable<string, array{callable(): \Throwable, int, string, array<string, string>}>
     */
    public static function domainErrorProvider(): iterable
    {
        yield '400 без полей' => [
            static fn (): InvalidInputException => new InvalidInputException('Укажите email и пароль'),
            400,
            '{"error":"Укажите email и пароль"}',
            [],
        ];

        yield '429 с retry_after' => [
            static fn (): TooManyRequestsException => new TooManyRequestsException('Слишком много запросов', 42),
            429,
            '{"error":"Слишком много запросов","retry_after":42}',
            ['Retry-After' => '42', 'Content-Type' => 'application/json; charset=utf-8'],
        ];

        yield '500' => [
            static fn (): InternalErrorException => new InternalErrorException('Не удалось поставить файл в очередь'),
            500,
            '{"error":"Не удалось поставить файл в очередь"}',
            [],
        ];
    }

    public function testRejectsForeignExceptions(): void
    {
        $this->expectException(LogicException::class);

        ($this->handler)($this->request, new RuntimeException('boom'), false, false, false);
    }
}
