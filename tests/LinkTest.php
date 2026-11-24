<?php

declare(strict_types=1);

namespace App\Tests;

use App\Domain\Entity\Link;
use PHPUnit\Framework\TestCase;

final class LinkTest extends TestCase
{
    public function testReconstituteKeepsTheStoredTimestamp(): void
    {
        $createdAt = new \DateTimeImmutable('2020-01-02T03:04:05Z');
        $link = Link::reconstitute('abc1234', 'https://example.com', $createdAt, null);

        self::assertSame('2020-01-02T03:04:05+00:00', $link->createdAt()->format(DATE_ATOM));
        self::assertFalse($link->isExpired(new \DateTimeImmutable('2026-01-01T00:00:00Z')));
    }

    public function testExpiryIsInclusiveOfTheDeadline(): void
    {
        $deadline = new \DateTimeImmutable('2026-06-01T00:00:00Z');
        $link = Link::reconstitute('abc1234', 'https://example.com', $deadline, $deadline);

        self::assertTrue($link->isExpired($deadline));
    }
}
