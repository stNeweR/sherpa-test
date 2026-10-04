<?php

declare(strict_types=1);

namespace App\Console\Command;

use App\UseCase\Health\CheckHealthUseCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:health', description: 'Проверить доступность БД и брокера сообщений')]
final class HealthCommand extends Command
{
    public function __construct(private readonly CheckHealthUseCase $checkHealth)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = $this->checkHealth->handle();

        $output->writeln(json_encode(
            $status,
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        ));

        return $status['status'] === 'ok' ? Command::SUCCESS : Command::FAILURE;
    }
}
