<?php

declare(strict_types=1);

namespace App\Http;

use Slim\Psr7\Response;

final class PayloadResponse extends Response
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        int $status,
        private readonly array $payload,
    ) {
        parent::__construct($status);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }
}
