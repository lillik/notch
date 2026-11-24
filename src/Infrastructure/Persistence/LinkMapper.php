<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Entity\Link;

final class LinkMapper
{
    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): Link
    {
        return Link::reconstitute(
            (string) $row['code'],
            (string) $row['long_url'],
            new \DateTimeImmutable((string) $row['created_at']),
            isset($row['expires_at']) && $row['expires_at'] !== null
                ? new \DateTimeImmutable((string) $row['expires_at'])
                : null,
        );
    }

    /**
     * @return array{code: string, long_url: string, created_at: string, expires_at: ?string}
     */
    public static function toCache(Link $link): array
    {
        return [
            'code' => $link->code(),
            'long_url' => $link->longUrl(),
            'created_at' => $link->createdAt()->format(DATE_ATOM),
            'expires_at' => $link->expiresAt()?->format(DATE_ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromCache(array $data): Link
    {
        return self::fromRow($data);
    }
}
