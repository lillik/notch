<?php

declare(strict_types=1);

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Short URL',
    description: 'Anonymous URL shortener. Create returns a code. Redirect sends the browser to the long URL. Response bodies follow the Accept header: application/json or application/xml. The default is JSON.',
)]
#[OA\Server(url: '/', description: 'Current host')]
#[OA\Schema(
    schema: 'CreateLink',
    required: ['long_url'],
    properties: [
        new OA\Property(property: 'long_url', type: 'string', format: 'uri', example: 'https://example.com/docs'),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'CreatedLink',
    required: ['code', 'long_url', 'short_url'],
    properties: [
        new OA\Property(property: 'code', type: 'string', example: '100012V'),
        new OA\Property(property: 'long_url', type: 'string', format: 'uri'),
        new OA\Property(property: 'short_url', type: 'string', format: 'uri'),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ErrorResponse',
    required: ['error'],
    properties: [
        new OA\Property(property: 'error', type: 'string'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'Health',
    required: ['status'],
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['ok', 'down']),
    ],
    type: 'object',
)]
final class Spec
{
}
