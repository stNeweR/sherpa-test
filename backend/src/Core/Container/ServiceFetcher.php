<?php

declare(strict_types=1);

namespace Core\Container;

use function get_debug_type;

use Psr\Container\ContainerInterface;
use RuntimeException;

use function sprintf;

/**
 * PSR-11 ContainerInterface::get() возвращает mixed, из-за чего каждый вызов
 * приходилось уточнять вручную. Обёртка проверяет фактический тип сервиса
 * и возвращает его уже типизированным.
 */
final readonly class ServiceFetcher
{
    public function __construct(private ContainerInterface $container)
    {
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    public function get(string $id): object
    {
        return $this->resolve($id, $id);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $expectedType
     *
     * @return T
     */
    public function getAs(string $id, string $expectedType): object
    {
        return $this->resolve($id, $expectedType);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $expectedType
     *
     * @return T
     */
    private function resolve(string $id, string $expectedType): object
    {
        $service = $this->container->get($id);

        if (!$service instanceof $expectedType) {
            throw new RuntimeException(sprintf(
                'Service "%s" must be an instance of %s, %s given.',
                $id,
                $expectedType,
                get_debug_type($service),
            ));
        }

        return $service;
    }
}
