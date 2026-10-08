<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders\Resolvers;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\GraphqlContext;
use Strapi\Types\Schema\Schema;

/** Port of server/src/services/builders/resolvers/query.ts */
final class Query
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * Merge sanitized query with resolver args so GraphQL-coerced publication args are not dropped.
     *
     * @param array<string, mixed> $sanitizedQuery
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function mergeDocumentListParams(array $sanitizedQuery, array $args): array
    {
        $status = $sanitizedQuery['status'] ?? null;
        $rest = $sanitizedQuery;
        unset($rest['status']);

        $merged = [
            ...$rest,
            'status' => $status ?? 'published',
        ];

        $publicationFilter = MergePublicationArgs::mergePublicationFilterFromGraphQLArgs($args)['publicationFilter'] ?? null;
        if ($publicationFilter !== null) {
            $merged['publicationFilter'] = $publicationFilter;
        }

        return $merged;
    }

    /** @param array{contentType: Schema} $options */
    public function buildQueriesResolvers(array $options): QueriesResolvers
    {
        return new QueriesResolvers($this->strapi, $options['contentType']);
    }

    /**
     * @internal shared by {@see QueriesResolvers}
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function validateAndSanitize(Strapi $strapi, array $args, Schema $contentType, mixed $ctx): array
    {
        $auth = GraphqlContext::authOf($ctx);

        $strapi->contentAPI()->validate()->query($args, $contentType, ['auth' => $auth]);

        return $strapi->contentAPI()->sanitize()->query($args, $contentType, ['auth' => $auth]);
    }
}
