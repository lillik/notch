<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\Entity\Link;
use App\Domain\Exception\DuplicateCode;
use App\Domain\Exception\InvalidLongUrl;
use App\Domain\Port\KeyLeaser;
use App\Domain\Repository\LinkCacheRepository;
use App\Domain\Repository\LinkRepository;
use App\Domain\ValueObject\UrlPolicy;

final class ShortenService
{
    public function __construct(
        private readonly UrlPolicy $urls,
        private readonly KeyLeaser $keys,
        private readonly LinkRepository $links,
        private readonly LinkCacheRepository $cache,
    ) {
    }

    public function shorten(string $longUrl, ?\DateTimeImmutable $expiresAt = null): Link
    {
        $this->urls->assertAllowed($longUrl);
        $this->assertExpiry($expiresAt);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $link = Link::create($this->keys->take(), $longUrl, $expiresAt);
            try {
                $this->links->save($link);
            } catch (DuplicateCode) {
                continue;
            }

            $this->cache->set($link);

            return $link;
        }

        throw new \RuntimeException('could not allocate a unique code');
    }

    private function assertExpiry(?\DateTimeImmutable $expiresAt): void
    {
        if ($expiresAt === null) {
            return;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($expiresAt <= $now) {
            throw new InvalidLongUrl('expires_at must be in the future');
        }
    }
}
