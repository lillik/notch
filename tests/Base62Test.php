<?php

declare(strict_types=1);

namespace App\Tests;

use App\Domain\ValueObject\Base62;
use PHPUnit\Framework\TestCase;

final class Base62Test extends TestCase
{
    public function testEncodeKnownValues(): void
    {
        self::assertSame('0', Base62::encode(0));
        self::assertSame('z', Base62::encode(61));
        self::assertSame('10', Base62::encode(62));
        self::assertSame(62, Base62::decode('10'));
    }

    public function testSevenCharacterFloor(): void
    {
        self::assertSame('1000000', Base62::encode(62 ** 6));
        self::assertSame(7, strlen(Base62::encode(62 ** 6)));
    }

    public function testDecodeRejectsUnknownCharacters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Base62::decode('ab-c');
    }
}
