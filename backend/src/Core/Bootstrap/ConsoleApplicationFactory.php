<?php

declare(strict_types=1);

namespace Core\Bootstrap;

use App\Console\Command\HealthCommand;
use App\Console\Command\SeedCommand;
use Core\Container\ServiceFetcher;
use Core\Messenger\MessengerFactory;
use Doctrine\Migrations\Configuration\Configuration as MigrationsConfiguration;
use Doctrine\Migrations\Configuration\EntityManager\ExistingEntityManager;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\Command\DiffCommand;
use Doctrine\Migrations\Tools\Console\Command\GenerateCommand;
use Doctrine\Migrations\Tools\Console\Command\LatestCommand;
use Doctrine\Migrations\Tools\Console\Command\ListCommand;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Doctrine\Migrations\Tools\Console\Command\StatusCommand;
use Doctrine\Migrations\Tools\Console\Command\UpToDateCommand;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Command\ConsumeMessagesCommand;
use Symfony\Component\Messenger\Command\DebugCommand;
use Symfony\Component\Messenger\Command\FailedMessagesRemoveCommand;
use Symfony\Component\Messenger\Command\FailedMessagesRetryCommand;
use Symfony\Component\Messenger\Command\FailedMessagesShowCommand;
use Symfony\Component\Messenger\Command\SetupTransportsCommand;
use Symfony\Component\Messenger\Command\StatsCommand;
use Symfony\Component\Messenger\Command\StopWorkersCommand;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Transport\TransportInterface;

final class ConsoleApplicationFactory
{
    private const TRANSPORT_NAMES = [MessengerFactory::DEFAULT_TRANSPORT, MessengerFactory::FAILURE_TRANSPORT];

    public function __construct(private readonly string $projectDir)
    {
    }

    public function create(ContainerInterface $container): ConsoleApplication
    {
        $services = new ServiceFetcher($container);
        $application = new ConsoleApplication('Import/Export products', '1.0.0');
        $application->addCommand($services->get(HealthCommand::class));
        $application->addCommand($services->get(SeedCommand::class));

        foreach ($this->createMigrationsCommands($container) as $command) {
            $application->addCommand($command);
        }

        foreach ($this->createMessengerCommands($container) as $command) {
            $application->addCommand($command);
        }

        return $application;
    }

    /**
     * @return list<Command>
     */
    private function createMigrationsCommands(ContainerInterface $container): array
    {
        $services = new ServiceFetcher($container);
        $configuration = new MigrationsConfiguration();
        $configuration->addMigrationsDirectory('App\\Migrations', $this->projectDir . '/src/App/Migrations');

        $entityManager = $services->get(EntityManagerInterface::class);

        $dependencyFactory = DependencyFactory::fromEntityManager(
            new ExistingConfiguration($configuration),
            new ExistingEntityManager($entityManager),
        );

        return [
            new MigrateCommand($dependencyFactory),
            new StatusCommand($dependencyFactory),
            new DiffCommand($dependencyFactory),
            new GenerateCommand($dependencyFactory),
            new ListCommand($dependencyFactory),
            new LatestCommand($dependencyFactory),
            new UpToDateCommand($dependencyFactory),
        ];
    }

    /**
     * @return list<Command>
     */
    private function createMessengerCommands(ContainerInterface $container): array
    {
        $services = new ServiceFetcher($container);
        $transportLocator = $services->getAs('messenger.transport_locator', ContainerInterface::class);
        $failureTransports = $services->get(MessengerFactory::class)
            ->createFailureSendersLocator($transportLocator);

        return [
            new ConsumeMessagesCommand(
                $services->get(RoutableMessageBus::class),
                $transportLocator,
                $services->get(EventDispatcherInterface::class),
                $services->get(LoggerInterface::class),
                [MessengerFactory::DEFAULT_TRANSPORT],
                null,
                [MessengerFactory::DEFAULT_BUS],
            ),
            new SetupTransportsCommand($transportLocator, self::TRANSPORT_NAMES),
            new StatsCommand($transportLocator, self::TRANSPORT_NAMES),
            new StopWorkersCommand($services->getAs('messenger.restart_signal_cache', CacheItemPoolInterface::class)),
            new FailedMessagesShowCommand(MessengerFactory::FAILURE_TRANSPORT, $failureTransports),
            new FailedMessagesRemoveCommand(MessengerFactory::FAILURE_TRANSPORT, $failureTransports),
            new FailedMessagesRetryCommand(
                MessengerFactory::FAILURE_TRANSPORT,
                $failureTransports,
                $services->get(MessageBus::class),
                $services->get(EventDispatcherInterface::class),
                $services->get(LoggerInterface::class),
            ),
            new DebugCommand([MessengerFactory::DEFAULT_BUS => [TransportInterface::class => true]]),
        ];
    }
}
