<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Entity\Link;
use App\Domain\Exception\DuplicateCode;
use App\Domain\Repository\LinkRepository;

final class PostgresLinkRepository implements LinkRepository
{
    public function __construct(
        private readonly \PDO $primary,
        private readonly \PDO $replica,
    ) {
    }

    public function save(Link $link): void
    {
        $statement = $this->primary->prepare(
            'INSERT INTO links (code, long_url, created_at, expires_at)
             VALUES (:code, :long_url, :created_at, :expires_at)',
        );

        try {
            $statement->execute([
                'code' => $link->code(),
                'long_url' => $link->longUrl(),
                'created_at' => $link->createdAt()->format(DATE_ATOM),
                'expires_at' => $link->expiresAt()?->format(DATE_ATOM),
            ]);
        } catch (\PDOException $exception) {
            if (($exception->errorInfo[0] ?? '') === '23505') {
                throw new DuplicateCode('Code is already used');
            }
            throw $exception;
        }
    }

    public function find(string $code): ?Link
    {
        try {
            $link = $this->select($this->replica, $code);
        } catch (\PDOException) {
            $link = null;
        }

        return $link ?? $this->select($this->primary, $code);
    }

    private function select(\PDO $db, string $code): ?Link
    {
        $statement = $db->prepare(
            'SELECT code, long_url, created_at, expires_at FROM links WHERE code = :code',
        );
        $statement->execute(['code' => $code]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return LinkMapper::fromRow($row);
    }
}
