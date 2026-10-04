<?php

declare(strict_types=1);

namespace Core\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

final class EntityManagerFactory
{
    private const DSN_SCHEME_MAPPING = [
        'pgsql' => 'pdo_pgsql',
        'postgres' => 'pdo_pgsql',
        'postgresql' => 'pdo_pgsql',
    ];

    public function __construct(
        private readonly string $entityPath,
        private readonly bool $devMode,
        private readonly string $cacheDir,
    ) {
    }

    public function create(string $databaseUrl, string $serverVersion): EntityManagerInterface
    {
        $config = ORMSetup::createAttributeMetadataConfig(
            paths: [$this->entityPath],
            isDevMode: $this->devMode,
            cache: $this->createCache(),
        );
        $config->enableNativeLazyObjects(true);

        return new EntityManager($this->createConnection($databaseUrl, $serverVersion), $config);
    }

    public function createConnection(string $databaseUrl, string $serverVersion): Connection
    {
        $params = (new DsnParser(self::DSN_SCHEME_MAPPING))->parse($databaseUrl);
        $params['serverVersion'] = $serverVersion;

        return DriverManager::getConnection($params);
    }

    private function createCache(): FilesystemAdapter
    {
        return new FilesystemAdapter('doctrine', 0, $this->cacheDir);
    }
}
