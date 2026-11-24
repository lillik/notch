<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ResponseInterface;

final class XmlResponseWriter implements ResponseWriter
{
    public function write(int $status, array $payload): ResponseInterface
    {
        return Xml::write($status, $payload);
    }
}
