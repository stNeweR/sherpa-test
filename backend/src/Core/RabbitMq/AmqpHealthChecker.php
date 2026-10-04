<?php

declare(strict_types=1);

namespace Core\RabbitMq;

use AMQPConnection;

final class AmqpHealthChecker
{
    private const TIMEOUT_SECONDS = 3;

    public function __construct(private readonly string $transportDsn)
    {
    }

    /**
     * @return array{host: string, port: int, vhost: string, exchange: string, user: string, password: string}
     */
    public function check(): array
    {
        $params = $this->connectionParameters();

        $connection = new AMQPConnection();
        $connection->setHost($params['host']);
        $connection->setPort($params['port']);
        $connection->setLogin($params['user']);
        $connection->setPassword($params['password']);
        $connection->setVhost($params['vhost']);
        $connection->setReadTimeout(self::TIMEOUT_SECONDS);
        $connection->setWriteTimeout(self::TIMEOUT_SECONDS);

        try {
            $connection->connect();

            return $params + ['status' => 'ok'];
        } finally {
            $connection->disconnect();
        }
    }

    /**
     * @return array{host: string, port: int, vhost: string, exchange: string, user: string, password: string}
     */
    private function connectionParameters(): array
    {
        $parts = parse_url($this->transportDsn);
        if ($parts === false || !isset($parts['host'])) {
            throw new \InvalidArgumentException(sprintf('Invalid AMQP DSN: %s', $this->transportDsn));
        }

        $pathParts = isset($parts['path']) ? explode('/', trim($parts['path'], '/')) : [];
        $vhost = isset($pathParts[0]) && urldecode($pathParts[0]) !== '' ? urldecode($pathParts[0]) : '/';

        return [
            'host' => $parts['host'],
            'port' => (int) ($parts['port'] ?? 5672),
            'vhost' => $vhost,
            'exchange' => $pathParts[1] ?? 'messages',
            'user' => isset($parts['user']) ? rawurldecode($parts['user']) : 'guest',
            'password' => isset($parts['pass']) ? rawurldecode($parts['pass']) : 'guest',
        ];
    }
}
