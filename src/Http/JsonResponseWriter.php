<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ResponseInterface;

final class JsonResponseWriter implements ResponseWriter
{
    public function write(int $status, array $payload): ResponseInterface
    {
        return Json::write($status, $payload);
    }
}
