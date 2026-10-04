<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Auth\JwtService;
use Core\Bootstrap\AppFactory;
use DI\ContainerBuilder;
use DI\Definition\Helper\FactoryDefinitionHelper;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Stream;
use Slim\Psr7\Uri;

/**
 * Проверяет HTTP-слой без БД: парсинг JSON, маршрутизацию и AuthMiddleware.
 */
final class AuthRouteTest extends TestCase
{
    private const SECRET = 'test-secret-key-for-route-tests-32b';

    /** @var App<ContainerInterface|null>|null */
    private ?App $app = null;

    private ?User $user = null;

    protected function setUp(): void
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $jwt = new JwtService(self::SECRET, 'HS256', 3600);
        $users = $this->repositoryStub();

        /** @var array<string, mixed> $definitions */
        $definitions = require dirname(__DIR__, 3) . '/config/container.php';

        $builder->addDefinitions($definitions);
        $builder->addDefinitions([
            JwtService::class => new FactoryDefinitionHelper(static fn (): JwtService => $jwt),
            UserRepository::class => new FactoryDefinitionHelper(static fn (): UserRepository => $users),
        ]);

        $container = $builder->build();

        $this->app = (new AppFactory())->create($container);
    }

    public function testHealthEndpointIsPublic(): void
    {
        $response = $this->request('GET', '/api/health');

        $this->assertContains($response->getStatusCode(), [200, 503], 'health не должен требовать токен');
    }

    public function testLoginReturns401ForWrongCredentials(): void
    {
        $response = $this->request('POST', '/api/auth/login', ['email' => 'admin@example.com', 'password' => 'wrong']);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertArrayNotHasKey('access_token', $this->json($response));
        $this->assertSame('Неверный email или пароль', $this->json($response)['error']);
    }

    public function testLoginIssuesTokenForValidCredentials(): void
    {
        $this->user = $this->user('admin@example.com', 'secret');

        $response = $this->request('POST', '/api/auth/login', ['email' => 'ADMIN@example.com', 'password' => 'secret']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Bearer', $this->json($response)['token_type']);
        $this->assertNotEmpty($this->json($response)['access_token']);
    }

    public function testLoginWithoutBodyIsRejected(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', new Uri('http', 'localhost', null, '/api/auth/login'))
            ->withHeader('Content-Type', 'application/json');

        $response = $this->app?->handle($request);

        $this->assertNotNull($response);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Укажите email и пароль', $this->json($response)['error']);
    }

    public function testProtectedRoutesRequireToken(): void
    {
        $this->assertSame(401, $this->request('GET', '/api/products')->getStatusCode());
        $this->assertSame(401, $this->request('POST', '/api/imports')->getStatusCode());
        $this->assertSame(401, $this->request('GET', '/api/imports/job-1')->getStatusCode());
    }

    public function testProtectedRouteRejectsForeignToken(): void
    {
        $foreign = new JwtService('another-secret-key-32-bytes-long', 'HS256', 3600);
        $token = $foreign->issue($this->user('admin@example.com', 'secret'));

        $this->assertSame(401, $this->request('GET', '/api/products', [], 'Bearer ' . $token)->getStatusCode());
    }

    private function repositoryStub(): UserRepository
    {
        $user = &$this->user;

        return new class ($user) extends UserRepository {
            /** @param User|null $user */
            public function __construct(private mixed &$user)
            {
            }

            public function findByEmail(string $email): ?User
            {
                return $this->user !== null && $this->user->getEmail() === mb_strtolower(trim($email))
                    ? $this->user
                    : null;
            }
        };
    }

    private function user(string $email, string $password): User
    {
        return new User($email, password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $method, string $path, array $body = [], ?string $authorization = null): ResponseInterface
    {
        $json = $body === [] ? '' : (string) json_encode($body, JSON_THROW_ON_ERROR);
        $resource = fopen('php://temp', 'wb+');
        $this->assertIsResource($resource);

        $stream = new Stream($resource);
        $stream->write($json);

        $request = (new ServerRequestFactory())
            ->createServerRequest($method, new Uri('http', 'localhost', null, $path))
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);

        if ($authorization !== null) {
            $request = $request->withHeader('Authorization', $authorization);
        }

        if ($body !== []) {
            $request = $request->withParsedBody($body);
        }

        $response = $this->app?->handle($request);

        $this->assertNotNull($response);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($body);

        /** @var array<string, mixed> $body */
        return $body;
    }
}
