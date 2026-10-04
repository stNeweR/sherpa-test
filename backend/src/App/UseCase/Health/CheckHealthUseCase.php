<?php

declare(strict_types=1);

namespace App\UseCase\Health;

use Core\RabbitMq\AmqpHealthChecker;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

use function sprintf;

use Throwable;

/**
 * Проверка доступности БД и брокера. Одна проверка — один use case, поэтому
 * и HTTP-ручка, и CLI-команда получают одинаковый снимок состояния.
 *
 * @phpstan-type CheckResult array{status: 'ok'|'error', message?: string}
 */
final readonly class CheckHealthUseCase
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AmqpHealthChecker $amqpHealthChecker,
    ) {
    }

    /** @return array{status: string, database: CheckResult, broker: CheckResult, time: string} */
    public function handle(): array
    {
        $database = $this->check(function (): void {
            $this->entityManager->getConnection()->executeQuery('SELECT 1')->fetchOne();
        });

        $broker = $this->check(function (): void {
            $this->amqpHealthChecker->check();
        });

        $healthy = $database['status'] === 'ok' && $broker['status'] === 'ok';

        return [
            'status' => $healthy ? 'ok' : 'degraded',
            'database' => $database,
            'broker' => $broker,
            'time' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param callable(): void $probe
     *
     * @return CheckResult
     */
    private function check(callable $probe): array
    {
        try {
            $probe();

            return ['status' => 'ok'];
        } catch (Throwable $e) {
            return ['status' => 'error', 'message' => sprintf('%s: %s', $e::class, $e->getMessage())];
        }
    }
}
