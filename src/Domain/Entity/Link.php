<?php

declare(strict_types=1);

namespace App\Domain\Entity;

final class Link
{
    private function __construct(
        private readonly string $code,
        private readonly string $longUrl,
        private readonly \DateTimeImmutable $createdAt,
        private readonly ?\DateTimeImmutable $expiresAt,
    ) {
    }

    public static function create(
        string $code,
        string $longUrl,
        ?\DateTimeImmutable $expiresAt,
    ): self {
        return new self(
            $code,
            $longUrl,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            $expiresAt,
        );
    }

    public static function reconstitute(
        string $code,
        string $longUrl,
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $expiresAt,
    ): self {
        return new self($code, $longUrl, $createdAt, $expiresAt);
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function longUrl(): string
    {
        return $this->longUrl;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
