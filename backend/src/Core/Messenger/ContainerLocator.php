<?php

declare(strict_types=1);

namespace Core\Messenger;

use function array_key_exists;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * @template TService of object
 *
 * @implements ServiceProviderInterface<TService>
 */
final class ContainerLocator implements ContainerInterface, ServiceProviderInterface
{
    /**
     * @param array<string, callable(): TService> $factories
     * @param array<string, string>             $types
     */
    public function __construct(
        private readonly array $factories,
        private readonly array $types = [],
    ) {
    }

    /** @return TService */
    public function get(string $id): object
    {
        if (!$this->has($id)) {
            throw new class (sprintf('Service "%s" not found in locator.', $id)) extends \InvalidArgumentException implements NotFoundExceptionInterface {
            };
        }

        return ($this->factories[$id])();
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->factories);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->factories);
    }

    /**
     * @return array<string, string>
     */
    public function getProvidedServices(): array
    {
        $types = [];

        foreach (array_keys($this->factories) as $id) {
            $types[$id] = $this->types[$id] ?? '?';
        }

        return $types;
    }
}
