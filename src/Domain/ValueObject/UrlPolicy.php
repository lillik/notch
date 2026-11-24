<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

use App\Domain\Exception\InvalidLongUrl;

final class UrlPolicy
{
    private const MAX_LENGTH = 2048;

    public function assertAllowed(string $longUrl): void
    {
        if ($longUrl === '' || strlen($longUrl) > self::MAX_LENGTH) {
            throw new InvalidLongUrl('URL is invalid');
        }

        if (filter_var($longUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidLongUrl('URL is invalid');
        }

        $parts = parse_url($longUrl);
        if ($parts === false) {
            throw new InvalidLongUrl('URL is invalid');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https') {
            throw new InvalidLongUrl('Only https URLs are allowed');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidLongUrl('URL credentials are not allowed');
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || $this->isBlockedHost($host)) {
            throw new InvalidLongUrl('URL host is not allowed');
        }
    }

    private function isBlockedHost(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || $host === 'metadata.google.internal') {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }
}
