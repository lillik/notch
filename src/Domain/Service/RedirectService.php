<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\Entity\Click;
use App\Domain\Entity\Link;
use App\Domain\Exception\LinkExpired;
use App\Domain\Exception\LinkNotFound;
use App\Domain\Port\ClickPublisher;
use App\Domain\Repository\LinkCacheRepository;
use App\Domain\Repository\LinkRepository;

final class RedirectService
{
    public function __construct(
        private readonly LinkCacheRepository $cache,
        private readonly LinkRepository $links,
        private readonly ClickPublisher $clicks,
    ) {
    }

    public function redirect(string $code): Link
    {
        $link = $this->cache->get($code);
        if ($link === null) {
            $link = $this->links->find($code);
            if ($link === null) {
                throw new LinkNotFound('Link was not found');
            }
        }

        if ($link->isExpired(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))) {
            $this->cache->invalidate($code);
            throw new LinkExpired('Link has expired');
        }

        $this->cache->set($link);
        $this->clicks->publish(new Click($link->code()));

        return $link;
    }
}
