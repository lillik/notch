<?php

declare(strict_types=1);

namespace App\Infrastructure\Key;

use App\Domain\Port\KeyLeaser;
use App\Domain\ValueObject\Base62;

final class BatchLeaser implements KeyLeaser
{
    private const BATCH = 1000;

    /** 62^6, so the first leased code is 7 characters. */
    private const OFFSET = 56800235584;

    /** @var list<string> Survives across requests inside one PHP-FPM worker. */
    private static array $remaining = [];

    public function __construct(private readonly \PDO $primary)
    {
    }

    public function take(): string
    {
        if (self::$remaining === []) {
            self::$remaining = $this->lease(self::BATCH);
        }

        $code = array_pop(self::$remaining);
        if ($code === null) {
            throw new \RuntimeException('key batch was empty');
        }

        return $code;
    }

    /**
     * @return list<string>
     */
    private function lease(int $count): array
    {
        $this->primary->beginTransaction();
        try {
            $statement = $this->primary->prepare(
                'UPDATE key_counter SET next_value = next_value + :count WHERE id = 1 RETURNING next_value',
            );
            $statement->execute(['count' => $count]);
            $end = $statement->fetchColumn();
            if ($end === false) {
                throw new \RuntimeException('key counter is missing');
            }
            $this->primary->commit();
        } catch (\Throwable $exception) {
            if ($this->primary->inTransaction()) {
                $this->primary->rollBack();
            }
            throw $exception;
        }

        $end = (int) $end;
        $start = $end - $count;
        $codes = [];
        for ($value = $start; $value < $end; $value++) {
            $codes[] = Base62::encode(self::OFFSET + $value);
        }

        return $codes;
    }
}
