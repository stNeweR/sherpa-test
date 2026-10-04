<?php

declare(strict_types=1);

namespace App\Tests\Unit\UseCase\Import;

use App\Dto\Import\ImportJobMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Шина, которая запоминает отправленные сообщения вместо отправки в RabbitMQ.
 *
 * Отдельный класс, а не анонимный внутри теста: Messenger нельзя подменить через
 * createStub(), потому что dispatch() возвращает финальный Envelope, который
 * PHPUnit не умеет дублировать.
 */
final class RecordingMessageBus implements MessageBusInterface
{
    /** @var list<ImportJobMessage> */
    public array $messages = [];

    /** @param array<StampInterface> $stamps */
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        if ($message instanceof ImportJobMessage) {
            $this->messages[] = $message;
        }

        return new Envelope($message);
    }
}
