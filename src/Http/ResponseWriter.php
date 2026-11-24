<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ResponseInterface;

interface ResponseWriter
{
    /**
     * @param array<string, mixed> $payload
     */
    public function write(int $status, array $payload): ResponseInterface;
}
