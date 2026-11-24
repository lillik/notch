<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Link;

interface LinkCacheRepository
{
    public function set(Link $link): void;

    public function get(string $code): ?Link;

    public function invalidate(string $code): void;
}
