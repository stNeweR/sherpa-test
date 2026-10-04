<?php

declare(strict_types=1);

namespace Core\Bootstrap;

use Symfony\Component\Dotenv\Dotenv;

final class Env
{
    public static function load(string ...$candidates): void
    {
        foreach ($candidates as $path) {
            if (is_file($path)) {
                (new Dotenv())->usePutenv(true)->loadEnv($path);

                return;
            }
        }
    }

    public static function get(string $key, ?string $default = null): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if (is_string($value)) {
            return $value;
        }

        if ($value === false) {
            if ($default === null) {
                throw new \RuntimeException(sprintf('Environment variable "%s" is not defined', $key));
            }

            return $default;
        }

        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        throw new \RuntimeException(sprintf('Environment variable "%s" must be a scalar or Stringable', $key));
    }

    public static function int(string $key, int $default): int
    {
        return (int) self::get($key, (string) $default);
    }

    public static function bool(string $key, bool $default): bool
    {
        return in_array(strtolower(self::get($key, $default ? '1' : '0')), ['1', 'true', 'yes', 'on'], true);
    }
}
