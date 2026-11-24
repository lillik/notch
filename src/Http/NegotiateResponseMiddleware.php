<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class NegotiateResponseMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ResponseFormat $format)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $writer = $this->format->select($request);
        if ($writer === null) {
            $response = new Response(406);
            $response->getBody()->write('not acceptable');

            return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $response = $handler->handle($request);
        if (!$response instanceof PayloadResponse) {
            return $response;
        }

        return $writer->write($response->getStatusCode(), $response->payload());
    }
}
