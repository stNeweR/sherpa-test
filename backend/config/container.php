<?php

declare(strict_types=1);

use Core\Bootstrap\Env;
use App\Entity\ImportJob;
use App\Entity\User;
use App\Entity\Product;
use Core\Doctrine\EntityManagerFactory;
use Core\Messenger\MessengerFactory;
use Core\RabbitMq\AmqpHealthChecker;
use App\Repository\ImportJobRepository;
use App\Repository\ProductRepository;
use App\Repository\UserRepository;
use App\Service\Import\ImageDownloader;
use App\Service\Import\ImportJobHandler;
use App\Service\Auth\JwtService;
use App\UseCase\Import\CreateImportJobUseCase;
use App\Middleware\AuthMiddleware;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactory;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Slim\Psr7\Factory\ResponseFactory;

use function DI\autowire;
use function DI\factory;

$projectDir = dirname(__DIR__);

$csvEnv = static function (string $key, string $default): array {
    return array_values(array_filter(array_map('trim', explode(',', Env::get($key, $default)))));
};

$transportDsn = Env::get('MESSENGER_TRANSPORT_DSN', 'amqp://app:app_secret@rabbitmq:5672/%2f/imports');
$failureTransportDsn = Env::get(
    'MESSENGER_FAILED_TRANSPORT_DSN',
    'amqp://app:app_secret@rabbitmq:5672/%2f/failed',
);

