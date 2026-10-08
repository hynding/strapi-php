<?php

declare(strict_types=1);

namespace Strapi\Openapi;

/**
 * Port of packages/core/openapi/src/types.ts. OpenAPI 3.1 objects are plain arrays (an empty
 * JSON object is a `\stdClass`); a Strapi route is the route array the server registered
 * (`method`, `path`, `handler`, `info`, `config`, `request`, `response`).
 *
 * @phpstan-type Route array<string, mixed>
 * @phpstan-type DocumentContextData array<string, mixed>
 * @phpstan-type OperationContextData array<string, mixed>
 * @phpstan-type PathContextData array<string, mixed>
 * @phpstan-type PathItemContextData array<string, mixed>
 * @phpstan-type GenerationOptions array{type?: 'admin'|'content-api'}
 */
final class Types
{
}
