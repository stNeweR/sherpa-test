<?php

declare(strict_types=1);

namespace Core\Bootstrap;

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

final class ContainerFactory
{
    public static function create(string $projectDir): ContainerInterface
    {
        /** @var array<string, mixed> $definitions */
        $definitions = require $projectDir . '/config/container.php';

        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->addDefinitions($definitions);

        return $builder->build();
    }
}
