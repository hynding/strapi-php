<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService\Transform\Relations\Utils;

/**
 * Port of transform/relations/utils/types.ts (phpstan types only).
 *
 * @phpstan-type ID string|int
 * @phpstan-type LongHandEntity array{id: ID, position?: array<string, mixed>}
 * @phpstan-type LongHandDocument array{documentId: ID, locale?: string|null, status?: 'draft'|'published', position?: array<string, mixed>}
 * @phpstan-type Relation mixed
 */
final class Types
{
}
