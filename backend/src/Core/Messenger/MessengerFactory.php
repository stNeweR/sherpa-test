<?php

declare(strict_types=1);

namespace Core\Messenger;

use App\Dto\Import\ImportJobMessage;
use App\Service\Import\ImportJobHandler;
use Core\Bootstrap\Env;
use Core\Container\ServiceFetcher;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransportFactory;
use Symfony\Component\Messenger\EventListener\AddErrorDetailsStampListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnFailureLimitListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\AddBusNameStampMiddleware;
use Symfony\Component\Messenger\Middleware\DispatchAfterCurrentBusMiddleware;
use Symfony\Component\Messenger\Middleware\FailedMessageProcessingMiddleware;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\RejectRedeliveredMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactory;
use Symfony\Component\Messenger\Transport\TransportInterface;

final class MessengerFactory
{
    public const DEFAULT_TRANSPORT = 'async';
    public const FAILURE_TRANSPORT = 'failed';
    public const DEFAULT_BUS = 'messenger.bus.default';

    public function __construct(
        private readonly string $transportDsn,
        private readonly string $failureTransportDsn,
        private readonly int $retryMaxAttempts,
        private readonly int $retryDelayMs,
    ) {
    }

    public function createSerializer(): SerializerInterface
    {
        return new Serializer();
    }

    public function createTransportFactory(): TransportFactory
    {
        return new TransportFactory([new AmqpTransportFactory()]);
    }

    public function createTransport(string $dsn): TransportInterface
    {
        return $this->createTransportFactory()->createTransport($dsn, [], $this->createSerializer());
    }

    public function createDefaultTransport(): TransportInterface
    {
        return $this->createTransport($this->transportDsn);
    }

    public function createFailureTransport(): TransportInterface
    {
        return $this->createTransport($this->failureTransportDsn);
    }

    public function createTransportLocator(ContainerInterface $container): ContainerInterface
    {
        $services = new ServiceFetcher($container);

        return new ContainerLocator([
            self::DEFAULT_TRANSPORT => static fn (): TransportInterface => $services->get(TransportInterface::class),
            self::FAILURE_TRANSPORT => static fn (): TransportInterface
                => $services->getAs('messenger.transport.failed', TransportInterface::class),
        ]);
    }

    public function createSendersLocator(ContainerInterface $transportLocator): SendersLocator
    {
        return new SendersLocator(['*' => [self::DEFAULT_TRANSPORT]], $transportLocator);
    }

    /** @return ContainerLocator<TransportInterface> */
    public function createFailureSendersLocator(ContainerInterface $transportLocator): ContainerLocator
    {
        $services = new ServiceFetcher($transportLocator);
        $failureTransport = static fn (): TransportInterface
            => $services->getAs(self::FAILURE_TRANSPORT, TransportInterface::class);

        return new ContainerLocator([
            self::DEFAULT_TRANSPORT => $failureTransport,
            self::FAILURE_TRANSPORT => $failureTransport,
        ]);
    }

    public function createRetryStrategy(): RetryStrategyInterface
    {
        return new MultiplierRetryStrategy(
            maxRetries: $this->retryMaxAttempts,
            delayMilliseconds: $this->retryDelayMs,
            multiplier: 2.0,
        );
    }

    public function createRetryStrategyLocator(): ContainerInterface
    {
        $strategy = $this->createRetryStrategy();

        return new ContainerLocator([
            self::DEFAULT_TRANSPORT => static fn (): RetryStrategyInterface => $strategy,
            self::FAILURE_TRANSPORT => static fn (): RetryStrategyInterface => $strategy,
        ]);
    }

    public function createBus(
        ContainerInterface $transportLocator,
        SendersLocatorInterface $sendersLocator,
        ImportJobHandler $importJobHandler,
    ): MessageBus {
        return new MessageBus([
            new AddBusNameStampMiddleware(self::DEFAULT_BUS),
            new RejectRedeliveredMessageMiddleware(),
            new DispatchAfterCurrentBusMiddleware(),
            new FailedMessageProcessingMiddleware(),
            new SendMessageMiddleware($sendersLocator),
            new HandleMessageMiddleware($this->createHandlersLocator($importJobHandler)),
        ]);
    }

    /**
     * Symfony вызывает зарегистрированный handler напрямую с сообщением,
     * поэтому в locator кладётся сам инвокабельный объект, а не замыкание-фабрика:
     * замыкание вернуло бы handler как результат и тихо ничего не выполнило бы.
     */
    public function createHandlersLocator(ImportJobHandler $importJobHandler): HandlersLocator
    {
        return new HandlersLocator([
            ImportJobMessage::class => [$importJobHandler],
        ]);
    }

    public function createRoutableMessageBus(MessageBus $bus): RoutableMessageBus
    {
        return new RoutableMessageBus(new ContainerLocator([
            self::DEFAULT_BUS => static fn (): MessageBus => $bus,
        ]), $bus);
    }

    public function createEventDispatcher(
        ContainerInterface $transportLocator,
        ContainerInterface $failureSendersLocator,
        ContainerInterface $retryStrategyLocator,
        LoggerInterface $logger,
    ): EventDispatcherInterface {
        $dispatcher = new EventDispatcher();

        $dispatcher->addSubscriber(new AddErrorDetailsStampListener());
        $dispatcher->addSubscriber(new SendFailedMessageForRetryListener(
            $transportLocator,
            $retryStrategyLocator,
            $logger,
            $dispatcher,
        ));
        $dispatcher->addSubscriber(new SendFailedMessageToFailureTransportListener(
            $failureSendersLocator,
            $logger,
            failureTransportsByName: [self::DEFAULT_TRANSPORT => self::FAILURE_TRANSPORT],
        ));
        $dispatcher->addSubscriber(new StopWorkerOnFailureLimitListener(
            Env::int('MESSENGER_FAILURE_LIMIT', 200),
            $logger,
        ));

        return $dispatcher;
    }
}
