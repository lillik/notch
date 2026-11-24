<?php

declare(strict_types=1);

namespace App\Domain\Entity;

final class Click
{
    private readonly \DateTimeImmutable $clickedAt;

    public function __construct(
        private readonly string $code,
        ?\DateTimeImmutable $clickedAt = null,
    ) {
        $this->clickedAt = $clickedAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function code(): string
    {
        return $this->code;
    }

    public function clickedAt(): \DateTimeImmutable
    {
        return $this->clickedAt;
    }
}
