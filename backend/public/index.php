<?php

declare(strict_types=1);

use Core\Bootstrap\AppFactory;
use Core\Bootstrap\ContainerFactory;
use Core\Bootstrap\Env;

require dirname(__DIR__) . '/vendor/autoload.php';

Env::load(dirname(__DIR__, 2) . '/.env', dirname(__DIR__) . '/.env');

$projectDir = dirname(__DIR__);
$container = ContainerFactory::create($projectDir);

(new AppFactory())->create($container)->run();
