<?php

declare(strict_types=1);

namespace App\Tests;

use App\Http\JsonResponseWriter;
use App\Http\NegotiateResponseMiddleware;
use App\Http\PayloadResponse;
use App\Http\ResponseFormat;
use App\Http\XmlResponseWriter;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class ResponseFormatTest extends TestCase
{
    public function testMissingAcceptUsesJson(): void
    {
        $writer = ResponseFormat::create()->select($this->request(null));

        self::assertInstanceOf(JsonResponseWriter::class, $writer);
    }

    public function testXmlAcceptSelectsXml(): void
    {
        $writer = ResponseFormat::create()->select($this->request('application/xml'));

        self::assertInstanceOf(XmlResponseWriter::class, $writer);
    }

    public function testQualityPicksThePreferredType(): void
    {
        $writer = ResponseFormat::create()->select($this->request('application/xml;q=0.2, application/json;q=0.8'));

        self::assertInstanceOf(JsonResponseWriter::class, $writer);
    }

    public function testUnsupportedAcceptIsRejected(): void
    {
        self::assertNull(ResponseFormat::create()->select($this->request('text/html')));
    }

    public function testMiddlewareEncodesThePayloadAndLeavesRedirectsAlone(): void
    {
        $middleware = new NegotiateResponseMiddleware(ResponseFormat::create());
        $json = $middleware->process(
            $this->request('application/json'),
            new FixedHandler(new PayloadResponse(200, ['status' => 'ok'])),
        );
        $xml = $middleware->process(
            $this->request('application/xml'),
            new FixedHandler(new PayloadResponse(404, ['error' => 'not found'])),
        );
        $redirect = new Response(302);
        $passed = $middleware->process(
            $this->request('application/xml'),
            new FixedHandler($redirect),
        );

        self::assertSame('application/json', $json->getHeaderLine('Content-Type'));
        self::assertSame('{"status":"ok"}', (string) $json->getBody());
        self::assertStringContainsString('<error>not found</error>', (string) $xml->getBody());
        self::assertSame($redirect, $passed);
    }

    public function testUnsupportedAcceptDoesNotCallTheAction(): void
    {
        $middleware = new NegotiateResponseMiddleware(ResponseFormat::create());
        $handler = new FixedHandler(new PayloadResponse(201, ['code' => 'unused']));

        $response = $middleware->process($this->request('text/plain'), $handler);

        self::assertSame(406, $response->getStatusCode());
        self::assertSame(0, $handler->calls);
    }

    private function request(?string $accept): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/health');
        if ($accept === null) {
            return $request;
        }

        return $request->withHeader('Accept', $accept);
    }
}

final class FixedHandler implements RequestHandlerInterface
{
    public int $calls = 0;

    public function __construct(private readonly ResponseInterface $response)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->calls++;

        return $this->response;
    }
}
