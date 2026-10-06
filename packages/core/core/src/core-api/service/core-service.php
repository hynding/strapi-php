<?php

declare(strict_types=1);

namespace Strapi\Core\CoreApi\Service;

use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;

/** Port of core-api/service/core-service.ts: `getFetchParams` (status defaults to published). */
abstract class CoreService
{
    public function __construct(protected readonly Strapi $strapi, public readonly Schema $contentType)
    {
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function getFetchParams(array $params = []): array
    {
        return ['status' => 'published', ...$params];
    }
}