return [
    ResponseFactoryInterface::class => factory(static fn (): ResponseFactoryInterface => new ResponseFactory()),

    EntityManagerFactory::class => autowire()
        ->constructorParameter('entityPath', $projectDir . '/src/App/Entity')
        ->constructorParameter('devMode', Env::bool('APP_DEBUG', false))
        ->constructorParameter('cacheDir', $projectDir . '/var/cache'),

    EntityManagerInterface::class => static fn (EntityManagerFactory $factory): EntityManagerInterface => $factory->create(
        Env::get('DATABASE_URL', 'pgsql://app:app_secret@db:5432/app'),
        Env::get('DB_SERVER_VERSION', '17'),
    ),

    AmqpHealthChecker::class => autowire()
        ->constructorParameter('transportDsn', $transportDsn),

    ProductRepository::class => static function (EntityManagerInterface $entityManager): ProductRepository {
        $repository = $entityManager->getRepository(Product::class);

        if (!$repository instanceof ProductRepository) {
            throw new \RuntimeException(sprintf(
                'Expected %s, got %s',
                ProductRepository::class,
                $repository::class,
            ));
        }

        return $repository;
    },

    LoggerInterface::class => static fn (): LoggerInterface => new Logger('app', [
        new StreamHandler('php://stderr', Logger::DEBUG),
    ]),

    MessengerFactory::class => autowire()
        ->constructorParameter('transportDsn', $transportDsn)
        ->constructorParameter('failureTransportDsn', $failureTransportDsn)
        ->constructorParameter('retryMaxAttempts', Env::int('MESSENGER_RETRY_MAX_ATTEMPTS', 3))
        ->constructorParameter('retryDelayMs', Env::int('MESSENGER_RETRY_DELAY_MS', 1000)),

    SerializerInterface::class => factory(static fn (MessengerFactory $factory): SerializerInterface => $factory->createSerializer()),

    TransportFactory::class => factory(static fn (MessengerFactory $factory): TransportFactory => $factory->createTransportFactory()),

    TransportInterface::class => factory(static fn (MessengerFactory $factory): TransportInterface => $factory->createDefaultTransport()),

    'messenger.transport.failed' => factory(
        static fn (MessengerFactory $factory): TransportInterface => $factory->createFailureTransport(),
    ),

    'messenger.transport_locator' => factory(
        static fn (ContainerInterface $container): ContainerInterface
            => $container->get(MessengerFactory::class)->createTransportLocator($container),
    ),

    'messenger.senders_locator' => factory(
        static fn (ContainerInterface $container): SendersLocator
            => $container->get(MessengerFactory::class)->createSendersLocator(
                $container->get('messenger.transport_locator'),
            ),
    ),

    MessageBus::class => factory(
        static fn (ContainerInterface $container): MessageBus
            => $container->get(MessengerFactory::class)->createBus(
                $container->get('messenger.transport_locator'),
                $container->get('messenger.senders_locator'),
                $container->get(ImportJobHandler::class),
            ),
    ),

    RoutableMessageBus::class => factory(
        static fn (ContainerInterface $container): RoutableMessageBus
            => $container->get(MessengerFactory::class)->createRoutableMessageBus(
                $container->get(MessageBus::class),
            ),
    ),

    MessageBusInterface::class => static fn (ContainerInterface $container): MessageBus
        => $container->get(MessageBus::class),

    ImportJobRepository::class => static function (EntityManagerInterface $entityManager): ImportJobRepository {
        $repository = $entityManager->getRepository(ImportJob::class);

        if (!$repository instanceof ImportJobRepository) {
            throw new \RuntimeException(sprintf(
                'Expected %s, got %s',
                ImportJobRepository::class,
                $repository::class,
            ));
        }

        return $repository;
    },

    CreateImportJobUseCase::class => autowire()
        ->constructorParameter('storagePath', Env::get('IMPORT_STORAGE_PATH', $projectDir . '/storage/imports'))
        ->constructorParameter('maxFileSize', Env::int('IMPORT_MAX_FILE_SIZE', 20971520))
        ->constructorParameter('allowedExtensions', $csvEnv('IMPORT_ALLOWED_EXTENSIONS', 'xlsx,xlsm'))
        ->constructorParameter('allowedMimeTypes', $csvEnv(
            'IMPORT_ALLOWED_MIME',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,'
            . 'application/vnd.ms-excel.sheet.macroEnabled.12,application/zip,application/octet-stream',
        )),

    ImageDownloader::class => autowire()
        ->constructorParameter('mediaPath', rtrim(Env::get('MEDIA_PATH', $projectDir . '/storage/media'), '/'))
        ->constructorParameter('mediaPublicUrl', rtrim(Env::get('MEDIA_PUBLIC_URL', '/media'), '/'))
        ->constructorParameter('timeoutSeconds', Env::int('IMPORT_IMAGE_TIMEOUT', 30))
        ->constructorParameter('maxAttempts', Env::int('IMPORT_IMAGE_RETRIES', 2)),

    ClientInterface::class => static fn (): ClientInterface => new Client([
        RequestOptions::CONNECT_TIMEOUT => 10,
        RequestOptions::TIMEOUT => 30,
    ]),

    UserRepository::class => static function (EntityManagerInterface $entityManager): UserRepository {
        $repository = $entityManager->getRepository(User::class);

        if (!$repository instanceof UserRepository) {
            throw new \RuntimeException(sprintf(
                'Expected %s, got %s',
                UserRepository::class,
                $repository::class,
            ));
        }

        return $repository;
    },

    JwtService::class => autowire()
        ->constructorParameter('secret', Env::get('JWT_SECRET', ''))
        ->constructorParameter('algorithm', Env::get('JWT_ALGORITHM', 'HS256'))
        ->constructorParameter('ttlSeconds', Env::int('JWT_TTL', 3600)),

    AuthMiddleware::class => autowire(),

    RateLimiterFactory::class => factory(
        static fn (): RateLimiterFactory => new RateLimiterFactory(
            [
                'id' => 'imports',
                'policy' => 'token_bucket',
                'limit' => Env::int('IMPORT_RATE_LIMIT', 5),
                'rate' => ['interval' => '1 minute', 'amount' => Env::int('IMPORT_RATE_LIMIT', 5)],
            ],
            new CacheStorage(new FilesystemAdapter('rate_limiter', 0, $projectDir . '/var/cache')),
        ),
    ),

    EventDispatcherInterface::class => factory(
        static fn (ContainerInterface $container): EventDispatcherInterface
            => $container->get(MessengerFactory::class)->createEventDispatcher(
                $container->get('messenger.transport_locator'),
                $container->get(MessengerFactory::class)->createFailureSendersLocator(
                    $container->get('messenger.transport_locator'),
                ),
                $container->get(MessengerFactory::class)->createRetryStrategyLocator(),
                $container->get(LoggerInterface::class),
            ),
    ),

    'messenger.restart_signal_cache' => factory(
        static fn (): FilesystemAdapter => new FilesystemAdapter('messenger_restart', 0, $projectDir . '/var/cache'),
    ),
];
