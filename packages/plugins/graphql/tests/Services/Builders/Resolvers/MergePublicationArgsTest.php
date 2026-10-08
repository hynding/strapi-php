<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Tests\Services\Builders\Resolvers;

use PHPUnit\Framework\TestCase;
use Strapi\Plugin\Graphql\Services\Builders\Resolvers\MergePublicationArgs;

/** Port of server/src/services/builders/resolvers/__tests__/merge-publication-args.test.ts */
final class MergePublicationArgsTest extends TestCase
{
    public function testReturnsPublicationFilterWhenSet(): void
    {
        // GraphQL internal values are kebab-case strings
        self::assertSame(
            ['publicationFilter' => 'never-published'],
            MergePublicationArgs::mergePublicationFilterFromGraphQLArgs(['publicationFilter' => 'never-published']),
        );

        self::assertSame(
            ['publicationFilter' => 'published-with-draft'],
            MergePublicationArgs::mergePublicationFilterFromGraphQLArgs(['publicationFilter' => 'published-with-draft']),
        );
    }

    public function testPrefersPublicationFilterOverDeprecatedHasPublishedVersion(): void
    {
        self::assertSame(
            ['publicationFilter' => 'modified'],
            MergePublicationArgs::mergePublicationFilterFromGraphQLArgs(['publicationFilter' => 'modified', 'hasPublishedVersion' => false]),
        );
    }

    public function testMapsHasPublishedVersionToDocumentScopedPublicationFilterModes(): void
    {
        self::assertSame(
            ['publicationFilter' => 'has-published-version-document'],
            MergePublicationArgs::mergePublicationFilterFromGraphQLArgs(['hasPublishedVersion' => true]),
        );

        self::assertSame(
            ['publicationFilter' => 'never-published-document'],
            MergePublicationArgs::mergePublicationFilterFromGraphQLArgs(['hasPublishedVersion' => false]),
        );
    }

    public function testReturnsEmptyWhenNeitherPublicationArgIsPresent(): void
    {
        self::assertSame([], MergePublicationArgs::mergePublicationFilterFromGraphQLArgs([]));
        self::assertSame([], MergePublicationArgs::mergePublicationFilterFromGraphQLArgs(['status' => 'DRAFT']));
    }
}
