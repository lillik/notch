<?php

declare(strict_types=1);

namespace App\Action;

use App\Domain\Exception\LinkExpired;
use App\Domain\Exception\LinkNotFound;
use App\Domain\Service\RedirectService;
use App\Http\PayloadResponse;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

#[OA\Get(
    path: '/{code}',
    operationId: 'redirect',
    summary: 'Redirect to the long URL',
    parameters: [
        new OA\Parameter(ref: '#/components/parameters/Accept'),
        new OA\Parameter(
            name: 'code',
            in: 'path',
            required: true,
            schema: new OA\Schema(type: 'string', pattern: '^[0-9A-Za-z]{1,16}$'),
        ),
    ],
    responses: [
        new OA\Response(
            response: 302,
            description: 'Browser redirect to the long URL',
            headers: [
                new OA\Header(header: 'Location', required: true, schema: new OA\Schema(type: 'string', format: 'uri')),
                new OA\Header(header: 'Cache-Control', schema: new OA\Schema(type: 'string', example: 'no-store')),
            ],
        ),
        new OA\Response(response: 404, description: 'Unknown code', content: [
            new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            new OA\XmlContent(ref: '#/components/schemas/ErrorResponse'),
        ]),
        new OA\Response(response: 406, description: 'Accept is neither JSON nor XML'),
        new OA\Response(response: 410, description: 'The link has expired', content: [
            new OA\JsonContent(ref: '#/components/schemas/ErrorResponse'),
            new OA\XmlContent(ref: '#/components/schemas/ErrorResponse'),
        ]),
    ],
)]
final class RedirectAction implements RequestHandlerInterface
{
    public function __construct(private readonly RedirectService $redirects)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $code = (string) $request->getAttribute('code');
        if ($code === '' || strlen($code) > 16) {
            return new PayloadResponse(404, ['error' => 'not found']);
        }

        try {
            $link = $this->redirects->redirect($code);
        } catch (LinkNotFound) {
            return new PayloadResponse(404, ['error' => 'not found']);
        } catch (LinkExpired) {
            return new PayloadResponse(410, ['error' => 'link expired']);
        }

        return (new Response(302))
            ->withHeader('Location', $link->longUrl())
            ->withHeader('Cache-Control', 'no-store');
    }
}
