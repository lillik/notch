<?php

declare(strict_types=1);

namespace App\Tests;

use App\Domain\Exception\InvalidLongUrl;
use App\Domain\ValueObject\UrlPolicy;
use PHPUnit\Framework\TestCase;

final class UrlPolicyTest extends TestCase
{
    private UrlPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new UrlPolicy();
    }

    public function testAllowsPublicHttpsUrl(): void
    {
        $this->policy->assertAllowed('https://example.com/path?q=1');
        self::assertTrue(true);
    }

    public function testRejectsNonHttpsAndPrivateHosts(): void
    {
        $rejected = [
            'http://example.com',
            'javascript:alert(1)',
            'https://user:pass@example.com',
            'https://localhost/admin',
            'https://127.0.0.1/',
            'https://10.1.2.3/secret',
            'https://169.254.169.254/latest',
        ];

        foreach ($rejected as $url) {
            try {
                $this->policy->assertAllowed($url);
                self::fail($url . ' should be rejected');
            } catch (InvalidLongUrl) {
                self::assertTrue(true);
            }
        }
    }
}
