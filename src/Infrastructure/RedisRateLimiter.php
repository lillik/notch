<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Predis\Client;

final readonly class RedisRateLimiter
{
    public function __construct(
        private Client $redis,
        private int    $limit,
        private int    $windowSeconds,
    ) {
    }

    public function allow(string $action, string $client): bool
    {
        $bucket = sprintf('rate:%s:%s:%d', $action, $client, intdiv(time(), $this->windowSeconds));
        $count = (int) $this->redis->incr($bucket);
        if ($count === 1) {
            $this->redis->expire($bucket, $this->windowSeconds);
        }

        return $count <= $this->limit;
    }
}
