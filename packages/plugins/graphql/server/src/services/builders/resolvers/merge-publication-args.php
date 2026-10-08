<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders\Resolvers;

use Strapi\Utils\PublicationFilter;

/**
 * Port of server/src/services/builders/resolvers/merge-publication-args.ts.
 *
 * GraphQL still exposes deprecated `hasPublishedVersion`; normalize to `publicationFilter`
 * document-scoped modes so the document service only applies one code path.
 */
final class MergePublicationArgs
{
    /**
     * @param array<string, mixed> $args
     * @return array{publicationFilter?: string}
     */
    public static function mergePublicationFilterFromGraphQLArgs(array $args): array
    {
        if (($args['publicationFilter'] ?? null) !== null) {
            $mode = PublicationFilter::parsePublicationFilter($args['publicationFilter']);

            return $mode === null ? [] : ['publicationFilter' => $mode];
        }
        if (($args['hasPublishedVersion'] ?? null) !== null) {
            return [
                'publicationFilter' => $args['hasPublishedVersion']
                    ? 'has-published-version-document'
                    : 'never-published-document',
            ];
        }

        return [];
    }
}
