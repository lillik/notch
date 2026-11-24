<?php

declare(strict_types=1);

namespace App\Http;

use Slim\Psr7\Response;

final class Xml
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function write(int $status, array $payload): Response
    {
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('response');
        foreach ($payload as $name => $value) {
            self::element($xml, (string) $name, $value);
        }
        $xml->endElement();
        $xml->endDocument();

        $response = new Response($status);
        $response->getBody()->write($xml->outputMemory());

        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Vary', 'Accept');
    }

    private static function element(\XMLWriter $xml, string $name, mixed $value): void
    {
        $xml->startElement($name);
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                self::element($xml, is_string($key) ? $key : 'item', $item);
            }
        } elseif (is_bool($value)) {
            $xml->text($value ? 'true' : 'false');
        } elseif ($value !== null) {
            $xml->text((string) $value);
        }
        $xml->endElement();
    }
}
