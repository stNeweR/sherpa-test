<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Messenger;

use App\Dto\Import\ImportJobMessage;
use App\Entity\ImportJob;
use App\Repository\ImportJobRepository;
use App\Service\Import\ImportJobHandler;
use Core\Container\ServiceFetcher;
use Core\Messenger\MessengerFactory;
use DI\ContainerBuilder;
use DI\Definition\Helper\FactoryDefinitionHelper;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;

/**
 * Регрессия: Symfony вызывает handler из HandlersLocator напрямую, поэтому
 * неверная форма locator приводила к тихому «успешному» выполнению без обработки.
 *
 * Набор unit не должен требовать БД, поэтому репозиторий задач заменён заглушкой:
 * handler завершается на «задача не найдена» до первого запроса к базе.
 */
final class MessengerFactoryTest extends TestCase
{
    public function testLocatorResolvesInvokableImportHandler(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Задача импорта не найдена'),
                $this->anything(),
            );

        $handler = $this->services($logger)->get(ImportJobHandler::class);
        $locator = $this->factory()->createHandlersLocator($handler);

        $descriptors = iterator_to_array($locator->getHandlers(new Envelope($this->message())));

        $this->assertCount(1, $descriptors);

        $descriptors[0]->getHandler()($this->message());
    }

    public function testLocatorIgnoresOtherMessages(): void
    {
        $locator = $this->factory()->createHandlersLocator($this->services()->get(ImportJobHandler::class));

        $this->assertSame([], iterator_to_array($locator->getHandlers(new Envelope(new \stdClass()))));
    }

    private function factory(): MessengerFactory
    {
        return new MessengerFactory(
            transportDsn: 'amqp://guest:guest@localhost:5672/%2f/imports',
            failureTransportDsn: 'amqp://guest:guest@localhost:5672/%2f/failed',
            retryMaxAttempts: 3,
            retryDelayMs: 1000,
        );
    }

    private function container(?LoggerInterface $logger = null): ContainerInterface
    {
        /** @var array<string, mixed> $definitions */
        $definitions = require dirname(__DIR__, 4) . '/config/container.php';

        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->addDefinitions($definitions);
        $builder->addDefinitions([
            LoggerInterface::class => new FactoryDefinitionHelper(static fn (): LoggerInterface => $logger ?? new NullLogger()),
            ImportJobRepository::class => new FactoryDefinitionHelper(
                static fn (): ImportJobRepository => new class () extends ImportJobRepository {
                    public function __construct()
                    {
                    }

                    public function findJob(string $id): ?ImportJob
                    {
                        return null;
                    }
                },
            ),
        ]);

        return $builder->build();
    }

    private function services(?LoggerInterface $logger = null): ServiceFetcher
    {
        return new ServiceFetcher($this->container($logger));
    }

    private function message(): ImportJobMessage
    {
        return new ImportJobMessage(
            jobId: 'job-missing',
            filePath: '/tmp/missing.xlsx',
            originalName: 'missing.xlsx',
        );
    }
}
