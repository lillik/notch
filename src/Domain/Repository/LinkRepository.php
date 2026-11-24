<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Link;
use App\Domain\Exception\DuplicateCode;

interface LinkRepository
{
    /**
     * @throws DuplicateCode
     */
    public function save(Link $link): void;

    public function find(string $code): ?Link;
}
