<?php

declare(strict_types=1);

namespace App\Action;

use App\Domain\Exception\InvalidLongUrl;
use App\Domain\Service\ShortenService;
use App\Http\PayloadResponse;
use App\Infrastructure\RedisRateLimiter;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[OA\Post(
    path: '/links',
    operationId: 'createLink',
    summary: 'Create a short link',
    parameters: [new OA\Parameter(ref: '#/components/parameters/Accept')],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(ref: '#/components/schemas/CreateLink'),
    ),
    responses: [
        new OA\Response(response: 201, description: 'Short link created', content: [
            new OA\JsonContent(ref: '#/components/schemas/CreatedLink'),
            new OA\XmlContent(ref: '#/components/schemas/CreatedLink'),
        ]),
        new OA\Response(response: 400, description: 'The URL or expiry is invalid', content: [
            new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            new OA\XmlContent(ref: '#/components/schemas/ErrorResponse'),
        ]),
        new OA\Response(response: 406, description: 'Accept is neither JSON nor XML'),
        new OA\Response(response: 429, description: 'Too many create requests from this address', content: [
            new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            new OA\XmlContent(ref: '#/components/schemas/ErrorResponse'),
        ]),
    ],
)]
#[OA\Post(
    path: '/shorten',
    operationId: 'createLinkAlias',
    summary: 'Create a short link',
    description: 'Same operation as POST /links.',
    parameters: [new OA\Parameter(ref: '#/components/parameters/Accept')],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(ref: '#/components/schemas/CreateLink'),
    ),
    responses: [
        new OA\Response(response: 201, description: 'Short link created', content: [
            new OA\JsonContent(ref: '#/components/schemas/CreatedLink'),
            new OA\XmlContent(ref: '#/components/schemas/CreatedLink'),
        ]),
        new OA\Response(response: 400, description: 'The URL or expiry is invalid', content: [
            new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            new OA\XmlContent(ref: '#/components/schemas/ErrorResponse'),
        ]),
        new OA\Response(response: 406, description: 'Accept is neither JSON nor XML'),
        new OA\Response(response: 429, description: 'Too many create requests from this address', content: [
            new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            new OA\XmlContent(ref: '#/components/schemas/ErrorResponse'),
        ]),
    ],
)]
final class CreateAction implements RequestHandlerInterface
{
    public function __construct(
        private readonly ShortenService $shorten,
        private readonly RedisRateLimiter $rates,
        private readonly string $publicBaseUrl,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $client = self::clientAddress($request);
        if (!$this->rates->allow('create', $client)) {
            return new PayloadResponse(429, ['error' => 'rate limit exceeded']);
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return new PayloadResponse(400, ['error' => 'JSON body is required']);
        }

        $longUrl = $body['long_url'] ?? $body['longUrl'] ?? '';
        if (!is_string($longUrl)) {
            return new PayloadResponse(400, ['error' => 'long_url is required']);
        }

        try {
            $expiresAt = self::expiresAt($body['expires_at'] ?? $body['expiresAt'] ?? null);
            $link = $this->shorten->shorten($longUrl, $expiresAt);
        } catch (InvalidLongUrl $exception) {
            return new PayloadResponse(400, ['error' => $exception->getMessage()]);
        }

        return new PayloadResponse(201, [
            'code' => $link->code(),
            'long_url' => $link->longUrl(),
            'short_url' => rtrim($this->publicBaseUrl, '/') . '/' . $link->code(),
            'expires_at' => $link->expiresAt()?->format(DATE_ATOM),
        ]);
    }

    private static function expiresAt(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidLongUrl('expires_at must be an ISO-8601 timestamp');
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new InvalidLongUrl('expires_at must be an ISO-8601 timestamp');
        }
    }

    private static function clientAddress(ServerRequestInterface $request): string
    {
        $server = $request->getServerParams();
        $realIp = $server['HTTP_X_REAL_IP'] ?? '';
        if (is_string($realIp) && $realIp !== '') {
            return $realIp;
        }

        $remote = $server['REMOTE_ADDR'] ?? 'unknown';

        return is_string($remote) && $remote !== '' ? $remote : 'unknown';
    }
}
