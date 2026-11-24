<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

final class Base62
{
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public static function encode(int $value): string
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('value must be non-negative');
        }

        if ($value === 0) {
            return self::ALPHABET[0];
        }

        $encoded = '';
        while ($value > 0) {
            $encoded = self::ALPHABET[$value % 62] . $encoded;
            $value = intdiv($value, 62);
        }

        return $encoded;
    }

    public static function decode(string $code): int
    {
        if ($code === '') {
            throw new \InvalidArgumentException('code is empty');
        }

        $decoded = 0;
        $length = strlen($code);
        for ($i = 0; $i < $length; $i++) {
            $pos = strpos(self::ALPHABET, $code[$i]);
            if ($pos === false) {
                throw new \InvalidArgumentException('invalid base62 code');
            }
            $decoded = $decoded * 62 + $pos;
        }

        return $decoded;
    }
}
