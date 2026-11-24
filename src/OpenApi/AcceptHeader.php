<?php

declare(strict_types=1);

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Parameter(
    parameter: 'Accept',
    name: 'Accept',
    in: 'header',
    required: false,
    description: 'Response format. Defaults to application/json.',
    schema: new OA\Schema(type: 'string', enum: ['application/json', 'application/xml'], default: 'application/json'),
)]
final class AcceptHeader
{
}
