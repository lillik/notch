<?php

declare(strict_types=1);

namespace App;

use Predis\Client;

final class Bootstrap
{
    public static function env(string $name, ?string $default = null): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            if ($default !== null) {
                return $default;
            }
            throw new \RuntimeException($name . ' is not set');
        }

        return $value;
    }

    public static function pdoFromUrl(string $url): \PDO
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'], $parts['path'])) {
            throw new \RuntimeException('Invalid database URL');
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $parts['host'],
            (int) ($parts['port'] ?? 5432),
            ltrim($parts['path'], '/'),
        );

        return new \PDO(
            $dsn,
            isset($parts['user']) ? rawurldecode($parts['user']) : '',
            isset($parts['pass']) ? rawurldecode($parts['pass']) : '',
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ],
        );
    }

    public static function redis(): Client
    {
        $address = self::env('REDIS_ADDR', 'redis:6379');
        $parts = explode(':', $address, 2);

        return new Client([
            'host' => $parts[0],
            'port' => (int) ($parts[1] ?? 6379),
        ]);
    }
}
