<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders\Resolvers;

use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;

/**
 * The object `buildQueriesResolvers({ contentType })` returns in query.ts (PHP-port addition:
 * upstream's object literal of `findMany`, `findFirst`, `findOne`).
 */
final class QueriesResolvers
{
    private readonly string $uid;

    public function __construct(private readonly Strapi $strapi, private readonly Schema $contentType)
    {
        $this->uid = $contentType->uid;
    }

    /** @param array<string, mixed> $args */
    public function findMany(mixed $parent, array $args, mixed $ctx): mixed
    {
        $sanitizedQuery = Query::validateAndSanitize($this->strapi, $args, $this->contentType, $ctx);

        return $this->strapi->documents($this->uid)->findMany(Query::mergeDocumentListParams($sanitizedQuery, $args));
    }

    /** @param array<string, mixed> $args */
    public function findFirst(mixed $parent, array $args, mixed $ctx): mixed
    {
        $sanitizedQuery = Query::validateAndSanitize($this->strapi, $args, $this->contentType, $ctx);

        return $this->strapi->documents($this->uid)->findFirst(Query::mergeDocumentListParams($sanitizedQuery, $args));
    }

    /** @param array<string, mixed> $args */
    public function findOne(mixed $parent, array $args, mixed $ctx): mixed
    {
        $documentId = $args['documentId'] ?? null;

        $sanitizedQuery = Query::validateAndSanitize($this->strapi, $args, $this->contentType, $ctx);
        unset($sanitizedQuery['id'], $sanitizedQuery['documentId']);

        $merged = Query::mergeDocumentListParams($sanitizedQuery, $args);

        return $this->strapi->documents($this->uid)->findOne([...$merged, 'documentId' => $documentId]);
    }
}
