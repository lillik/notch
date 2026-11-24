<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Entity\Link;
use App\Domain\Repository\LinkCacheRepository;
use Predis\Client;

final class RedisLinkCacheRepository implements LinkCacheRepository
{
    private const TTL_SECONDS = 86400;

    public function __construct(private readonly Client $redis)
    {
    }

    public function set(Link $link): void
    {
        $ttl = self::TTL_SECONDS;
        if ($link->expiresAt() !== null) {
            $remaining = $link->expiresAt()->getTimestamp() - time();
            if ($remaining < 1) {
                $this->invalidate($link->code());

                return;
            }
            $ttl = min($ttl, $remaining);
        }

        $this->redis->setex(
            $this->key($link->code()),
            $ttl,
            json_encode(LinkMapper::toCache($link), JSON_THROW_ON_ERROR),
        );
    }

    public function get(string $code): ?Link
    {
        $raw = $this->redis->get($this->key($code));
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['code'], $data['long_url'], $data['created_at'])) {
            return null;
        }

        return LinkMapper::fromCache($data);
    }

    public function invalidate(string $code): void
    {
        $this->redis->del([$this->key($code)]);
    }

    private function key(string $code): string
    {
        return 'link:' . $code;
    }
}
