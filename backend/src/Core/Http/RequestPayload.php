<?php

declare(strict_types=1);

namespace Core\Http;

use function is_scalar;

use Psr\Http\Message\ServerRequestInterface;
use Stringable;

use function trim;

final readonly class RequestPayload
{
    /** @param array<array-key, mixed> $data */
    public function __construct(private array $data)
    {
    }

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $body = $request->getParsedBody();

        return new self(is_array($body) ? $body : []);
    }

    /** @param array<array-key, mixed>|null $data */
    public static function fromArray(?array $data): self
    {
        return new self($data ?? []);
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->data[$key] ?? null;

        if (!is_scalar($value) && !$value instanceof Stringable) {
            return $default;
        }

        $string = trim((string) $value);

        return $string === '' ? $default : $string;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->data[$key] ?? null;

        return is_scalar($value) && is_numeric((string) $value) ? (int) $value : $default;
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    /** @return array<array-key, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
