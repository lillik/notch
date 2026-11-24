<?php

declare(strict_types=1);

namespace App\Http;

use Slim\Psr7\Response;

final class Json
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function write(int $status, array $payload): Response
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Vary', 'Accept');
    }
}
