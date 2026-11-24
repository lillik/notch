<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ServerRequestInterface;

final class ResponseFormat
{
    /**
     * @param array<string, ResponseWriter> $writers
     */
    public function __construct(
        private readonly ResponseWriter $fallback,
        private readonly array $writers,
    ) {
    }

    public static function create(): self
    {
        $json = new JsonResponseWriter();
        $xml = new XmlResponseWriter();

        return new self($json, [
            'application/json' => $json,
            'text/json' => $json,
            'application/xml' => $xml,
            'text/xml' => $xml,
        ]);
    }

    public function select(ServerRequestInterface $request): ?ResponseWriter
    {
        $header = trim($request->getHeaderLine('Accept'));
        if ($header === '') {
            return $this->fallback;
        }

        foreach ($this->ranked($header) as $type) {
            if ($type === '*/*') {
                return $this->fallback;
            }
            if (isset($this->writers[$type])) {
                return $this->writers[$type];
            }
            if (str_ends_with($type, '+json')) {
                return $this->writers['application/json'];
            }
            if (str_ends_with($type, '+xml')) {
                return $this->writers['application/xml'];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function ranked(string $header): array
    {
        $items = [];
        foreach (explode(',', $header) as $index => $part) {
            $bits = array_map(trim(...), explode(';', $part));
            $type = strtolower($bits[0]);
            if ($type === '') {
                continue;
            }

            $quality = 1.0;
            foreach (array_slice($bits, 1) as $param) {
                if (str_starts_with(strtolower($param), 'q=')) {
                    $quality = (float) substr($param, 2);
                }
            }
            if ($quality <= 0) {
                continue;
            }

            $items[] = ['type' => $type, 'quality' => $quality, 'index' => $index];
        }

        usort(
            $items,
            static fn (array $left, array $right): int => $right['quality'] <=> $left['quality'] ?: $left['index'] <=> $right['index'],
        );

        return array_column($items, 'type');
    }
}
