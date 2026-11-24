<?php

declare(strict_types=1);

namespace App\Action;

use App\Http\PayloadResponse;
use OpenApi\Attributes as OA;
use Predis\Client;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[OA\Get(
    path: '/health',
    operationId: 'health',
    summary: 'Report whether Postgres and Redis answer',
    parameters: [new OA\Parameter(ref: '#/components/parameters/Accept')],
    responses: [
        new OA\Response(response: 200, description: 'Dependencies answer', content: [
            new OA\JsonContent(ref: '#/components/schemas/Health'),
            new OA\XmlContent(ref: '#/components/schemas/Health'),
        ]),
        new OA\Response(response: 406, description: 'Accept is neither JSON nor XML'),
        new OA\Response(response: 503, description: 'A dependency is down', content: [
            new OA\JsonContent(ref: '#/components/schemas/Health'),
            new OA\XmlContent(ref: '#/components/schemas/Health'),
        ]),
    ],
)]
final class HealthAction implements RequestHandlerInterface
{
    public function __construct(
        private readonly \PDO $primary,
        private readonly Client $redis,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $this->primary->query('SELECT 1');
            $this->redis->ping();
        } catch (\Throwable) {
            return new PayloadResponse(503, ['status' => 'down']);
        }

        return new PayloadResponse(200, ['status' => 'ok']);
    }
}
